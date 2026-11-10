<?php

namespace App\Jobs;

use App\ActivityRecorder;
use App\Models\Voucher;
use App\PdfAmountReader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ReadSavedVoucherAmount implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public ?string $readToken = null;

    public function __construct(public int $voucherId, ?string $readToken = null)
    {
        $this->readToken = $readToken;
        $this->onConnection('database')->onQueue('voucher-ocr');
    }

    public function handle(PdfAmountReader $reader): void
    {
        $voucher = Voucher::query()->find($this->voucherId);
        if ($voucher === null || $voucher->amount !== null || $voucher->amount_status !== 'pending' || $voucher->amount_read_token !== $this->readToken) {
            return;
        }

        $amount = $reader->read(Storage::disk('local')->path($voucher->file_path));
        if (Validator::make(['amount' => $amount], [
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99', 'decimal:0,2'],
        ])->fails()) {
            $amount = null;
        }

        DB::transaction(function () use ($voucher, $amount): void {
            $updated = Voucher::query()->whereKey($this->voucherId)->whereNull('amount')->where('amount_status', 'pending')->where('amount_read_token', $this->readToken)
                ->update(['amount' => $amount, 'amount_status' => $amount === null ? 'manual' : 'ready']);
            if ($updated === 1 && $amount !== null) {
                $voucher->amount = $amount;
                app(ActivityRecorder::class)->record($voucher, 'voucher.amount_read', ['amount' => null], ['amount' => $voucher->amount], system: true);
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        Voucher::query()->whereKey($this->voucherId)->whereNull('amount')->where('amount_status', 'pending')->where('amount_read_token', $this->readToken)
            ->update(['amount_status' => 'manual']);
    }
}
