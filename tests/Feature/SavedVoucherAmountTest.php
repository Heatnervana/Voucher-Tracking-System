<?php

namespace Tests\Feature;

use App\Jobs\ReadSavedVoucherAmount;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherYear;
use App\PdfAmountReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class SavedVoucherAmountTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_fills_the_saved_voucher_amount(): void
    {
        Storage::fake('local');
        $voucher = $this->voucher();
        $this->mock(PdfAmountReader::class)->shouldReceive('read')->once()
            ->with(Storage::disk('local')->path($voucher->file_path))->andReturn('5250.00');

        ReadSavedVoucherAmount::dispatch($voucher->id);
        $this->runWorker();

        $this->assertDatabaseHas('vouchers', ['id' => $voucher->id, 'amount' => '5250.00', 'amount_status' => 'ready']);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    #[TestWith([null])]
    #[TestWith([''])]
    #[TestWith(['0'])]
    #[TestWith(['-1'])]
    #[TestWith(['0.001'])]
    #[TestWith(['10000000000000'])]
    #[TestWith(['not money'])]
    public function test_unreadable_or_invalid_amount_requires_manual_entry(?string $amount): void
    {
        Storage::fake('local');
        $voucher = $this->voucher();
        $this->mock(PdfAmountReader::class)->shouldReceive('read')->once()->andReturn($amount);

        ReadSavedVoucherAmount::dispatch($voucher->id);
        $this->runWorker();

        $this->assertDatabaseHas('vouchers', ['id' => $voucher->id, 'amount' => null, 'amount_status' => 'manual']);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_reader_exception_marks_the_saved_voucher_for_manual_entry(): void
    {
        Storage::fake('local');
        $voucher = $this->voucher();
        $this->mock(PdfAmountReader::class)->shouldReceive('read')->once()->andThrow(new RuntimeException('OCR unavailable'));

        ReadSavedVoucherAmount::dispatch($voucher->id);
        $this->runWorker();

        $this->assertDatabaseHas('vouchers', ['id' => $voucher->id, 'amount' => null, 'amount_status' => 'manual']);
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    public function test_missing_saved_pdf_requires_manual_entry(): void
    {
        Storage::fake('local');
        $voucher = $this->voucher();
        Storage::disk('local')->delete($voucher->file_path);

        ReadSavedVoucherAmount::dispatch($voucher->id);
        $this->runWorker();

        $this->assertDatabaseHas('vouchers', ['id' => $voucher->id, 'amount' => null, 'amount_status' => 'manual']);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_timeout_failure_marks_only_pending_blank_amounts_for_manual_entry(): void
    {
        Storage::fake('local');
        $voucher = $this->voucher();
        $job = new ReadSavedVoucherAmount($voucher->id);
        $this->assertTrue($job->failOnTimeout);

        $job->failed(new TimeoutExceededException('OCR timed out'));

        $this->assertDatabaseHas('vouchers', ['id' => $voucher->id, 'amount' => null, 'amount_status' => 'manual']);
    }

    public function test_manual_amount_entered_before_processing_is_preserved(): void
    {
        Storage::fake('local');
        $voucher = $this->voucher();
        ReadSavedVoucherAmount::dispatch($voucher->id);
        $voucher->update(['amount' => '80.00', 'amount_status' => 'ready']);
        $this->mock(PdfAmountReader::class)->shouldNotReceive('read');

        $this->runWorker();

        $this->assertDatabaseHas('vouchers', ['id' => $voucher->id, 'amount' => '80.00', 'amount_status' => 'ready']);
    }

    public function test_manual_amount_entered_during_processing_is_preserved(): void
    {
        Storage::fake('local');
        $voucher = $this->voucher();
        $this->mock(PdfAmountReader::class)->shouldReceive('read')->once()->andReturnUsing(function () use ($voucher): string {
            $voucher->update(['amount' => '80.00', 'amount_status' => 'ready']);

            return '5250.00';
        });

        ReadSavedVoucherAmount::dispatch($voucher->id);
        $this->runWorker();

        $this->assertDatabaseHas('vouchers', ['id' => $voucher->id, 'amount' => '80.00', 'amount_status' => 'ready']);
    }

    public function test_worker_failure_preserves_a_manual_amount_entered_during_processing(): void
    {
        Storage::fake('local');
        $voucher = $this->voucher();
        $this->mock(PdfAmountReader::class)->shouldReceive('read')->once()->andReturnUsing(function () use ($voucher): never {
            $voucher->update(['amount' => '80.00', 'amount_status' => 'ready']);
            throw new RuntimeException('OCR failed after manual entry');
        });

        ReadSavedVoucherAmount::dispatch($voucher->id);
        $this->runWorker();

        $this->assertDatabaseHas('vouchers', ['id' => $voucher->id, 'amount' => '80.00', 'amount_status' => 'ready']);
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    public function test_deleted_voucher_is_skipped_before_processing(): void
    {
        Storage::fake('local');
        $voucher = $this->voucher();
        ReadSavedVoucherAmount::dispatch($voucher->id);
        $voucher->delete();
        $this->mock(PdfAmountReader::class)->shouldNotReceive('read');

        $this->runWorker();

        $this->assertSoftDeleted($voucher);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_deleting_a_voucher_during_processing_does_not_restore_it(): void
    {
        Storage::fake('local');
        $voucher = $this->voucher();
        $this->mock(PdfAmountReader::class)->shouldReceive('read')->once()->andReturnUsing(function () use ($voucher): string {
            Storage::disk('local')->delete($voucher->file_path);
            $voucher->delete();

            return '5250.00';
        });

        ReadSavedVoucherAmount::dispatch($voucher->id);
        $this->runWorker();

        $this->assertSoftDeleted($voucher);
        Storage::disk('local')->assertMissing($voucher->file_path);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_worker_reads_the_current_filename_after_a_queued_voucher_is_renamed(): void
    {
        Storage::fake('local');
        $voucher = $this->voucher();
        ReadSavedVoucherAmount::dispatch($voucher->id);
        Storage::disk('local')->move($voucher->file_path, 'vouchers/renamed.pdf');
        $voucher->update(['file_path' => 'vouchers/renamed.pdf']);
        $this->mock(PdfAmountReader::class)->shouldReceive('read')->once()
            ->with(Storage::disk('local')->path('vouchers/renamed.pdf'))->andReturn('5250.00');

        $this->runWorker();

        $this->assertDatabaseHas('vouchers', ['id' => $voucher->id, 'file_path' => 'vouchers/renamed.pdf', 'amount' => '5250.00', 'amount_status' => 'ready']);
    }

    public function test_renaming_during_processing_preserves_the_filename_and_saves_a_successful_read(): void
    {
        Storage::fake('local');
        $voucher = $this->voucher();
        $this->mock(PdfAmountReader::class)->shouldReceive('read')->once()->andReturnUsing(function () use ($voucher): string {
            Storage::disk('local')->move($voucher->file_path, 'vouchers/renamed.pdf');
            $voucher->update(['file_path' => 'vouchers/renamed.pdf']);

            return '5250.00';
        });

        ReadSavedVoucherAmount::dispatch($voucher->id);
        $this->runWorker();

        $this->assertDatabaseHas('vouchers', ['id' => $voucher->id, 'file_path' => 'vouchers/renamed.pdf', 'amount' => '5250.00', 'amount_status' => 'ready']);
        Storage::disk('local')->assertExists('vouchers/renamed.pdf');
    }

    public function test_duplicate_jobs_do_not_read_or_overwrite_an_already_resolved_amount(): void
    {
        Storage::fake('local');
        $voucher = $this->voucher();
        $this->mock(PdfAmountReader::class)->shouldReceive('read')->once()->andReturn('5250.00');
        ReadSavedVoucherAmount::dispatch($voucher->id);
        ReadSavedVoucherAmount::dispatch($voucher->id);

        $this->runWorker();
        $this->runWorker();

        $this->assertDatabaseHas('vouchers', ['id' => $voucher->id, 'amount' => '5250.00', 'amount_status' => 'ready']);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_a_manual_entry_voucher_is_not_read_again(): void
    {
        Storage::fake('local');
        $voucher = $this->voucher(['amount_status' => 'manual']);
        $this->mock(PdfAmountReader::class)->shouldNotReceive('read');

        ReadSavedVoucherAmount::dispatch($voucher->id);
        $this->runWorker();

        $this->assertDatabaseHas('vouchers', ['id' => $voucher->id, 'amount' => null, 'amount_status' => 'manual']);
    }

    public function test_needs_amount_scope_excludes_pending_and_completed_vouchers(): void
    {
        Storage::fake('local');
        $manual = $this->voucher(['amount_status' => 'manual']);
        $this->voucher();
        $this->voucher(['amount' => '80.00', 'amount_status' => 'ready']);
        $this->voucher(['amount' => '90.00', 'amount_status' => 'manual']);

        $this->assertSame([$manual->id], Voucher::needsAmount()->pluck('id')->all());
    }

    public function test_migration_preserves_amounts_and_marks_existing_blanks_for_manual_entry(): void
    {
        Storage::fake('local');
        $blank = $this->voucher();
        $completed = $this->voucher(['amount' => '80.00']);
        $migration = require database_path('migrations/2026_10_29_025612_add_amount_status_to_vouchers_table.php');
        $migration->down();

        $migration->up();

        $this->assertDatabaseHas('vouchers', ['id' => $blank->id, 'amount' => null, 'amount_status' => 'manual']);
        $this->assertDatabaseHas('vouchers', ['id' => $completed->id, 'amount' => '80.00', 'amount_status' => 'ready']);
    }

    /** @param array<string, mixed> $attributes */
    private function voucher(array $attributes = []): Voucher
    {
        $user = User::create(['username' => 'ocr-'.Str::uuid(), 'name' => 'OCR Tester', 'password' => 'TestPassword123!']);
        VoucherYear::firstOrCreate(['year' => 2026]);
        $path = 'vouchers/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 sample');

        return Voucher::create(array_merge([
            'checkada_no' => (string) (1000 + $user->id), 'dv_number' => (string) (100000 + $user->id), 'payee' => 'Test Payee',
            'amount' => null, 'amount_status' => 'pending', 'year' => 2026, 'type' => 'ADA', 'month' => 10,
            'file_path' => $path, 'uploaded_by' => $user->id,
        ], $attributes));
    }

    private function runWorker(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'voucher-ocr', '--once' => true, '--tries' => 1])->assertSuccessful();
    }
}
