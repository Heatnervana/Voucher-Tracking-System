<?php

namespace Tests\Feature;

use App\ActivityRecorder;
use App\Jobs\ReadSavedVoucherAmount;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherYear;
use App\PdfAmountReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ActivityHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_recorder_keeps_identity_and_whitelisted_changes_without_credentials(): void
    {
        $admin = User::factory()->administrator()->create(['name' => 'Original Admin']);
        $account = User::factory()->create(['name' => 'Employee']);
        $this->actingAs($admin);

        app(ActivityRecorder::class)->record($account, 'account.updated',
            ['name' => 'Employee', 'password' => 'secret-old', 'remember_token' => 'token-old'],
            ['name' => 'Updated Employee', 'password' => 'secret-new', 'session_id' => 'session-secret']);
        $admin->delete();

        $entry = ActivityLog::where('action', 'account.updated')->firstOrFail();
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertSame('Original Admin ('.$admin->username.')', $entry->actor_label);
        $this->assertSame($admin->id, $entry->actor->id);
        $this->assertSame(['name' => 'Employee'], $entry->before);
        $this->assertSame(['name' => 'Updated Employee'], $entry->after);
        $this->assertStringNotContainsString('secret', $entry->toJson());
    }

    public function test_account_actions_are_audited_without_passwords_or_session_tokens(): void
    {
        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin);
        $account = User::factory()->create(['name' => 'Audit Employee']);

        $this->patch('/accounts/'.$account->id, ['name' => $account->name, 'username' => $account->username, 'role' => 'administrator'])->assertRedirect();
        $this->put('/accounts/'.$account->id.'/password', ['password' => 'TemporaryPassword123!', 'password_confirmation' => 'TemporaryPassword123!'])->assertRedirect();
        $this->delete('/accounts/'.$account->id)->assertRedirect('/accounts');

        foreach (['account.created', 'account.role_changed', 'account.password_reset', 'account.deleted'] as $action) {
            $this->assertDatabaseHas('activity_logs', ['action' => $action, 'actor_id' => $admin->id, 'subject_id' => $account->id]);
        }
        $reset = ActivityLog::where('action', 'account.password_reset')->firstOrFail();
        $this->assertSame([], $reset->before);
        $this->assertSame([], $reset->after);
        $log = ActivityLog::all()->toJson();
        $this->assertStringNotContainsString('TemporaryPassword123!', $log);
        $this->assertStringNotContainsString($account->password, $log);
        $this->assertStringNotContainsString($account->remember_token, $log);
    }

    public function test_login_success_and_failure_capture_ip_without_treating_failed_user_as_actor(): void
    {
        $account = User::factory()->create(['username' => 'audit-login']);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.5'])->post('/login', ['username' => $account->username, 'password' => 'invalid-secret'])->assertSessionHasErrors('username');
        $this->post('/login', ['username' => $account->username, 'password' => 'PasswordTest123!'])->assertRedirect();

        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.failed', 'actor_id' => null, 'subject_id' => $account->id, 'ip_address' => '192.0.2.5']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.login', 'actor_id' => $account->id, 'subject_id' => $account->id]);
        $this->assertStringNotContainsString('invalid-secret', ActivityLog::all()->toJson());
        $this->assertStringNotContainsString('PasswordTest123!', ActivityLog::all()->toJson());
    }

    public function test_voucher_upload_rename_and_amount_changes_are_audited(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        $voucher = $this->voucher();

        DB::transaction(function () use ($voucher): void {
            $voucher->update(['file_path' => 'vouchers/renamed.pdf']);
            $voucher->update(['amount' => '125.50', 'amount_status' => 'ready']);
        });

        $this->assertDatabaseHas('activity_logs', ['action' => 'voucher.uploaded', 'subject_id' => $voucher->id]);
        $renamed = ActivityLog::where('action', 'voucher.renamed')->firstOrFail();
        $this->assertSame(['file_name' => 'original.pdf'], $renamed->before);
        $this->assertSame(['file_name' => 'renamed.pdf'], $renamed->after);
        $amount = ActivityLog::where('action', 'voucher.amount_changed')->firstOrFail();
        $this->assertSame(['amount' => null], $amount->before);
        $this->assertSame(['amount' => '125.50'], $amount->after);
    }

    public function test_rolled_back_changes_do_not_leave_audit_entries(): void
    {
        $account = User::factory()->create();
        try {
            DB::transaction(function () use ($account): void {
                $account->update(['name' => 'Rolled back name']);
                throw new RuntimeException('Roll back');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame($account->name, 'Rolled back name');
        $this->assertNotSame('Rolled back name', $account->fresh()->name);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'account.updated']);
    }

    public function test_ocr_records_one_system_event_and_stale_ocr_records_none(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->administrator()->create());
        $voucher = $this->voucher();
        $reader = $this->mock(PdfAmountReader::class);
        $reader->shouldReceive('read')->once()->andReturn('99.50');

        (new ReadSavedVoucherAmount($voucher->id))->handle($reader);
        (new ReadSavedVoucherAmount($voucher->id))->handle($reader);

        $entry = ActivityLog::where('action', 'voucher.amount_read')->sole();
        $this->assertNull($entry->actor_id);
        $this->assertSame('System', $entry->actor_label);
        $this->assertSame('99.50', $entry->after['amount']);
        $this->assertNull($entry->ip_address);
    }

    public function test_activity_history_is_read_only_and_administrator_only(): void
    {
        $this->get('/activity')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get('/activity')->assertForbidden();
        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin)->get('/activity')->assertOk()->assertSee('Activity history')->assertSee('Asia/Singapore');
        $this->post('/activity')->assertMethodNotAllowed();
        $this->delete('/activity/1')->assertNotFound();
    }

    public function test_history_filters_actions_and_shows_local_times_and_captured_names(): void
    {
        $this->travelTo('2026-11-05 01:30:00');
        $admin = User::factory()->administrator()->create(['name' => 'History Administrator']);
        $this->actingAs($admin);
        $account = User::factory()->create(['name' => 'Filter Employee']);
        app(ActivityRecorder::class)->record($account, 'account.updated', ['name' => 'Before'], ['name' => 'After']);

        $this->get('/activity?action=account.updated&q=Filter')->assertOk()
            ->assertSee('05 Nov 2026, 09:30:00')->assertSee('History Administrator')->assertSee('Before')->assertSee('After')
            ->assertDontSee('Account created</strong>', false);
        $this->get('/activity?action=untrusted')->assertSessionHasErrors('action');
    }

    public function test_rename_captures_changed_metadata_and_same_filename_repairs_are_not_missed(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        $voucher = $this->voucher();

        $voucher->update(['file_path' => 'vouchers/renamed.pdf', 'dv_number' => 'DV-RENAMED', 'payee' => 'Renamed Payee']);
        $voucher->update(['dv_number' => 'DV-REPAIRED']);

        $rename = ActivityLog::where('action', 'voucher.renamed')->sole();
        $this->assertSame('DV-AUDIT', $rename->before['dv_number']);
        $this->assertSame('DV-RENAMED', $rename->after['dv_number']);
        $this->assertSame('Renamed Payee', $rename->after['payee']);
        $repair = ActivityLog::where('action', 'voucher.details_changed')->sole();
        $this->assertSame(['dv_number' => 'DV-RENAMED'], $repair->before);
        $this->assertSame(['dv_number' => 'DV-REPAIRED'], $repair->after);
    }

    public function test_logout_records_the_signed_in_account(): void
    {
        $account = User::factory()->create();

        $this->actingAs($account)->post('/logout')->assertRedirect(route('login'));

        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.logout', 'actor_id' => $account->id, 'subject_id' => $account->id]);
    }

    public function test_recycle_lifecycle_records_each_action_once_without_bookkeeping_events(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        $voucher = $this->voucher();

        DB::transaction(function () use ($voucher): void {
            $voucher->forceFill(['deleted_by' => auth()->id(), 'amount_status' => 'manual', 'amount_read_token' => 'cancelled'])->save();
            $voucher->delete();
            $voucher->forceFill(['deleted_by' => null])->restore();
            $voucher->forceDelete();
        });

        $this->assertSame(['voucher.uploaded', 'voucher.deleted', 'voucher.restored', 'voucher.purged'], ActivityLog::where('subject_type', 'voucher')->orderBy('id')->pluck('action')->all());
    }

    public function test_ocr_does_not_audit_an_amount_overridden_during_reading(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->administrator()->create());
        $voucher = $this->voucher();
        $reader = $this->mock(PdfAmountReader::class);
        $reader->shouldReceive('read')->once()->andReturnUsing(function () use ($voucher): string {
            $voucher->update(['amount' => '88.00', 'amount_status' => 'ready']);

            return '99.50';
        });

        (new ReadSavedVoucherAmount($voucher->id))->handle($reader);

        $this->assertDatabaseMissing('activity_logs', ['action' => 'voucher.amount_read']);
        $this->assertSame('88.00', $voucher->fresh()->amount);
    }

    public function test_unknown_login_attempt_records_only_the_submitted_username(): void
    {
        $this->post('/login', ['username' => 'missing.employee', 'password' => 'never-record-this', 'remember_token' => 'untrusted-token'])->assertSessionHasErrors('username');

        $entry = ActivityLog::where('action', 'auth.failed')->sole();
        $this->assertNull($entry->actor_id);
        $this->assertNull($entry->subject_id);
        $this->assertSame('missing.employee', $entry->subject_label);
        $this->assertSame([], $entry->after);
        $this->assertStringNotContainsString('never-record-this', $entry->toJson());
        $this->assertStringNotContainsString('untrusted-token', $entry->toJson());
    }

    public function test_failed_audit_write_rolls_back_the_ocr_amount_update(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->administrator()->create());
        $voucher = $this->voucher();
        $reader = $this->mock(PdfAmountReader::class);
        $reader->shouldReceive('read')->once()->andReturn('99.50');
        ActivityLog::creating(function (ActivityLog $entry): void {
            if ($entry->action === 'voucher.amount_read') {
                throw new RuntimeException('Audit unavailable');
            }
        });

        try {
            (new ReadSavedVoucherAmount($voucher->id))->handle($reader);
            $this->fail('The unavailable audit store must stop the update.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }

        $this->assertNull($voucher->fresh()->amount);
        $this->assertSame('pending', $voucher->fresh()->amount_status);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'voucher.amount_read']);
    }

    public function test_history_search_applies_a_single_zero_search_term(): void
    {
        $this->actingAs(User::factory()->administrator()->create(['name' => 'Administrator', 'username' => 'history.admin']));
        $matching = User::factory()->create(['name' => 'Employee Zero', 'username' => 'employee.0']);
        User::factory()->create(['name' => 'Other Employee', 'username' => 'employee.other']);

        $this->get('/activity?q=0&action=account.created')->assertOk()->assertViewHas('activities', fn ($activities): bool => $activities->total() === 1 && $activities->first()->subject_id === $matching->id);
    }

    private function voucher(): Voucher
    {
        VoucherYear::firstOrCreate(['year' => 2026]);

        return Voucher::create(['checkada_no' => 'CHECK-AUDIT', 'dv_number' => 'DV-AUDIT', 'payee' => 'Audit Payee', 'amount' => null, 'amount_status' => 'pending', 'year' => 2026, 'type' => 'LBP', 'month' => 1, 'file_path' => 'vouchers/original.pdf', 'uploaded_by' => auth()->id()]);
    }
}
