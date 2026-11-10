<?php

namespace App\Jobs;

use App\PdfAmountReader;
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

    public function __construct(public string $fileId)
    {
        // Keep OCR off the HTTP process even when the default queue is synchronous.
        $this->onConnection('database')->onQueue('voucher-ocr');
    }

    public function handle(PdfAmountReader $reader): void
    {
        $key = 'voucher-amount:'.$this->fileId;
        $disk = Storage::disk('local');
        $path = 'staging/'.$this->fileId.'.pdf';

        if (! Cache::has($key) || ! $disk->exists($path)) {
            return;
        }

        $amount = $reader->read($disk->path($path));

        if (Cache::has($key) && $disk->exists($path)) {
            Cache::put($key, ['status' => $amount === null ? 'manual' : 'ready', 'amount' => $amount], now()->addDay());
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
