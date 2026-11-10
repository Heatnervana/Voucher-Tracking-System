<?php

namespace Tests\Feature;

use App\Jobs\ReadSavedVoucherAmount;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherYear;
use App\PdfAmountReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class VoucherAmountRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $admin = User::create(['username' => 'retry-admin', 'name' => 'Administrator', 'password' => 'TestPassword123!']);
        $admin->forceFill(['is_admin' => true, 'must_change_password' => false])->save();
        $this->actingAs($admin);
        VoucherYear::create(['year' => 2026]);
    }

    #[TestWith([0])]
    #[TestWith([1])]
    public function test_missing_amounts_offer_manual_entry_without_a_second_ocr_attempt(int $previousRetries): void
    {
        $blank = $this->voucher('100003');
        $blank->update(['amount_retry_count' => $previousRetries]);
        $this->get(route('vouchers.index'))->assertOk()->assertDontSee('Retry OCR')->assertDontSee('retry-selected-form', false)
            ->assertDontSee('Enter amount manually')->assertDontSee('#amount-editor', false)->assertSee('Open PDF');
        $this->get(route('vouchers.show', $blank))->assertOk()->assertDontSee('Retry OCR')
            ->assertDontSee('second read')->assertSee('Add amount');
        Queue::fake([ReadSavedVoucherAmount::class]);
        $this->postJson('/vouchers/bulk/retry-amount', ['voucher_ids' => [$blank->id]])->assertNotFound();
        Queue::assertNothingPushed();
        $this->assertSame('manual', $blank->fresh()->amount_status);
    }

    public function test_stale_worker_cannot_update_a_newer_attempt(): void
    {
        $blank = $this->voucher('100001');
        $blank->update(['amount_status' => 'pending', 'amount_read_token' => 'new-attempt']);
        $this->mock(PdfAmountReader::class)->shouldNotReceive('read');
        $stale = new ReadSavedVoucherAmount($blank->id, 'old-attempt');
        $stale->handle(app(PdfAmountReader::class));
        $stale->failed(new \RuntimeException('Late failure'));
        $this->assertSame('pending', $blank->fresh()->amount_status);
        $this->assertNull($blank->fresh()->amount);
    }

    public function test_staff_and_guests_cannot_retry(): void
    {
        $blank = $this->voucher('100001');
        auth()->user()->forceFill(['is_admin' => false])->save();
        $this->postJson('/vouchers/bulk/retry-amount', ['voucher_ids' => [$blank->id]])->assertNotFound();
        auth()->logout();
        $this->post('/vouchers/bulk/retry-amount', ['voucher_ids' => [$blank->id]])->assertNotFound();
        $this->assertSame('manual', $blank->fresh()->amount_status);
    }

    private function voucher(string $number, ?string $amount = null): Voucher
    {
        $path = 'vouchers/2026/ADA/06/'.$number.'.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 test');

        return Voucher::create(['checkada_no' => $number, 'dv_number' => $number, 'payee' => 'Test Payee', 'amount' => $amount,
            'amount_status' => $amount === null ? 'manual' : 'ready', 'year' => 2026, 'type' => 'ADA', 'month' => 6,
            'file_path' => $path, 'uploaded_by' => auth()->id()]);
    }
}
