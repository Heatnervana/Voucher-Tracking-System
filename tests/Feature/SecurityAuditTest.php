<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_responses_have_a_server_generated_correlation_id_instead_of_client_input(): void
    {
        Log::spy();
        $response = $this->withHeader('X-Request-ID', 'untrusted-client-value')->get(route('login'))->assertOk();
        $requestId = $response->headers->get('X-Request-ID');
        $this->assertTrue(Str::isUuid($requestId));
        $this->assertNotSame('untrusted-client-value', $requestId);
        Log::shouldHaveReceived('withContext')->with(['request_id' => $requestId])->once();
    }

    public function test_unexpected_errors_keep_details_private_and_return_a_correlation_id(): void
    {
        config(['app.debug' => false]);
        Route::get('/security-audit-error', function (): never {
            throw new RuntimeException('internal-audit-error-marker');
        });
        $response = $this->get('/security-audit-error')->assertStatus(500)
            ->assertDontSee('internal-audit-error-marker')->assertDontSee('RuntimeException');
        $this->assertTrue(Str::isUuid($response->headers->get('X-Request-ID')));
    }

    public function test_staff_pdf_access_records_identity_without_document_contents_or_storage_paths(): void
    {
        $voucher = $this->voucher();
        $staff = User::factory()->create();
        $this->actingAs($staff)->withServerVariables(['REMOTE_ADDR' => '192.0.2.25'])
            ->get(route('vouchers.pdf', $voucher))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(1, ActivityLog::where('action', 'voucher.viewed')->count());
        $entry = ActivityLog::where('action', 'voucher.viewed')->sole();
        $this->assertSame($staff->id, $entry->actor_id);
        $this->assertSame($voucher->id, $entry->subject_id);
        $this->assertSame('192.0.2.25', $entry->ip_address);
        $this->assertSame([], $entry->before);
        $this->assertSame([], $entry->after);
        $this->assertStringNotContainsString('sensitive-document-bytes', $entry->toJson());
        $this->assertStringNotContainsString('vouchers/', $entry->toJson());
    }

    public function test_admin_pdf_download_is_audited_and_remains_authorized(): void
    {
        $voucher = $this->voucher();
        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin)->get(route('vouchers.download', $voucher))->assertOk();
        $this->assertDatabaseHas('activity_logs', ['action' => 'voucher.downloaded', 'actor_id' => $admin->id, 'subject_id' => $voucher->id]);
    }

    public function test_recycled_pdf_preview_is_audited(): void
    {
        $voucher = $this->voucher();
        $voucher->delete();
        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin)->get(route('recycle-bin.preview', $voucher))->assertOk();
        $this->assertDatabaseHas('activity_logs', ['action' => 'voucher.viewed', 'actor_id' => $admin->id, 'subject_id' => $voucher->id]);
    }

    public function test_denied_staff_mutation_records_route_without_credentials_or_changing_the_voucher(): void
    {
        $voucher = $this->voucher();
        $staff = User::factory()->create();
        $this->actingAs($staff)->patchJson(route('vouchers.rename', $voucher), ['filename' => 'tampered.pdf', 'password' => 'audit-secret-sentinel'])->assertForbidden();
        $this->assertSame(1, ActivityLog::where('action', 'authorization.denied')->count());
        $entry = ActivityLog::where('action', 'authorization.denied')->sole();
        $this->assertSame($staff->id, $entry->actor_id);
        $this->assertSame('vouchers.rename', $entry->subject_label);
        $this->assertSame('route', $entry->subject_type);
        $this->assertStringNotContainsString('audit-secret-sentinel', $entry->toJson());
        $this->assertStringNotContainsString('tampered.pdf', $entry->toJson());
        $this->assertSame($voucher->file_path, $voucher->fresh()->file_path);
    }

    public function test_guest_missing_pdf_and_head_requests_do_not_create_pdf_access_entries(): void
    {
        $voucher = $this->voucher();
        $this->get(route('vouchers.pdf', $voucher))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create());
        $this->head(route('vouchers.pdf', $voucher))->assertOk();
        $this->get(route('vouchers.pdf', $voucher->id + 1))->assertNotFound();
        $this->assertSame(0, ActivityLog::whereIn('action', ['voucher.viewed', 'voucher.downloaded'])->count());
    }

    public function test_initial_pdf_range_is_audited(): void
    {
        $voucher = $this->voucher();
        $staff = User::factory()->create();
        $this->actingAs($staff)->withHeader('Range', 'bytes=0-20')->get(route('vouchers.pdf', $voucher))->assertOk();
        $this->assertDatabaseHas('activity_logs', ['action' => 'voucher.viewed', 'actor_id' => $staff->id, 'subject_id' => $voucher->id]);
    }

    public function test_noninitial_range_cannot_bypass_pdf_access_logging(): void
    {
        $voucher = $this->voucher();
        $staff = User::factory()->create();
        $this->actingAs($staff)->withHeader('Range', 'bytes=10-20')->get(route('vouchers.pdf', $voucher))->assertOk();
        $this->assertDatabaseHas('activity_logs', ['action' => 'voucher.viewed', 'actor_id' => $staff->id, 'subject_id' => $voucher->id]);
    }

    public function test_administrator_management_access_is_not_restricted_to_the_server_address(): void
    {
        $this->actingAs(User::factory()->administrator()->create())->withServerVariables(['REMOTE_ADDR' => '203.0.113.25'])
            ->get(route('accounts.index'))->assertOk();
        $this->get(route('vouchers.create'))->assertOk();
    }

    public function test_search_treats_sql_injection_text_as_a_literal_search_term(): void
    {
        $this->voucher();
        $this->actingAs(User::factory()->create())->get(route('vouchers.index', ['q' => "%' OR 1=1 --"]))
            ->assertOk()->assertDontSee('Audit Payee');
        $this->assertDatabaseCount('vouchers', 1);
    }

    public function test_library_escapes_stored_payee_markup(): void
    {
        $voucher = $this->voucher();
        $markup = '<img src=x onerror=alert(1)>';
        $voucher->update(['payee' => $markup]);
        $this->actingAs(User::factory()->create())->get(route('vouchers.index'))
            ->assertOk()->assertDontSee($markup, false)->assertSee(e($markup), false);
    }

    private function voucher(): Voucher
    {
        Storage::fake('local');
        VoucherYear::create(['year' => 2026]);
        Storage::disk('local')->put('vouchers/audit.pdf', "%PDF-1.4\nsensitive-document-bytes");

        return Voucher::create([
            'checkada_no' => '123', 'dv_number' => '456', 'payee' => 'Audit Payee',
            'year' => 2026, 'type' => 'CHECK', 'month' => 1, 'amount' => '100.00', 'file_path' => 'vouchers/audit.pdf',
            'uploaded_by' => User::factory()->administrator()->create()->id,
        ]);
    }
}
