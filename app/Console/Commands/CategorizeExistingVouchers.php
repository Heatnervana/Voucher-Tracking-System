<?php

namespace App\Console\Commands;

use App\ActivityRecorder;
use App\Models\Voucher;
use App\PdfAmountReader;
use App\VoucherUtilityCategory;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CategorizeExistingVouchers extends Command
{
    protected $signature = 'vouchers:categorize-existing
        {--apply : Save detected categories and review alerts}
        {--limit=25 : Maximum number of PDFs to check in this batch}
        {--after-id=0 : Resume after this voucher ID}
        {--through-id= : Last voucher ID in the scan; defaults to the latest active voucher ID}';

    protected $description = 'Preview or apply electricity and water categories for saved voucher PDFs';

    public function handle(PdfAmountReader $reader, ActivityRecorder $activity): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $after = filter_var($this->option('after-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $through = filter_var($this->option('through-id') ?? (int) Voucher::max('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($limit === false || $after === false || $through === false) {
            $this->error('Use a positive batch limit and nonnegative voucher IDs.');

            return self::INVALID;
        }

        $apply = (bool) $this->option('apply');
        $vouchers = Voucher::query()->where('id', '>', $after)->where('id', '<=', $through)
            ->whereNull('utility_category')->where('utility_status', 'none')
            ->orderBy('id')->limit($limit)->get();
        if ($vouchers->isEmpty()) {
            $this->info('No eligible vouchers in this range.');

            return self::SUCCESS;
        }

        $disk = Storage::disk('local');
        $root = realpath($disk->path('vouchers'));
        $lock = @fopen(storage_path('framework/voucher-files.lock'), 'c');
        if ($lock === false) {
            $this->error('Could not open the voucher storage lock.');

            return self::FAILURE;
        }

        $lastId = $after;
        $examined = 0;
        $proposed = 0;
        $saved = 0;
        $unreadable = 0;
        $busy = false;

        try {
            foreach ($vouchers as $voucher) {
                if (! flock($lock, LOCK_SH | LOCK_NB)) {
                    $this->warn('Voucher files are busy. Retry this batch from the cursor below.');
                    $busy = true;

                    break;
                }

                try {
                    $lastId = $voucher->id;
                    $examined++;
                    $category = $this->inspectVoucher($voucher, $disk, $root, $reader);
                    if ($category === null) {
                        $unreadable++;

                        continue;
                    }
                    if ($category['category'] === null && $category['status'] === 'none') {
                        continue;
                    }

                    $proposed++;
                    if ($apply && ! $this->saveCategory($voucher, $category, $activity)) {
                        $this->warn("Voucher #{$voucher->id}: record changed during the scan");

                        continue;
                    }
                    if ($apply) {
                        $saved++;
                    }
                    $label = VoucherUtilityCategory::LABELS[$category['category']] ?? 'Needs category review';
                    $this->line("Voucher #{$voucher->id}: {$label}");
                } finally {
                    flock($lock, LOCK_UN);
                }
            }
        } finally {
            fclose($lock);
        }

        $this->info(($apply ? 'Apply' : 'Preview').": examined {$examined}, matches or review alerts {$proposed}, saved {$saved}, unreadable {$unreadable}.");
        $this->line("Continue with --after-id={$lastId} --through-id={$through} --limit={$limit}".($apply ? ' --apply' : ''));

        return $busy ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The caller holds the shared storage lock so OCR cannot open a PDF while a replacement is in progress.
     *
     * @return array{category: ?string, status: string}|null
     */
    private function inspectVoucher(Voucher $voucher, FilesystemAdapter $disk, string|false $root, PdfAmountReader $reader): ?array
    {
        $path = realpath($disk->path($voucher->file_path));
        if (! $this->isSavedPdf($path, $root)) {
            $this->warn("Voucher #{$voucher->id}: saved PDF unavailable");

            return null;
        }

        $name = VoucherUtilityCategory::fromName($voucher->file_path);
        $payee = VoucherUtilityCategory::fromName($voucher->payee);
        if (! isset(VoucherUtilityCategory::LABELS[$name])) {
            $name = isset(VoucherUtilityCategory::LABELS[$payee]) ? $payee : ($name ?? $payee);
        }
        $description = isset(VoucherUtilityCategory::LABELS[$name])
            ? null
            : $reader->readDetails($path, readAmount: false)['utility'];
        $category = VoucherUtilityCategory::resolve($description, $name);
        if ($description === null && $category['category'] === null && $category['status'] === 'none') {
            $this->warn("Voucher #{$voucher->id}: could not read the description");

            return null;
        }

        return $category;
    }

    private function isSavedPdf(string|false $path, string|false $root): bool
    {
        if ($path === false || $root === false || ! is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'pdf') {
            return false;
        }

        $prefix = $root.DIRECTORY_SEPARATOR;

        return PHP_OS_FAMILY === 'Windows'
            ? strncasecmp($path, $prefix, strlen($prefix)) === 0
            : str_starts_with($path, $prefix);
    }

    /** @param array{category: ?string, status: string} $category */
    private function saveCategory(Voucher $voucher, array $category, ActivityRecorder $activity): bool
    {
        return DB::transaction(function () use ($voucher, $category, $activity): bool {
            $changes = ['utility_category' => $category['category'], 'utility_status' => $category['status']];
            $updated = Voucher::query()->whereKey($voucher->id)->whereNull('utility_category')->where('utility_status', 'none')
                ->where('file_path', $voucher->file_path)->where('payee', $voucher->payee)->where('amount_read_token', $voucher->amount_read_token)
                ->update($changes);
            if ($updated !== 1) {
                return false;
            }

            if ($category['category'] !== null) {
                $activity->record($voucher, 'voucher.category_read', ['utility_category' => null, 'utility_status' => 'none'], $changes, system: true);
            }

            return true;
        });
    }
}
