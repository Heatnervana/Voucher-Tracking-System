<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherYear;
use App\PdfAmountReader;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CategorizeExistingVouchersTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_reports_a_filename_match_without_saving_it(): void
    {
        Storage::fake('local');
        $voucher = $this->savedVoucher(1, 'PRIMEWATER INFRASTRUCTURE CORP');
        $this->mock(PdfAmountReader::class)->shouldNotReceive('readDetails');

        $this->artisan('vouchers:categorize-existing')
            ->expectsOutputToContain("Voucher #{$voucher->id}: Water")
            ->assertSuccessful();

        $this->assertNull($voucher->fresh()->utility_category);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'voucher.category_read']);
    }

    public function test_scan_uses_the_payee_and_first_page_and_flags_only_likely_utilities(): void
    {
        Storage::fake('local');
        $water = $this->savedVoucher(1, 'Scanned voucher', ['payee' => 'PRIMEWATER INFRASTRUCTURE CORP']);
        $electricity = $this->savedVoucher(2, 'Supplier A');
        $ambiguous = $this->savedVoucher(3, 'Utility Bills');
        $ordinary = $this->savedVoucher(4, 'Fuel supplier');
        $unreadable = $this->savedVoucher(5, 'Supplier B');
        $missing = $this->savedVoucher(6, 'Supplier C');
        Storage::disk('local')->delete($missing->file_path);
        $reader = $this->mock(PdfAmountReader::class);
        foreach ([[$electricity, 'electricity'], [$ambiguous, null], [$ordinary, 'unmatched'], [$unreadable, null]] as [$voucher, $suggestion]) {
            $reader->shouldReceive('readDetails')->once()->with(realpath(Storage::disk('local')->path($voucher->file_path)), true, false)
                ->andReturn(['amount' => null, 'utility' => $suggestion]);
        }

        $this->artisan('vouchers:categorize-existing', ['--apply' => true])
            ->expectsOutputToContain("Voucher #{$unreadable->id}: could not read the description")
            ->expectsOutputToContain("Voucher #{$missing->id}: saved PDF unavailable")
            ->assertSuccessful();

        $this->assertSame('water', $water->fresh()->utility_category);
        $this->assertSame('electricity', $electricity->fresh()->utility_category);
        $this->assertSame([$ambiguous->id], Voucher::needsUtilityCategory()->pluck('id')->all());
        $this->assertNull($ordinary->fresh()->utility_category);
        $this->assertSame('none', $ordinary->fresh()->utility_status);
        $this->assertSame('150.00', $electricity->fresh()->amount);
        $this->assertSame('%PDF-1.4 fixture', Storage::disk('local')->get($electricity->file_path));
        $this->assertSame(2, ActivityLog::where('action', 'voucher.category_read')->where('actor_label', 'System')->count());
    }

    public function test_bounded_batch_skips_admin_choices_recycle_bin_and_in_progress_uploads(): void
    {
        Storage::fake('local');
        $before = $this->savedVoucher(1, 'MOELCI');
        $optedOut = $this->savedVoucher(2, 'MOELCI', ['utility_status' => 'confirmed']);
        $confirmed = $this->savedVoucher(3, 'MOELCI', ['utility_category' => 'water', 'utility_status' => 'confirmed']);
        $pending = $this->savedVoucher(4, 'MOELCI', ['utility_status' => 'pending']);
        $deleted = $this->savedVoucher(5, 'MOELCI');
        $deleted->delete();
        $first = $this->savedVoucher(6, 'MOELCI');
        $second = $this->savedVoucher(7, 'MOELCI');
        $outside = $this->savedVoucher(8, 'MOELCI');
        $this->mock(PdfAmountReader::class)->shouldNotReceive('readDetails');

        $this->artisan('vouchers:categorize-existing', ['--apply' => true, '--after-id' => $before->id, '--through-id' => $second->id, '--limit' => 1])
            ->expectsOutputToContain("--after-id={$first->id} --through-id={$second->id}")
            ->assertSuccessful();
        $this->assertNull($second->fresh()->utility_category);
        $this->artisan('vouchers:categorize-existing', ['--apply' => true, '--after-id' => $first->id, '--through-id' => $second->id])
            ->assertSuccessful();

        $this->assertSame('electricity', $first->fresh()->utility_category);
        $this->assertSame('electricity', $second->fresh()->utility_category);
        $this->assertSame('water', $confirmed->fresh()->utility_category);
        foreach ([$before, $optedOut, $pending, $deleted, $outside] as $skipped) {
            $this->assertNull($skipped->fresh()->utility_category);
        }
    }

    public function test_scan_preserves_a_manual_choice_made_during_ocr(): void
    {
        Storage::fake('local');
        $voucher = $this->savedVoucher(1, 'Supplier A');
        $this->mock(PdfAmountReader::class)->shouldReceive('readDetails')->once()->andReturnUsing(function () use ($voucher): array {
            $voucher->update(['utility_status' => 'confirmed']);

            return ['amount' => null, 'utility' => 'electricity'];
        });

        $this->artisan('vouchers:categorize-existing', ['--apply' => true])
            ->expectsOutputToContain("Voucher #{$voucher->id}: record changed during the scan")
            ->assertSuccessful();

        $this->assertNull($voucher->fresh()->utility_category);
        $this->assertSame('confirmed', $voucher->fresh()->utility_status);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'voucher.category_read']);
    }

    public function test_scan_stops_before_reading_a_pdf_during_a_file_change(): void
    {
        Storage::fake('local');
        $voucher = $this->savedVoucher(1, 'Supplier A');
        $lock = fopen(storage_path('framework/voucher-files.lock'), 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        $this->mock(PdfAmountReader::class)->shouldNotReceive('readDetails');

        try {
            $this->artisan('vouchers:categorize-existing', ['--apply' => true])
                ->expectsOutputToContain('Voucher files are busy')
                ->expectsOutputToContain('--after-id=0')
                ->assertExitCode(Command::FAILURE);
        } finally {
            fclose($lock);
        }

        $this->assertNull($voucher->fresh()->utility_category);
    }

    public function test_scan_protects_the_saved_file_while_ocr_reads_it(): void
    {
        Storage::fake('local');
        $voucher = $this->savedVoucher(1, 'Supplier A');
        $this->mock(PdfAmountReader::class)->shouldReceive('readDetails')->once()->andReturnUsing(function (): array {
            $lock = fopen(storage_path('framework/voucher-files.lock'), 'c');
            try {
                $this->assertFalse(flock($lock, LOCK_EX | LOCK_NB));
            } finally {
                fclose($lock);
            }

            return ['amount' => null, 'utility' => 'water'];
        });

        $this->artisan('vouchers:categorize-existing', ['--apply' => true])->assertSuccessful();

        $this->assertSame('water', $voucher->fresh()->utility_category);
    }

    private function savedVoucher(int $number, string $name, array $attributes = []): Voucher
    {
        VoucherYear::firstOrCreate(['year' => 2026]);
        $dv = 2026000000 + $number;
        $path = "vouchers/2026/CHECK/10/{$number}_{$dv}_{$name}.pdf";
        Storage::disk('local')->put($path, '%PDF-1.4 fixture');

        return Voucher::create($attributes + [
            'checkada_no' => (string) $number,
            'dv_number' => (string) $dv,
            'payee' => $name,
            'year' => 2026,
            'type' => 'CHECK',
            'month' => 10,
            'amount' => '150.00',
            'amount_status' => 'ready',
            'file_path' => $path,
            'uploaded_by' => User::factory()->create()->id,
        ]);
    }
}
