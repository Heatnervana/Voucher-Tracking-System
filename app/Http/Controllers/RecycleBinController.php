<?php

namespace App\Http\Controllers;

use App\Models\Voucher;
use App\VoucherRecycleBin;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class RecycleBinController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return view('vouchers.recycle-bin', ['vouchers' => Voucher::onlyTrashed()->with('deleter')->search($filters['q'] ?? null)
            ->orderByDesc('deleted_at')->paginate(20)->withQueryString(), 'filters' => $filters]);
    }

    public function preview(Voucher $voucher): StreamedResponse
    {
        $voucher->refresh();
        abort_unless($voucher->trashed() && Storage::disk('local')->exists($voucher->file_path), 404);

        return Storage::disk('local')->response($voucher->file_path, basename($voucher->file_path), ['Content-Type' => 'application/pdf']);
    }

    public function restore(Voucher $voucher, VoucherRecycleBin $recycle): RedirectResponse
    {
        $voucher->refresh();
        abort_unless($voucher->trashed(), 404);

        return $this->restoreVouchers(new Collection([$voucher]), $recycle);
    }

    public function restoreSelected(Request $request, VoucherRecycleBin $recycle): RedirectResponse
    {
        return $this->restoreVouchers($this->selected($request), $recycle);
    }

    public function destroy(Voucher $voucher, VoucherRecycleBin $recycle): RedirectResponse
    {
        $voucher->refresh();
        abort_unless($voucher->trashed(), 404);

        return $this->purgeVouchers(new Collection([$voucher]), $recycle);
    }

    public function destroySelected(Request $request, VoucherRecycleBin $recycle): RedirectResponse
    {
        return $this->purgeVouchers($this->selected($request), $recycle);
    }

    /** @return Collection<int, Voucher> */
    private function selected(Request $request): Collection
    {
        $data = $request->validate(['voucher_ids' => ['required', 'array', 'min:1', 'max:20'],
            'voucher_ids.*' => ['required', 'integer', 'distinct', Rule::exists('vouchers', 'id')->whereNotNull('deleted_at')]]);

        return Voucher::onlyTrashed()->whereIn('id', $data['voucher_ids'])->get();
    }

    /** @param Collection<int, Voucher> $vouchers */
    private function restoreVouchers(Collection $vouchers, VoucherRecycleBin $recycle): RedirectResponse
    {
        try {
            $count = $recycle->restore($vouchers);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['recycle' => $exception instanceof DomainException ? $exception->getMessage() : 'Restore failed. No records were restored. Please try again or contact your administrator.']);
        }

        return redirect()->route('recycle-bin.index')->with('status', $count.' PDF(s) restored to the voucher library.');
    }

    /** @param Collection<int, Voucher> $vouchers */
    private function purgeVouchers(Collection $vouchers, VoucherRecycleBin $recycle): RedirectResponse
    {
        try {
            $result = $recycle->purge($vouchers);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['recycle' => 'Permanent deletion failed. The records remain in the recycle bin.']);
        }
        $response = redirect()->route('recycle-bin.index')->with('status', $result['purged'].' PDF(s) permanently deleted.');
        if ($result['cleanup_failed']) {
            $response->withErrors(['recycle' => 'The records were removed, but private PDF cleanup failed. The problem was logged; contact your administrator.']);
        }

        return $response;
    }
}
