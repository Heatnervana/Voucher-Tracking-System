<?php

namespace App\Http\Controllers;

use App\ActivityRecorder;
use App\Jobs\ReadSavedVoucherAmount;
use App\Jobs\ReadVoucherAmount;
use App\Models\Voucher;
use App\Models\VoucherYear;
use App\VoucherFilename;
use App\VoucherRecycleBin;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VoucherController extends Controller
{
    private const MAX_FOLDER_BYTES = 5_000_000_000;

    private const MAX_CHUNK_BYTES = 900 * 1024 * 1024;

    private const MAX_FILES_PER_CHUNK = 20;

    public function index(Request $request): View
    {
        $rules = [
            'q' => ['nullable', 'string', 'max:100'],
            'year' => ['nullable', 'integer', 'exists:years,year'],
            'type' => ['nullable', Rule::in(['ADA', 'CHECK'])],
            'month' => ['nullable', 'integer', 'between:1,12'],
        ];
        if ($request->user()->can('manage-vouchers')) {
            $rules['amount_status'] = ['nullable', Rule::in(['missing'])];
        }

        $filters = $request->validate($rules);
        $vouchers = Voucher::with('uploader')->search($filters['q'] ?? null)
            ->when($filters['year'] ?? null, fn ($query, $year) => $query->where('year', $year))
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filters['month'] ?? null, fn ($query, $month) => $query->where('month', $month))
            ->when(($filters['amount_status'] ?? null) === 'missing', fn ($query) => $query->needsAmount())
            ->orderByRaw("LENGTH(LTRIM(checkada_no, '0'))")
            ->orderByRaw("LTRIM(checkada_no, '0') COLLATE NOCASE")
            ->orderBy('checkada_no')->orderBy('id')->paginate(20)->withQueryString();

        $folderCount = isset($filters['year'], $filters['type'])
            ? Voucher::where('year', $filters['year'])->where('type', $filters['type'])->count()
            : 0;
        $monthCount = isset($filters['year'], $filters['type'], $filters['month'])
            ? Voucher::where('year', $filters['year'])->where('type', $filters['type'])->where('month', $filters['month'])->count()
            : 0;

        return view('vouchers.index', [
            'vouchers' => $vouchers,
            'years' => VoucherYear::orderByDesc('year')->pluck('year'),
            'filters' => $filters,
            'folderCount' => $folderCount,
            'monthCount' => $monthCount,
            'missingAmountCount' => Voucher::needsAmount()->count(),
        ]);
    }

    public function savedAmountStatus(Request $request): JsonResponse
    {
        $request->session()->reflash();
        $data = $request->validate(['ids' => ['sometimes', 'array', 'max:20'], 'ids.*' => ['integer', 'min:1']]);
        $vouchers = Voucher::whereIn('id', $data['ids'] ?? [])->get(['id', 'amount', 'amount_status']);

        return response()->json([
            'vouchers' => $vouchers->map(fn (Voucher $voucher): array => [
                'id' => $voucher->id, 'amount' => $voucher->amount,
                'status' => $voucher->amount !== null ? 'ready' : $voucher->amount_status,
            ]),
            'manual_count' => $request->user()->can('manage-vouchers') ? Voucher::needsAmount()->count() : null,
            'pending_count' => Voucher::whereNull('amount')->where('amount_status', 'pending')->count(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function create(): View
    {
        return view('vouchers.upload', ['years' => VoucherYear::orderByDesc('year')->pluck('year')]);
    }

    public function startUpload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'exists:years,year'],
            'type' => ['required', Rule::in(['ADA', 'CHECK'])],
            'month' => ['required', 'integer', 'between:1,12'],
            'expected_files' => ['sometimes', 'integer', 'min:1', 'max:20000'],
            'folder_action' => ['sometimes', Rule::in(['keep', 'replace'])],
        ]);

        $existing = Voucher::where('year', $data['year'])->where('type', $data['type'])->where('month', $data['month'])->get();
        if ($existing->isNotEmpty() && ! isset($data['folder_action'])) {
            return response()->json(['code' => 'existing_folder', 'existing_files' => $existing->count(),
                'message' => Carbon::create()->month((int) $data['month'])->format('F').' '.$data['year'].' / '.$data['type'].' already contains '.$existing->count().' PDFs. Replace PDFs whose Check / ADA and DV numbers both match, or keep saved PDFs and add new ones?'], 409);
        }

        $this->clearStagedUpload();
        $batchId = (string) Str::uuid();

        session(['voucher_upload' => [
            'year' => (int) $data['year'],
            'type' => $data['type'],
            'month' => (int) $data['month'],
            'files' => [],
            'batch_id' => $batchId,
            'total_size' => 0,
            'received_size' => 0,
            'expected_files' => $data['expected_files'] ?? null,
            'received_files' => 0,
            'chunks' => [],
            'errors' => [],
            'folder_action' => $data['folder_action'] ?? 'keep',
            'replacement_targets' => ($data['folder_action'] ?? 'keep') === 'replace'
                ? $existing->mapWithKeys(fn (Voucher $voucher): array => [$voucher->id => $this->voucherFingerprint($voucher)])->all() : [],
        ]]);

        return response()->json(['batch_id' => $batchId]);
    }

    public function uploadChunk(Request $request, VoucherFilename $filename): JsonResponse
    {
        $data = $request->validate([
            'batch_id' => ['required', 'uuid'],
            'chunk_id' => ['required', 'uuid'],
            'files' => ['required', 'array', 'min:1', 'max:'.self::MAX_FILES_PER_CHUNK],
            'file_labels' => ['sometimes', 'array', 'max:'.self::MAX_FILES_PER_CHUNK],
            'file_labels.*' => ['string', 'max:1024'],
            'expected_files' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_FILES_PER_CHUNK],
        ]);
        $upload = session('voucher_upload');

        abort_unless(is_array($upload) && hash_equals($upload['batch_id'] ?? '', $data['batch_id']), 409);
        abort_if($upload['completed'] ?? false, 409);

        if (isset($upload['chunks'][$data['chunk_id']])) {
            return response()->json($upload['chunks'][$data['chunk_id']]);
        }

        $files = $request->file('files', []);
        $expected = $data['expected_files'] ?? (isset($data['file_labels']) ? count($data['file_labels']) : count($files));
        if (count($files) !== (int) $expected) {
            Log::warning('Voucher upload chunk incomplete', ['batch_id' => $data['batch_id'], 'expected' => $expected, 'received' => count($files), 'filenames' => $data['file_labels'] ?? []]);

            return response()->json(['code' => 'incomplete_chunk', 'message' => 'This part expected '.$expected.' PDFs but received '.count($files).'. No files from this part were accepted. Retry the upload.'], 422);
        }
        $chunkSize = array_sum(array_map(fn (UploadedFile $file): int => max(0, (int) $file->getSize()), $files));

        if ($chunkSize > self::MAX_CHUNK_BYTES || $upload['received_size'] + $chunkSize > self::MAX_FOLDER_BYTES) {
            return response()->json(['message' => 'This batch would exceed the upload size limit.'], 413);
        }

        $upload['received_size'] += $chunkSize;
        $upload['received_files'] = ($upload['received_files'] ?? 0) + count($files);
        $errors = [];
        $uploaded = 0;

        $labels = $request->input('file_labels', []);

        foreach ($files as $index => $file) {
            $label = isset($labels[$index]) ? str_replace('\\', '/', $labels[$index]) : null;
            $result = $this->stageUploadedFile($file, $filename, $label);

            if ($result['error'] !== null) {
                $errors[] = $result['error'];

                continue;
            }

            $upload['files'][$result['id']] = $result['file'];
            $upload['total_size'] += $result['size'];
            $uploaded++;
        }

        $result = ['uploaded' => $uploaded, 'errors' => $errors, 'files_total' => count($upload['files'])];
        $upload['chunks'][$data['chunk_id']] = $result;
        $upload['errors'] = array_merge($upload['errors'], $errors);
        session(['voucher_upload' => $upload]);

        return response()->json($result);
    }

    public function completeUpload(Request $request): JsonResponse
    {
        $data = $request->validate(['batch_id' => ['required', 'uuid']]);
        $upload = session('voucher_upload');

        abort_unless(is_array($upload) && hash_equals($upload['batch_id'] ?? '', $data['batch_id']), 409);

        if ($upload['completed'] ?? false) {
            return response()->json(['redirect' => route('vouchers.confirm')]);
        }

        if (empty($upload['files'])) {
            return response()->json(['message' => implode(' ', $upload['errors'] ?? []) ?: 'No PDF files were uploaded. Please choose a folder with PDF vouchers.'], 422);
        }

        if (isset($upload['expected_files']) && $upload['received_files'] !== (int) $upload['expected_files']) {
            Log::warning('Voucher upload count mismatch', ['batch_id' => $upload['batch_id'], 'expected' => $upload['expected_files'], 'received' => $upload['received_files']]);

            return response()->json(['code' => 'incomplete_upload', 'message' => 'Expected '.$upload['expected_files'].' PDFs but received '.$upload['received_files'].'. Your staged files are preserved. Retry the missing part or select the PDFs again.',
                'received_files' => $upload['received_files'], 'expected_files' => (int) $upload['expected_files'],
                'received_chunk_ids' => array_keys($upload['chunks'] ?? [])], 409);
        }

        session()->flash('upload_errors', $upload['errors']);
        session(['voucher_upload' => [
            'year' => $upload['year'],
            'type' => $upload['type'],
            'month' => $upload['month'],
            'files' => $upload['files'],
            'batch_id' => $upload['batch_id'],
            'completed' => true,
            'expected_files' => $upload['expected_files'] ?? $upload['received_files'] ?? count($upload['files']),
            'received_files' => $upload['received_files'] ?? count($upload['files']),
            'errors' => $upload['errors'] ?? [],
            'folder_action' => $upload['folder_action'] ?? 'keep',
            'replacement_targets' => $upload['replacement_targets'] ?? [],
        ]]);

        return response()->json(['redirect' => route('vouchers.confirm')]);
    }

    public function removeStagedFile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'batch_id' => ['required', 'uuid'],
            'file_id' => ['required', 'uuid'],
        ]);
        $upload = session('voucher_upload');

        abort_unless(is_array($upload) && hash_equals($upload['batch_id'] ?? '', $data['batch_id']), 409);

        if (! isset($upload['files'][$data['file_id']])) {
            return response()->json(['message' => 'This PDF is no longer in the upload.'], 404);
        }

        $disk = Storage::disk('local');
        $path = $upload['files'][$data['file_id']]['path'];

        if ($disk->exists($path) && ! $disk->delete($path)) {
            return response()->json(['message' => 'Could not remove this staged PDF. Please try again.'], 500);
        }

        unset($upload['files'][$data['file_id']]);
        Cache::forget('voucher-amount:'.$data['file_id']);
        session(['voucher_upload' => $upload]);

        return response()->json([
            'remaining' => count($upload['files']),
            'redirect' => $upload['files'] === [] ? route('vouchers.create') : null,
        ]);
    }

    public function store(Request $request, VoucherFilename $filename): RedirectResponse
    {
        $data = $request->validate(['year' => ['required', 'integer', 'exists:years,year'], 'type' => ['required', Rule::in(['ADA', 'CHECK'])], 'month' => ['required', 'integer', 'between:1,12'], 'files' => ['required', 'array', 'min:1', 'max:30']]);
        $this->clearStagedUpload();
        $staged = [];
        $errors = [];

        foreach ($request->file('files', []) as $file) {
            $result = $this->stageUploadedFile($file, $filename);

            if ($result['error'] !== null) {
                $errors[] = $result['error'];

                continue;
            }

            $staged[$result['id']] = $result['file'];
        }

        if ($staged === []) {
            return back()->withInput()->with('upload_errors', $errors ?: ['No PDF files were received.']);
        }

        session(['voucher_upload' => ['year' => (int) $data['year'], 'type' => $data['type'], 'month' => (int) $data['month'], 'files' => $staged, 'batch_id' => (string) Str::uuid()]]);

        return redirect()->route('vouchers.confirm')->with('upload_errors', $errors);
    }

    public function confirm(): View|RedirectResponse
    {
        $upload = session('voucher_upload');

        return is_array($upload) && ! empty($upload['files'])
            ? view('vouchers.confirm', ['upload' => $this->withDetectedAmounts($upload)])
            : redirect()->route('vouchers.create');
    }

    public function preview(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'batch_id' => ['required', 'uuid'],
            'file_id' => ['required', 'uuid'],
        ]);
        $upload = session('voucher_upload');

        abort_unless(is_array($upload) && hash_equals($upload['batch_id'] ?? '', $data['batch_id']), 409);
        $file = $upload['files'][$data['file_id']] ?? null;
        abort_unless(is_array($file), 404);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($file['path']), 404);

        return $disk->response($file['path'], basename($file['path']), [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function amounts(Request $request): JsonResponse
    {
        $request->session()->reflash();
        $data = $request->validate(['batch_id' => ['required', 'uuid']]);
        $upload = session('voucher_upload');
        abort_unless(is_array($upload) && hash_equals($upload['batch_id'] ?? '', $data['batch_id']), 409);

        $files = [];
        foreach ($this->withDetectedAmounts($upload)['files'] as $id => $file) {
            $files[$id] = ['status' => $file['amount_status'], 'amount' => $file['detected_amount']];
        }

        return response()->json(['files' => $files])->header('Cache-Control', 'no-store');
    }

    public function cancelUpload(Request $request): RedirectResponse
    {
        $upload = session('voucher_upload');
        if (is_array($upload)) {
            $this->assertUploadBatch($request, $upload);
        }
        $this->clearStagedUpload();

        return redirect()->route('vouchers.index');
    }

    public function finalize(Request $request): RedirectResponse
    {
        $upload = session('voucher_upload');

        if (! is_array($upload) || empty($upload['files'])) {
            return redirect()->route('vouchers.create');
        }

        $this->assertUploadBatch($request, $upload);
        $data = $request->validate(['voucher_details' => ['sometimes', 'string', 'json']]);
        if (isset($data['voucher_details'])) {
            $details = Validator::make(['vouchers' => json_decode($data['voucher_details'], true)], ['vouchers' => ['required', 'array']])->validate();
            $request->replace(array_merge($request->except('voucher_details'), $details));
        }
        $request->validate(['vouchers' => ['sometimes', 'array'], 'vouchers.*' => ['array']]);

        $saved = 0;
        $errors = [];
        $disk = Storage::disk('local');
        $remaining = $upload['files'];
        $seenPairs = [];

        foreach ($upload['files'] as $id => $file) {
            $details = $request->input("vouchers.$id", []);
            if (is_string($details['amount'] ?? null) && trim($details['amount']) === '') {
                $details['amount'] = null;
            }
            $validation = Validator::make($details, [
                'checkada_no' => ['required', 'regex:/^[A-Za-z0-9-]{1,40}$/'],
                'dv_number' => ['required', 'regex:/^[0-9]{6,12}$/'],
                'payee' => ['required', 'string', 'max:255'],
                'amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999999.99', 'decimal:0,2'],
            ]);

            if ($validation->fails()) {
                $reason = implode(' ', $validation->errors()->all());
                $errors[] = $file['original_name'].': '.$reason;
                Log::warning('Voucher details rejected', ['filename' => $file['original_name'], 'reason' => $reason, 'batch_id' => $upload['batch_id']]);

                continue;
            }

            $data = $validation->validated();
            $data['checkada_no'] = Str::upper($data['checkada_no']);
            $pairKey = $data['checkada_no'].'|'.$data['dv_number'];
            if (isset($seenPairs[$pairKey])) {
                $errors[] = $file['original_name'].': Check / ADA number '.$data['checkada_no'].' and DV number '.$data['dv_number'].' are repeated together in this upload: '.$seenPairs[$pairKey].'.';

                continue;
            }

            $reading = Cache::get('voucher-amount:'.$id);
            $data['amount_status'] = ($data['amount'] ?? null) !== null ? 'ready' : 'manual';
            if (($data['amount'] ?? null) === null && ($reading['status'] ?? null) === 'pending') {
                $data['amount_status'] = 'pending';
            } elseif (($data['amount'] ?? null) === null && ($reading['status'] ?? null) === 'ready') {
                $detected = Validator::make(['amount' => $reading['amount'] ?? null], [
                    'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99', 'decimal:0,2'],
                ]);
                if ($detected->passes()) {
                    $data['amount'] = $reading['amount'];
                    $data['amount_status'] = 'ready';
                }
            }

            $replacement = null;
            $candidate = Voucher::withTrashed()->withVoucherNumbers($data['checkada_no'], $data['dv_number'])->first();
            $sameFolder = $candidate !== null && $candidate->year === (int) $upload['year'] && $candidate->type === $upload['type'] && $candidate->month === (int) $upload['month'];
            if ($candidate !== null && ($candidate->trashed() || ! $sameFolder || ($upload['folder_action'] ?? 'keep') !== 'replace')) {
                $errors[] = $file['original_name'].': '.$this->duplicateVoucherMessage($candidate).' No saved PDF was replaced.';

                continue;
            }
            if (($upload['folder_action'] ?? 'keep') === 'replace') {
                if ($candidate !== null) {
                    if (($upload['replacement_targets'][$candidate->id] ?? null) !== $this->voucherFingerprint($candidate)) {
                        $errors[] = $file['original_name'].': this saved voucher changed after the replacement choice. Start a new upload to review it again.';

                        continue;
                    }
                    $replacement = $candidate;
                }
            }

            $payeeForName = trim(preg_replace('/[^A-Za-z0-9 ._-]/u', '', $data['payee']) ?? '');
            $destination = sprintf('vouchers/%d/%s/%02d/%s_%s_%s.pdf', $upload['year'], $upload['type'], $upload['month'], $data['checkada_no'], $data['dv_number'], Str::upper($payeeForName ?: 'PAYEE'));

            try {
                $attributes = [
                    'checkada_no' => $data['checkada_no'],
                    'dv_number' => $data['dv_number'],
                    'payee' => $data['payee'],
                    'amount' => $data['amount'] ?? null,
                    'amount_status' => $data['amount_status'],
                    'amount_read_token' => (string) Str::uuid(),
                    'amount_retry_count' => 0,
                    'year' => $upload['year'],
                    'type' => $upload['type'],
                    'month' => $upload['month'],
                    'file_path' => $destination,
                    'uploaded_by' => auth()->id(),
                ];
                $voucher = retry(
                    [50, 150],
                    fn (): Voucher => $this->saveStagedVoucher($disk, $file['path'], $destination, $attributes, $replacement),
                    when: $this->isVoucherDatabaseBusy(...),
                );

                $saved++;
                $seenPairs[$pairKey] = $file['original_name'];
                Cache::forget('voucher-amount:'.$id);
                unset($remaining[$id]);
                if ($voucher->amount_status === 'pending') {
                    try {
                        Bus::dispatch(new ReadSavedVoucherAmount($voucher->id, $voucher->amount_read_token));
                    } catch (\Throwable $exception) {
                        Voucher::whereKey($voucher->id)->whereNull('amount')->where('amount_status', 'pending')
                            ->update(['amount_status' => 'manual']);
                        report($exception);
                    }
                }
            } catch (QueryException $exception) {
                Log::warning('Voucher save rejected', ['filename' => $file['original_name'], 'reason' => $exception->getMessage(), 'batch_id' => $upload['batch_id']]);
                $conflict = $exception instanceof UniqueConstraintViolationException
                    ? Voucher::withTrashed()->withVoucherNumbers($data['checkada_no'], $data['dv_number'])->first()
                    : null;
                $message = match (true) {
                    $this->isVoucherDatabaseBusy($exception) => 'The database is busy. This PDF is still in review. Click Save vouchers again to retry.',
                    $conflict !== null && ! $conflict->is($replacement) => $this->duplicateVoucherMessage($conflict),
                    $exception instanceof UniqueConstraintViolationException => 'A saved record already uses this filename. This PDF is still in review.',
                    default => 'The database could not save this voucher. This PDF is still in review. The error was logged.',
                };
                $errors[] = $file['original_name'].': '.$message;
            } catch (\Throwable $exception) {
                Log::error('Voucher save failed', ['filename' => $file['original_name'], 'reason' => $exception->getMessage(), 'batch_id' => $upload['batch_id']]);
                $errors[] = $file['original_name'].': could not save this voucher. The error was logged.';
            }
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                Log::warning('Voucher not saved', ['reason' => $error, 'batch_id' => $upload['batch_id'],
                    'year' => $upload['year'], 'type' => $upload['type'], 'month' => $upload['month']]);
            }
            session(['voucher_upload' => array_replace($upload, ['files' => $remaining])]);

            return redirect()->route('vouchers.confirm')->withInput()->with('upload_errors', $errors)->with('saved_count', $saved);
        }

        session()->forget('voucher_upload');

        return redirect()->route('vouchers.index')->with('status', $saved.' voucher(s) saved.');
    }

    public function show(Voucher $voucher): View
    {
        return view('vouchers.show', ['voucher' => $voucher->load('uploader'), 'years' => VoucherYear::orderByDesc('year')->pluck('year')]);
    }

    public function pdf(Voucher $voucher)
    {
        abort_unless(Storage::disk('local')->exists($voucher->file_path), 404);

        return Storage::disk('local')->response($voucher->file_path, basename($voucher->file_path), ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline']);
    }

    public function download(Voucher $voucher)
    {
        abort_unless(Storage::disk('local')->exists($voucher->file_path), 404);

        return Storage::disk('local')->download($voucher->file_path, basename($voucher->file_path), ['Content-Type' => 'application/pdf']);
    }

    public function updateAmount(Request $request, Voucher $voucher): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99', 'decimal:0,2']]);
        $voucher->refresh();
        DB::transaction(fn () => $voucher->update(['amount' => $data['amount'], 'amount_status' => 'ready']));

        return redirect()->route('vouchers.show', $voucher)->with('status', 'Amount saved.');
    }

    public function rename(Request $request, Voucher $voucher, VoucherFilename $parser): JsonResponse|RedirectResponse
    {
        $voucher->refresh();
        $filename = $request->input('filename');
        if (is_string($filename) && $filename !== '' && pathinfo($filename, PATHINFO_EXTENSION) === '') {
            $request->merge(['filename' => $filename.'.pdf']);
        }
        $data = $request->validate([
            'filename' => [
                'required', 'string', 'max:150',
                'regex:~\A[^<>:"/\\\\|?*\x00-\x1F]+\.pdf\z~iu',
                'not_regex:~\A(?:CON|PRN|AUX|NUL|COM[1-9¹²³]|LPT[1-9¹²³])(?:\.|$)~iu',
            ],
        ], [
            'filename.regex' => 'Enter a filename ending in .pdf without slashes or these characters: < > : " | ? *',
            'filename.not_regex' => 'That filename is reserved by Windows. Please choose another name.',
            'filename.max' => 'Keep the full filename within 150 characters, including .pdf.',
        ]);

        $oldPath = $voucher->file_path;
        $target = dirname($oldPath).'/'.$data['filename'];
        $details = $parser->parse($data['filename']);
        if ($details !== null) {
            $details['checkada_no'] = Str::upper($details['checkada_no']);
            $conflict = Voucher::withTrashed()->withVoucherNumbers($details['checkada_no'], $details['dv_number'])->whereKeyNot($voucher->id)->first();
            if ($conflict !== null) {
                $message = $this->duplicateVoucherMessage($conflict);
                if ($request->expectsJson()) {
                    return response()->json(['message' => $message, 'errors' => ['filename' => [$message]]], 422);
                }

                return back()->withInput()->withErrors(['filename' => $message]);
            }
        }
        if ($target === $oldPath && ($details === null || $voucher->only(array_keys($details)) === $details)) {
            if ($request->expectsJson()) {
                return response()->json(['filename' => basename($oldPath), 'message' => 'Filename unchanged.',
                    'voucher' => $voucher->only(['dv_number', 'checkada_no', 'payee'])]);
            }

            return redirect()->route('vouchers.show', $voucher)->with('status', 'Filename unchanged.');
        }

        $disk = Storage::disk('local');
        $currentPath = $oldPath;
        $error = 'The PDF could not be renamed. Please try again.';

        try {
            DB::transaction(function () use ($disk, $oldPath, $target, $voucher, $details, &$currentPath, &$error): void {
                if ($target !== $oldPath) {
                    // An intermediate name also supports changes to letter case on Windows.
                    $temporaryPath = dirname($oldPath).'/.rename-'.Str::uuid().'.pdf';
                    if (! $disk->exists($oldPath) || ! $disk->move($oldPath, $temporaryPath)) {
                        throw new \RuntimeException('Could not prepare the PDF for renaming.');
                    }
                    $currentPath = $temporaryPath;

                    if ($disk->exists($target) || Voucher::where('file_path', $target)->whereKeyNot($voucher->id)->exists()) {
                        $error = 'A PDF with that filename already exists in this folder. Choose another name.';
                        throw new \RuntimeException($error);
                    }
                    if (! $disk->move($temporaryPath, $target)) {
                        throw new \RuntimeException('Could not rename the PDF.');
                    }
                    $currentPath = $target;
                }
                if (! $voucher->update(['file_path' => $target] + ($details ?? []))) {
                    throw new \RuntimeException('Could not save the filename.');
                }
            });
        } catch (\Throwable $exception) {
            if ($currentPath !== $oldPath && ! $disk->move($currentPath, $oldPath)) {
                report($exception);
                $error = 'The rename could not be completed or restored. Please contact support before trying again.';
            }

            if ($request->expectsJson()) {
                return response()->json(['message' => $error, 'errors' => ['filename' => [$error]]], 422);
            }

            return back()->withInput()->withErrors(['filename' => $error]);
        }

        if ($request->expectsJson()) {
            return response()->json(['filename' => basename($target), 'message' => 'Saved PDF filename updated.',
                'voucher' => $voucher->only(['dv_number', 'checkada_no', 'payee'])]);
        }

        return redirect()->route('vouchers.show', $voucher)->with('status', 'Saved PDF filename updated.');
    }

    public function move(Request $request, Voucher $voucher): RedirectResponse
    {
        $voucher->refresh();
        $data = $request->validate(['year' => ['required', 'integer', 'exists:years,year'], 'type' => ['required', Rule::in(['ADA', 'CHECK'])], 'month' => ['required', 'integer', 'between:1,12']]);

        $conflict = Voucher::withVoucherNumbers($voucher->checkada_no, $voucher->dv_number)->whereKeyNot($voucher->id)->first();
        if ($conflict !== null) {
            return back()->withErrors(['year' => $this->duplicateVoucherMessage($conflict)]);
        }

        $oldPath = $voucher->file_path;
        $target = sprintf('vouchers/%d/%s/%02d/%s', $data['year'], $data['type'], $data['month'], basename($oldPath));
        $disk = Storage::disk('local');

        if ($target === $oldPath) {
            return redirect()->route('vouchers.show', $voucher);
        }

        try {
            DB::transaction(function () use ($disk, $oldPath, $target, $voucher, $data): void {
                $disk->makeDirectory(dirname($target));

                if (! $disk->exists($oldPath) || $disk->exists($target) || ! $disk->move($oldPath, $target)) {
                    throw new \RuntimeException('The PDF could not be moved.');
                }

                try {
                    $voucher->update(['year' => $data['year'], 'type' => $data['type'], 'month' => $data['month'], 'file_path' => $target]);
                } catch (\Throwable $exception) {
                    $disk->move($target, $oldPath);
                    throw $exception;
                }
            });
        } catch (\Throwable) {
            return back()->withErrors(['move' => 'The voucher could not be moved.']);
        }

        return redirect()->route('vouchers.show', $voucher)->with('status', 'Voucher moved.');
    }

    public function destroy(Voucher $voucher): RedirectResponse
    {
        try {
            app(VoucherRecycleBin::class)->trash(new Collection([$voucher]), auth()->id());
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors(['delete' => 'The voucher could not be moved to the recycle bin. Please try again.']);
        }

        return redirect()->route('vouchers.index')->with('status', 'Voucher and saved PDF moved to the recycle bin.');
    }

    public function destroySelected(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'voucher_ids' => ['required', 'array', 'min:1', 'max:20'],
            'voucher_ids.*' => ['required', 'integer', 'distinct', Rule::exists('vouchers', 'id')->whereNull('deleted_at')],
        ]);

        return $this->deleteVoucherScope(
            Voucher::whereIn('id', $data['voucher_ids']),
            'your selection',
            [],
        );
    }

    public function destroyMonth(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'exists:years,year'],
            'type' => ['required', Rule::in(['ADA', 'CHECK'])],
            'month' => ['required', 'integer', 'between:1,12'],
        ]);
        $query = Voucher::where('year', $data['year'])->where('type', $data['type'])->where('month', $data['month']);
        $monthName = Carbon::create()->month((int) $data['month'])->format('F');

        return $this->deleteVoucherScope(
            $query,
            "{$data['year']} / {$data['type']} / {$monthName}",
            ['year' => $data['year'], 'type' => $data['type'], 'month' => $data['month']],
        );
    }

    public function destroyFolder(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'exists:years,year'],
            'type' => ['required', Rule::in(['ADA', 'CHECK'])],
        ]);
        $query = Voucher::where('year', $data['year'])->where('type', $data['type']);

        return $this->deleteVoucherScope(
            $query,
            "{$data['year']} / {$data['type']} (all months)",
            ['year' => $data['year'], 'type' => $data['type']],
        );
    }

    private function deleteVoucherScope($query, string $scope, array $filters): RedirectResponse
    {
        $vouchers = $query->get();

        if ($vouchers->isEmpty()) {
            return back()->withErrors(['delete' => "There are no saved vouchers in {$scope}."]);
        }

        try {
            app(VoucherRecycleBin::class)->trash($vouchers, auth()->id());
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors(['delete' => "Could not move all vouchers in {$scope} to the recycle bin. No records were removed; please try again."]);
        }

        return redirect()->route('vouchers.index', $filters)->with('status', $vouchers->count()." voucher(s) and PDF(s) moved to the recycle bin from {$scope}.");
    }

    private function voucherFingerprint(Voucher $voucher): string
    {
        return hash('sha256', json_encode($voucher->getAttributes(), JSON_THROW_ON_ERROR));
    }

    private function duplicateVoucherMessage(Voucher $voucher): string
    {
        return 'Check / ADA number '.$voucher->checkada_no.' and DV number '.$voucher->dv_number.' already exist together in '.Carbon::create()->month($voucher->month)->format('F').' '.$voucher->year.' / '.$voucher->type.'. '.($voucher->trashed() ? 'The PDF is in the recycle bin. Restore it or permanently delete it before reusing these numbers. ' : '').'Saved PDF: '.$voucher->file_path.'.';
    }

    private function isVoucherDatabaseBusy(\Throwable $exception): bool
    {
        return $exception instanceof QueryException
            && DB::connection($exception->getConnectionName())->getDriverName() === 'sqlite'
            && in_array((int) ($exception->errorInfo[1] ?? 0) & 0xFF, [5, 6], true);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function saveStagedVoucher(FilesystemAdapter $disk, string $staged, string $destination, array $attributes, ?Voucher $existing): Voucher
    {
        if (! $disk->exists($staged) || ($disk->exists($destination) && $existing?->file_path !== $destination)) {
            throw new \RuntimeException('The staged PDF is missing or the destination filename is already used.');
        }
        $originalPath = $existing?->file_path;
        $quarantine = 'replacements/'.Str::uuid().'.pdf';
        $oldMoved = false;
        $newMoved = false;
        try {
            if ($originalPath !== null) {
                if (! $disk->exists($originalPath) || ! $disk->move($originalPath, $quarantine)) {
                    throw new \RuntimeException('Could not preserve the existing PDF before replacement.');
                }
                $oldMoved = true;
            }
            $voucher = DB::transaction(function () use ($disk, $staged, $destination, $attributes, $existing, &$newMoved): Voucher {
                $disk->makeDirectory(dirname($destination));
                if (! $disk->move($staged, $destination)) {
                    throw new \RuntimeException('Could not move PDF into voucher storage.');
                }
                $newMoved = true;
                if ($existing !== null) {
                    $recorder = app(ActivityRecorder::class);
                    $before = $recorder->snapshot($existing->fresh());
                    Voucher::whereKey($existing->id)->update($attributes);
                    $existing->refresh();
                    $recorder->record($existing, 'voucher.replaced', $before, $recorder->snapshot($existing));

                    return $existing;
                }

                return Voucher::create($attributes);
            });
        } catch (\Throwable $exception) {
            $stagedRestored = ! $newMoved || $disk->move($destination, $staged);
            $originalRestored = ! $oldMoved || (($stagedRestored || $destination !== $originalPath) && $disk->move($quarantine, $originalPath));
            if (! $stagedRestored) {
                Log::critical('Could not restore staged PDF after failed save', ['staged' => $staged, 'destination' => $destination]);
            }
            if (! $originalRestored) {
                Log::critical('Could not restore original PDF after failed replacement', ['original' => $originalPath, 'recovery' => $quarantine]);
            }
            if (! $stagedRestored || ! $originalRestored) {
                throw new \RuntimeException('Could not recover the PDF files after a failed save.', previous: $exception);
            }
            throw $exception;
        }
        if ($oldMoved && ! $disk->delete($quarantine)) {
            Log::warning('Replacement saved; old PDF remains in recovery storage', ['recovery' => $quarantine]);
        }

        return $voucher;
    }

    /**
     * @param  array<string, mixed>  $upload
     */
    private function assertUploadBatch(Request $request, array $upload): void
    {
        $data = $request->validate(['batch_id' => ['required', 'uuid']]);
        abort_unless(hash_equals($upload['batch_id'] ?? '', $data['batch_id']), 409, 'This review belongs to an earlier upload. Open the current upload before continuing.');
    }

    private function clearStagedUpload(): void
    {
        foreach (session('voucher_upload.files', []) as $id => $file) {
            Cache::forget('voucher-amount:'.$id);
            if (isset($file['path'])) {
                Storage::disk('local')->delete($file['path']);
            }
        }

        session()->forget('voucher_upload');
    }

    /**
     * @return array{id: string|null, file: array<string, mixed>|null, size: int, error: string|null}
     */
    private function stageUploadedFile(UploadedFile $file, VoucherFilename $filename, ?string $displayName = null): array
    {
        $originalName = $file->getClientOriginalName();
        $displayName ??= $originalName;
        if (! $file->isValid()) {
            return $this->uploadFailure($displayName, 'Transfer failed: '.$file->getErrorMessage());
        }
        $size = max(0, (int) $file->getSize());
        $validation = Validator::make(['file' => $file], ['file' => ['required', 'file', 'mimes:pdf', 'extensions:pdf', 'max:512000']]);

        if ($validation->fails()) {
            $reason = $size > 512000 * 1024 ? 'Upload a PDF no larger than 500 MB.' : 'The file content or extension is not recognized as a PDF. '.implode(' ', $validation->errors()->all());

            return $this->uploadFailure($displayName, $reason, $size);
        }

        $disk = Storage::disk('local');
        $disk->makeDirectory('staging');
        $id = (string) Str::uuid();

        try {
            $file->move($disk->path('staging'), $id.'.pdf');
        } catch (FileException $exception) {
            report($exception);

            return $this->uploadFailure($displayName, 'Could not save this file to temporary storage. Check available disk space and folder permissions.', $size);
        }

        $path = 'staging/'.$id.'.pdf';
        $parsed = $filename->parse($originalName);
        try {
            Cache::put('voucher-amount:'.$id, ['status' => 'pending', 'amount' => null], now()->addDay());
            ReadVoucherAmount::dispatch($id);
        } catch (\Throwable $exception) {
            Cache::forget('voucher-amount:'.$id);
            report($exception);
        }

        return [
            'id' => $id,
            'file' => ($parsed ?? ['checkada_no' => '', 'dv_number' => '', 'payee' => '']) + [
                'path' => $path,
                'original_name' => $displayName,
                'size' => $size,
                'detected_amount' => null,
            ],
            'size' => $size,
            'error' => null,
        ];
    }

    /**
     * @return array{id: null, file: null, size: int, error: string}
     */
    private function uploadFailure(string $filename, string $reason, int $size = 0): array
    {
        Log::warning('Voucher upload rejected', ['filename' => $filename, 'reason' => $reason, 'bytes' => $size,
            'batch_id' => session('voucher_upload.batch_id'), 'user_id' => auth()->id()]);

        return ['id' => null, 'file' => null, 'size' => $size, 'error' => $filename.': '.$reason];
    }

    /**
     * @param  array<string, mixed>  $upload
     * @return array<string, mixed>
     */
    private function withDetectedAmounts(array $upload): array
    {
        $keys = array_map(fn (string $id): string => 'voucher-amount:'.$id, array_keys($upload['files']));
        $results = Cache::many($keys);
        foreach ($upload['files'] as $id => &$file) {
            $result = $results['voucher-amount:'.$id] ?? null;
            $file['detected_amount'] = $result['amount'] ?? $file['detected_amount'];
            $file['amount_status'] = $result['status'] ?? ($file['detected_amount'] !== null ? 'ready' : 'manual');
        }

        return $upload;
    }
}
