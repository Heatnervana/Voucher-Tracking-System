<?php

namespace App\Jobs;

use App\PdfAmountReader;
use App\VoucherUtilityCategory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ReadVoucherAmount implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    /**
     * TRUST-BOUNDARY: Only uploader-generated UUIDs should populate fileId; clients never supply a parser path.
     */
    public function __construct(public string $fileId, public ?string $nameUtility = null)
    {
        // SECURITY: Keep OCR off the HTTP process even when the default queue is synchronous.
        $this->onConnection('database')->onQueue('voucher-ocr');
    }

    /**
     * SECURITY: Rechecking cache and file existence prevents canceled uploads from publishing late OCR results.
     */
    public function handle(PdfAmountReader $reader): void
    {
        $key = 'voucher-amount:'.$this->fileId;
        $disk = Storage::disk('local');
        $path = 'staging/'.$this->fileId.'.pdf';

        if (! Cache::has($key) || ! $disk->exists($path)) {
            return;
        }

        $file = $disk->path($path);
        $nameUtility = $this->nameUtility ?? null;
        $details = isset(VoucherUtilityCategory::LABELS[$nameUtility])
            ? ['amount' => $reader->read($file), 'utility' => null]
            : $reader->readDetails($file);
        $amount = $details['amount'];

        if (Cache::has($key) && $disk->exists($path)) {
            Cache::put($key, ['status' => $amount === null ? 'manual' : 'ready', 'amount' => $amount, 'utility' => $details['utility']], now()->addDay());
        }
    }

    public function failed(?Throwable $exception): void
    {
        $key = 'voucher-amount:'.$this->fileId;
        if (Cache::has($key)) {
            Cache::put($key, ['status' => 'manual', 'amount' => null], now()->addDay());
        }
    }
}
