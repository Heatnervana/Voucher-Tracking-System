<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherYear;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class VoucherDuplicateRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_different_checks_can_share_a_dv_number_in_the_same_year_and_batch(): void
    {
        $this->setUpLibrary();
        $this->save([['checkada_no' => '1100', 'dv_number' => '123456'], ['checkada_no' => '1101', 'dv_number' => '123456']])
            ->assertRedirect(route('vouchers.index'));
        $this->assertDatabaseCount('vouchers', 2);
        $this->get(route('vouchers.index', ['q' => '123456']))->assertViewHas('vouchers', fn ($rows) => $rows->total() === 2);
    }

    public function test_duplicate_pair_is_rejected_across_months_and_payment_types_with_its_location(): void
    {
        $this->setUpLibrary();
        $existing = $this->voucher('ADA-123', '123456', 2026, 'CHECK', 6);
        $this->save([['checkada_no' => 'ada-123', 'dv_number' => '123456']])->assertRedirect(route('vouchers.confirm'))
            ->assertSessionHas('upload_errors', fn ($errors) => str_contains($errors[0], 'Check / ADA number ADA-123')
                && str_contains($errors[0], 'June 2026 / CHECK') && str_contains($errors[0], $existing->file_path));
        $this->assertDatabaseCount('vouchers', 1);
        $this->assertCount(1, session('voucher_upload.files'));
    }

    public function test_same_check_with_a_different_dv_is_allowed_in_one_batch(): void
    {
        $this->setUpLibrary();
        $this->save([['checkada_no' => '1100', 'dv_number' => '123456'], ['checkada_no' => '1100', 'dv_number' => '987654']])
            ->assertRedirect(route('vouchers.index'));
        $this->assertDatabaseCount('vouchers', 2);
    }

    public function test_repeated_pair_in_one_batch_keeps_the_second_pdf_in_review(): void
    {
        $this->setUpLibrary();
        $this->save([['checkada_no' => 'ADA-123', 'dv_number' => '123456'], ['checkada_no' => 'ada-123', 'dv_number' => '123456']])
            ->assertRedirect(route('vouchers.confirm'))
            ->assertSessionHas('saved_count', 1)
            ->assertSessionHas('upload_errors', fn ($errors) => str_contains($errors[0], 'upload-1.pdf')
                && str_contains($errors[0], 'ADA-123') && str_contains($errors[0], '123456')
                && str_contains($errors[0], 'upload-0.pdf'));
        $this->assertDatabaseCount('vouchers', 1);
        $remaining = session('voucher_upload.files');
        $this->assertCount(1, $remaining);
        $this->assertSame('upload-1.pdf', array_values($remaining)[0]['original_name']);
        Storage::disk('local')->assertExists(array_values($remaining)[0]['path']);
    }

    public function test_one_repeated_number_with_a_different_counterpart_is_allowed_across_years(): void
    {
        $this->setUpLibrary();
        $this->voucher('1100', '123456', 2026);
        $this->save([['checkada_no' => '1100', 'dv_number' => '987654'], ['checkada_no' => '1101', 'dv_number' => '123456']], 2027)
            ->assertRedirect(route('vouchers.index'));
        $this->assertDatabaseCount('vouchers', 3);
    }

    public function test_pair_cannot_be_reused_in_a_different_year(): void
    {
        $this->setUpLibrary();
        $this->voucher('1100', '123456', 2026);
        $this->save([['checkada_no' => '1100', 'dv_number' => '123456']], 2027)->assertRedirect(route('vouchers.confirm'))
            ->assertSessionHas('upload_errors', fn ($errors) => str_contains($errors[0], '1100') && str_contains($errors[0], 'October 2026 / ADA'));
        $this->assertDatabaseCount('vouchers', 1);
    }

    public function test_folder_replacement_cannot_reuse_a_pair_from_another_year(): void
    {
        $this->setUpLibrary();
        $existing = $this->voucher('1100', '123456', 2026);
        $this->save([['checkada_no' => '1100', 'dv_number' => '123456']], 2027, ['folder_action' => 'replace'])
            ->assertRedirect(route('vouchers.confirm'))->assertSessionHas('upload_errors', fn ($errors) => str_contains($errors[0], 'October 2026 / ADA'));
        $this->assertSame(2026, $existing->fresh()->year);
        $this->assertSame('%PDF original', Storage::disk('local')->get($existing->file_path));
        $this->assertDatabaseCount('vouchers', 1);
    }

    #[TestWith([2026])]
    #[TestWith([2027])]
    public function test_database_prevents_case_variant_pair_duplicates_in_any_year(int $targetYear): void
    {
        $this->setUpLibrary();
        $this->voucher('ADA-123', '123456', 2026);
        $this->expectException(QueryException::class);
        $this->voucher('ada-123', '123456', $targetYear, 'CHECK', 6);
    }

    public function test_rollback_refuses_single_check_uniqueness_when_distinct_pairs_share_a_check(): void
    {
        $this->setUpLibrary();
        $migration = require database_path('migrations/2026_11_04_081915_identify_voucher_duplicates_by_check_and_dv_pair.php');
        $this->voucher('ADA-123', '123456', 2026);
        $this->voucher('ada-123', '987654', 2027, 'CHECK', 6);
        try {
            $migration->down();
            $this->fail('Rollback must preserve distinct voucher pairs.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('ADA-123', $exception->getMessage());
            $this->assertDatabaseCount('vouchers', 2);
        }
    }

    public function test_rollback_does_not_restore_dv_uniqueness_over_repeated_dvs(): void
    {
        $this->setUpLibrary();
        $this->voucher('1100', '123456', 2026);
        $this->voucher('1101', '123456', 2026);
        $migration = require database_path('migrations/2026_11_04_080230_use_year_and_check_number_for_voucher_uniqueness.php');
        try {
            $migration->down();
            $this->fail('Rollback must preserve repeated DV records.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Cannot restore DV uniqueness', $exception->getMessage());
            $this->assertDatabaseCount('vouchers', 2);
        }
    }

    public function test_moving_an_existing_voucher_preserves_its_pair(): void
    {
        $this->setUpLibrary();
        $conflict = $this->voucher('1100', '999999', 2026, 'CHECK', 6);
        $moving = $this->voucher('1100', '123456', 2027);
        $this->from(route('vouchers.show', $moving))->post(route('vouchers.move', $moving), ['year' => 2026, 'type' => 'ADA', 'month' => 10])
            ->assertSessionHasNoErrors();
        $this->assertSame(2026, $moving->fresh()->year);
        $other = $this->voucher('1102', '123456', 2026);
        $this->post(route('vouchers.move', $other), ['year' => 2027, 'type' => 'CHECK', 'month' => 6])->assertSessionHasNoErrors();
        $this->assertSame(2027, $other->fresh()->year);
        Storage::disk('local')->assertExists($conflict->file_path);
    }

    private function setUpLibrary(): void
    {
        Storage::fake('local');
        $user = User::create(['username' => 'rules-admin', 'name' => 'Administrator', 'password' => 'TestPassword123!']);
        $user->forceFill(['is_admin' => true, 'must_change_password' => false])->save();
        $this->actingAs($user);
        foreach ([2026, 2027] as $year) {
            VoucherYear::create(['year' => $year]);
        }
    }

    private function voucher(string $check, string $dv, int $year, string $type = 'ADA', int $month = 10): Voucher
    {
        $path = 'vouchers/'.$year.'/'.$type.'/'.$month.'/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($path, '%PDF original');

        return Voucher::create(['checkada_no' => $check, 'dv_number' => $dv, 'payee' => 'Test Payee', 'amount' => '12.34',
            'year' => $year, 'type' => $type, 'month' => $month, 'file_path' => $path, 'uploaded_by' => auth()->id()]);
    }

    private function save(array $details, int $year = 2026, array $options = []): TestResponse
    {
        $files = [];
        $vouchers = [];
        foreach ($details as $index => $data) {
            $id = (string) Str::uuid();
            $path = 'staging/'.$id.'.pdf';
            Storage::disk('local')->put($path, '%PDF new');
            $files[$id] = ['path' => $path, 'original_name' => 'upload-'.$index.'.pdf'];
            $vouchers[$id] = $data + ['payee' => 'Test Payee', 'amount' => '25.00'];
        }
        $batch = ['batch_id' => (string) Str::uuid(), 'year' => $year, 'type' => 'ADA', 'month' => 10, 'files' => $files] + $options;

        return $this->withSession(['voucher_upload' => $batch])->post(route('vouchers.finalize'), ['batch_id' => $batch['batch_id'], 'vouchers' => $vouchers]);
    }

    public function test_unfiltered_dv_search_finds_pdfs_across_years_types_and_months(): void
    {
        $user = User::create(['username' => 'search-admin', 'name' => 'Administrator', 'password' => 'TestPassword123!']);
        $user->forceFill(['is_admin' => true, 'must_change_password' => false])->save();
        $this->actingAs($user);
        foreach ([2024, 2025, 2026] as $index => $year) {
            VoucherYear::create(['year' => $year]);
            Voucher::create(['checkada_no' => (string) (1100 + $index), 'dv_number' => '1234567890', 'payee' => 'Payee '.$year,
                'amount' => $index === 0 ? null : '123.45', 'amount_status' => $index === 0 ? 'manual' : 'ready',
                'year' => $year, 'type' => $index === 1 ? 'ADA' : 'CHECK', 'month' => $index + 1,
                'file_path' => 'vouchers/'.$year.'/test.pdf', 'uploaded_by' => $user->id]);
        }
        $filters = ['q' => '1234567890', 'year' => '', 'type' => '', 'month' => '', 'amount_status' => ''];
        $this->get(route('vouchers.index', $filters))->assertOk()->assertViewHas('vouchers', fn ($vouchers) => $vouchers->total() === 3)
            ->assertSee('Payee 2024')->assertSee('Payee 2025')->assertSee('Payee 2026');
        $this->get(route('vouchers.index', array_replace($filters, ['month' => 2])))->assertOk()
            ->assertViewHas('vouchers', fn ($vouchers) => $vouchers->total() === 1)->assertSee('Payee 2025')->assertDontSee('Payee 2024');
    }
}
