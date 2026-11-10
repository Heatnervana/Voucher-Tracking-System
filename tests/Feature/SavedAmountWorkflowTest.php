<?php

namespace Tests\Feature;

use App\Jobs\ReadSavedVoucherAmount;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SavedAmountWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_before_ocr_finishes_queues_reading_from_saved_storage(): void
    {
        Queue::fake([ReadSavedVoucherAmount::class]);
        $this->saveBlank('pending');
        $voucher = Voucher::sole();
        $this->assertNull($voucher->amount);
        $this->assertSame('pending', $voucher->amount_status);
        Storage::disk('local')->assertExists($voucher->file_path);
        Queue::assertPushed(ReadSavedVoucherAmount::class, fn ($job) => $job->voucherId === $voucher->id);
        $this->get(route('vouchers.index'))->assertOk()->assertSee('Reading amount')->assertViewHas('missingAmountCount', 0);
        $this->get(route('vouchers.index', ['amount_status' => 'missing']))->assertViewHas('vouchers', fn ($rows) => $rows->isEmpty());
    }

    public function test_ready_ocr_result_is_saved_even_if_the_browser_has_not_received_it(): void
    {
        Queue::fake([ReadSavedVoucherAmount::class]);
        $this->saveBlank('ready', '5250.00');
        $this->assertSame('5250.00', Voucher::sole()->amount);
        $this->assertSame('ready', Voucher::sole()->amount_status);
        Queue::assertNotPushed(ReadSavedVoucherAmount::class);
    }

    public function test_unreadable_ocr_result_is_flagged_without_repeating_the_failed_read(): void
    {
        Queue::fake([ReadSavedVoucherAmount::class]);
        $this->saveBlank('manual');
        $this->assertSame('manual', Voucher::sole()->amount_status);
        Queue::assertNotPushed(ReadSavedVoucherAmount::class);
        $this->get(route('profile.edit'))->assertSee('Notifications: 1 voucher needs an amount');
    }

    public function test_queue_failure_keeps_saved_pdf_and_flags_manual_input(): void
    {
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Queue unavailable'));
        $this->saveBlank('pending');
        $voucher = Voucher::sole();
        $this->assertSame('manual', $voucher->amount_status);
        Storage::disk('local')->assertExists($voucher->file_path);
        $this->get(route('profile.edit'))->assertSee('Notifications: 1 voucher needs an amount');
    }

    public function test_status_polling_reports_manual_only_in_the_bell_and_requires_login(): void
    {
        Queue::fake([ReadSavedVoucherAmount::class]);
        $this->saveBlank('pending');
        $voucher = Voucher::sole();
        $url = '/vouchers/amount-status?ids[]='.$voucher->id;
        $this->getJson($url)->assertOk()->assertHeaderContains('Cache-Control', 'no-store')
            ->assertJsonPath('manual_count', 0)->assertJsonPath('vouchers.0.status', 'pending');
        $voucher->update(['amount_status' => 'manual']);
        $this->getJson($url)->assertJsonPath('manual_count', 1)->assertJsonPath('vouchers.0.status', 'manual');
        $this->patch(route('vouchers.update-amount', $voucher), ['amount' => '72.45'])->assertRedirect();
        $this->assertSame('ready', $voucher->fresh()->amount_status);
        $this->getJson($url)->assertJsonPath('manual_count', 0)->assertJsonPath('vouchers.0.amount', '72.45');
        auth()->user()->forceFill(['is_admin' => false])->save();
        $this->getJson($url)->assertOk()->assertJsonPath('manual_count', null)->assertJsonPath('vouchers.0.amount', '72.45');
        auth()->logout();
        $this->getJson($url)->assertUnauthorized();
    }

    private function saveBlank(string $status, ?string $amount = null): void
    {
        Storage::fake('local');
        $user = User::create(['username' => 'saved-workflow', 'name' => 'Tester', 'password' => 'PasswordTest123!']);
        $user->forceFill(['is_admin' => true, 'must_change_password' => false])->save();
        $this->actingAs($user);
        VoucherYear::create(['year' => 2026]);
        $id = (string) Str::uuid();
        $batchId = (string) Str::uuid();
        $path = 'staging/'.$id.'.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 test');
        Cache::put('voucher-amount:'.$id, ['status' => $status, 'amount' => $amount], 3600);
        $this->withSession(['voucher_upload' => ['batch_id' => $batchId, 'year' => 2026, 'type' => 'ADA', 'month' => 10,
            'files' => [$id => ['path' => $path, 'original_name' => 'sample.pdf']],
        ]])->post(route('vouchers.finalize'), ['batch_id' => $batchId, 'vouchers' => [$id => [
            'checkada_no' => '1000', 'dv_number' => '100001', 'payee' => 'TEST PAYEE', 'amount' => '',
        ]]])->assertRedirect(route('vouchers.index'));
    }
}
