<?php

namespace App\Jobs;

use App\ActivityRecorder;
use App\Models\Voucher;
use App\PdfAmountReader;
use App\VoucherUtilityCategory;
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

    public function __construct(public int $voucherId, ?string $readToken = null, public ?string $nameUtility = null)
    {
        $this->readToken = $readToken;
        $this->onConnection('database')->onQueue('voucher-ocr');
    }

    /**
     * SECURITY: Matching read tokens and conditional updates prevent stale OCR from replacing manual or newer results.
     */
    public function handle(PdfAmountReader $reader): void
    {
        $voucher = Voucher::query()->find($this->voucherId);
        if ($voucher === null || $voucher->amount_read_token !== $this->readToken) {
            return;
        }

        $readAmount = $voucher->amount === null && $voucher->amount_status === 'pending';
        $readUtility = in_array($voucher->utility_status, ['pending', 'pending_required'], true);
        if (! $readAmount && ! $readUtility) {
            return;
        }

        $path = Storage::disk('local')->path($voucher->file_path);
        $details = $readUtility
            ? $reader->readDetails($path, readAmount: $readAmount)
            : ['amount' => $reader->read($path), 'utility' => null];
        $amount = $details['amount'];
        if (Validator::make(['amount' => $amount], [
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99', 'decimal:0,2'],
        ])->fails()) {
            $amount = null;
        }

        DB::transaction(function () use ($voucher, $amount, $details, $readAmount, $readUtility): void {
            if ($readUtility) {
                $this->finishUtilityRead($voucher, $details['utility']);
            }
            if (! $readAmount) {
                return;
            }
            $updated = Voucher::query()->whereKey($this->voucherId)->whereNull('amount')->where('amount_status', 'pending')->where('amount_read_token', $this->readToken)
                ->update(['amount' => $amount, 'amount_status' => $amount === null ? 'manual' : 'ready']);
            if ($updated === 1 && $amount !== null) {
                $voucher->amount = $amount;
                app(ActivityRecorder::class)->record($voucher, 'voucher.amount_read', ['amount' => null], ['amount' => $voucher->amount], system: true);
            }
        });
    }

    /**
     * SECURITY: Failure may change only the still-pending read generation and must preserve manual edits.
     */
    public function failed(?Throwable $exception): void
    {
        Voucher::query()->whereKey($this->voucherId)->whereNull('amount')->where('amount_status', 'pending')->where('amount_read_token', $this->readToken)
            ->update(['amount_status' => 'manual']);
        $voucher = Voucher::query()->find($this->voucherId);
        if ($voucher !== null && $voucher->amount_read_token === $this->readToken) {
            $this->finishUtilityRead($voucher, null);
        }
    }

    private function finishUtilityRead(Voucher $voucher, ?string $suggestion): void
    {
        if (! in_array($voucher->utility_status, ['pending', 'pending_required'], true)) {
            return;
        }

        $category = VoucherUtilityCategory::resolve($suggestion, $this->nameUtility ?? null);
        $updated = Voucher::query()->whereKey($voucher->id)->whereNull('utility_category')
            ->where('utility_status', $voucher->utility_status)->where('amount_read_token', $this->readToken)
            ->update(['utility_category' => $category['category'], 'utility_status' => $category['status']]);
        if ($updated === 1 && $category['category'] !== null) {
            app(ActivityRecorder::class)->record($voucher, 'voucher.category_read', ['utility_category' => null], ['utility_category' => $category['category']], system: true);
        }
    }
}
