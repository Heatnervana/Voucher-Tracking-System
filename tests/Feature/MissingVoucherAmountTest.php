<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MissingVoucherAmountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $user = User::create(['username' => 'amount-admin', 'name' => 'Administrator', 'password' => 'TestPassword123!']);
        $user->forceFill(['is_admin' => true, 'must_change_password' => false])->save();
        $this->actingAs($user);
        VoucherYear::create(['year' => 2026]);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_a_mixed_batch_saves_blank_and_missing_amounts_as_null(bool $packed): void
    {
        $files = [];
        $details = [];
        foreach (['', '  ', null, '123.45'] as $index => $amount) {
            $id = (string) Str::uuid();
            $path = "staging/$id.pdf";
            Storage::disk('local')->put($path, '%PDF-1.4 sample '.$index);
            $files[$id] = ['path' => $path, 'original_name' => "sample-$index.pdf", 'detected_amount' => null];
            $details[$id] = ['checkada_no' => (string) (1000 + $index), 'dv_number' => (string) (100000 + $index), 'payee' => 'Test Payee'];
            if ($index !== 2) {
                $details[$id]['amount'] = $amount;
            }
        }
        $batch = ['batch_id' => (string) Str::uuid(), 'year' => 2026, 'type' => 'ADA', 'month' => 10, 'files' => $files];
        $payload = ['batch_id' => $batch['batch_id']] + ($packed ? ['voucher_details' => json_encode($details)] : ['vouchers' => $details]);
        $this->withSession(['voucher_upload' => $batch])->post(route('vouchers.finalize'), $payload)
            ->assertRedirect(route('vouchers.index'))->assertSessionHasNoErrors();
        $this->assertSame(4, Voucher::count());
        $this->assertSame(3, Voucher::whereNull('amount')->count());
        $this->assertSame('123.45', Voucher::where('dv_number', '100003')->firstOrFail()->amount);
        $this->assertCount(4, Storage::disk('local')->allFiles('vouchers'));
        $this->assertSame([], Storage::disk('local')->allFiles('staging'));
    }

    public function test_missing_amounts_are_flagged_filtered_and_cleared_when_added(): void
    {
        $missing = $this->voucher('100001', null);
        $this->voucher('100002', '75.00');
        $this->get(route('vouchers.index', ['amount_status' => 'missing']))->assertOk()
            ->assertViewHas('missingAmountCount', 1)->assertSee('Amount needed')->assertSee('100001')->assertDontSee('100002')->assertDontSee('PHP 0.00');
        $this->get(route('vouchers.show', $missing))->assertOk()->assertSee('Amount needed')->assertSee('Save amount')
            ->assertDontSee('@else')->assertDontSee('₱ 0.00');
        $path = $missing->file_path;
        $this->patch(route('vouchers.update-amount', $missing), ['amount' => '5250.25', 'payee' => 'Unwanted change'])
            ->assertRedirect(route('vouchers.show', $missing))->assertSessionHasNoErrors();
        $this->assertSame('5250.25', $missing->fresh()->amount);
        $this->assertSame('Test Payee', $missing->fresh()->payee);
        $this->assertSame($path, $missing->fresh()->file_path);
        Storage::disk('local')->assertExists($path);
        $this->get(route('vouchers.index', ['amount_status' => 'missing']))->assertOk()
            ->assertViewHas('missingAmountCount', 0)->assertDontSee('100001');
    }

    #[TestWith([''])]
    #[TestWith(['0'])]
    #[TestWith(['-1'])]
    #[TestWith(['abc'])]
    #[TestWith(['10000000000000'])]
    #[TestWith(['0.001'])]
    public function test_invalid_later_amounts_leave_the_voucher_flagged(string $amount): void
    {
        $voucher = $this->voucher('100001', null);
        $this->patchJson(route('vouchers.update-amount', $voucher), ['amount' => $amount])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertNull($voucher->fresh()->amount);
    }

    public function test_staff_and_guests_cannot_add_amounts(): void
    {
        $voucher = $this->voucher('100001', null);
        auth()->user()->forceFill(['is_admin' => false])->save();
        $this->get(route('vouchers.show', $voucher))->assertOk()->assertSee('Amount needed')->assertDontSee('Save amount');
        $this->patchJson(route('vouchers.update-amount', $voucher), ['amount' => '10.00'])->assertForbidden();
        auth()->logout();
        $this->patch(route('vouchers.update-amount', $voucher), ['amount' => '10.00'])->assertRedirect(route('login'));
        $this->assertNull($voucher->fresh()->amount);
    }

    public function test_missing_filter_paginates_and_keeps_a_global_reminder(): void
    {
        for ($number = 100001; $number <= 100021; $number++) {
            $this->voucher((string) $number, null);
        }
        $this->voucher('100022', '80.00');
        $this->get(route('vouchers.index', ['amount_status' => 'missing', 'year' => 2026, 'type' => 'ADA', 'month' => 10, 'page' => 2]))
            ->assertOk()->assertViewHas('missingAmountCount', 21)->assertSee('100021')->assertDontSee('100022')
            ->assertSee('amount_status=missing');
        $this->get(route('vouchers.index', ['q' => 'not a payee']))->assertOk()->assertViewHas('missingAmountCount', 21);
    }

    public function test_admin_can_correct_an_existing_amount_without_changing_the_pdf(): void
    {
        $voucher = $this->voucher('100001', '123.45');
        $path = $voucher->file_path;
        $bytes = Storage::disk('local')->get($path);
        $this->get(route('vouchers.show', $voucher))->assertOk()->assertSee('Edit amount')->assertSee('value="123.45"', false);
        $this->patch(route('vouchers.update-amount', $voucher), ['amount' => '456.78', 'payee' => 'Unwanted change'])
            ->assertRedirect(route('vouchers.show', $voucher))->assertSessionHasNoErrors();
        $this->assertSame('456.78', $voucher->fresh()->amount);
        $this->assertSame('Test Payee', $voucher->fresh()->payee);
        $this->assertSame($path, $voucher->fresh()->file_path);
        $this->assertSame($bytes, Storage::disk('local')->get($path));
        $this->get(route('vouchers.index'))->assertViewHas('missingAmountCount', 0);
        auth()->user()->forceFill(['is_admin' => false])->save();
        $this->get(route('vouchers.show', $voucher))->assertDontSee('Edit amount');
        $this->patchJson(route('vouchers.update-amount', $voucher), ['amount' => '10.00'])->assertForbidden();
        $this->assertSame('456.78', $voucher->fresh()->amount);
    }

    public function test_invalid_correction_keeps_the_previous_amount(): void
    {
        $voucher = $this->voucher('100001', '123.45');
        $this->from(route('vouchers.show', $voucher))->patch(route('vouchers.update-amount', $voucher), ['amount' => '-1'])
            ->assertRedirect(route('vouchers.show', $voucher))->assertSessionHasErrors('amount');
        $this->assertSame('123.45', $voucher->fresh()->amount);
    }

    public function test_nullable_migration_preserves_existing_amounts_and_refuses_lossy_rollback(): void
    {
        $migration = require database_path('migrations/2026_10_28_065335_make_voucher_amount_nullable.php');
        $migration->down();
        $existing = $this->voucher('100001', '123.45');
        $migration->up();
        $this->assertSame('123.45', $existing->fresh()->amount);
        $missing = $this->voucher('100002', null);
        try {
            $migration->down();
            $this->fail('Rollback must not replace missing amounts with zero.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Enter all missing voucher amounts', $exception->getMessage());
            $this->assertNull($missing->fresh()->amount);
            $this->assertSame('123.45', $existing->fresh()->amount);
        }
    }

    private function voucher(string $number, ?string $amount): Voucher
    {
        $path = "vouchers/2026/ADA/10/$number.pdf";
        Storage::disk('local')->put($path, '%PDF-1.4 sample');

        return Voucher::create(['checkada_no' => $number, 'dv_number' => $number, 'payee' => 'Test Payee', 'amount' => $amount,
            'year' => 2026, 'type' => 'ADA', 'month' => 10, 'file_path' => $path, 'uploaded_by' => auth()->id()]);
    }
}
