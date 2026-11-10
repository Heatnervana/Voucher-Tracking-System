<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['staff', false])]
    #[TestWith(['administrator', true])]
    public function test_admin_can_create_an_account_with_a_role_and_temporary_password(string $role, bool $isAdmin): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        $this->post('/accounts', $this->accountData($role))->assertRedirect('/accounts');
        $account = User::where('username', 'new.employee')->firstOrFail();
        $this->assertSame($isAdmin, $account->is_admin);
        $this->assertTrue($account->must_change_password);
        $this->assertTrue(Hash::check('TemporaryPass123!', $account->password));
        $this->assertNotSame('TemporaryPass123!', $account->password);
    }

    #[TestWith(['GET', '/accounts'])]
    #[TestWith(['GET', '/accounts/create'])]
    #[TestWith(['GET', '/accounts/2/edit'])]
    #[TestWith(['POST', '/accounts'])]
    #[TestWith(['PATCH', '/accounts/2'])]
    #[TestWith(['PUT', '/accounts/2/password'])]
    #[TestWith(['DELETE', '/accounts/2'])]
    public function test_staff_and_guests_cannot_manage_accounts(string $method, string $path): void
    {
        $this->json($method, $path, $this->accountData())->assertUnauthorized();
        $staff = User::factory()->create();
        User::factory()->create();
        $this->actingAs($staff);
        $this->json($method, $path, $this->accountData())->assertForbidden();
    }

    public function test_accounts_link_and_list_are_administrator_only(): void
    {
        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin)->get('/accounts')->assertOk()->assertSee('Create account')->assertSee($admin->username);
        $this->get(route('vouchers.index'))->assertSee('Accounts');
        $this->actingAs(User::factory()->create())->get(route('vouchers.index'))->assertDontSee('Accounts');
    }

    public function test_creation_validates_username_role_and_password_confirmation(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        $this->post('/accounts', $this->accountData() + ['is_admin' => true])->assertRedirect('/accounts');
        $this->assertFalse(User::where('username', 'new.employee')->firstOrFail()->is_admin);
        $this->post('/accounts', $this->accountData())->assertSessionHasErrors('username');
        $this->post('/accounts', array_replace($this->accountData(), ['username' => '../bad', 'role' => 'owner', 'password_confirmation' => 'different']))
            ->assertSessionHasErrors(['username', 'role', 'password']);
        $this->assertDatabaseCount('users', 2);
        $this->assertNull(session()->getOldInput('password'));
        $this->assertNull(session()->getOldInput('password_confirmation'));
    }

    public function test_a_created_account_must_change_its_password_before_using_the_app(): void
    {
        $this->actingAs(User::factory()->administrator()->create())->post('/accounts', $this->accountData())->assertRedirect('/accounts');
        $this->post('/logout');
        $this->post('/login', ['username' => 'new.employee', 'password' => 'TemporaryPass123!'])->assertRedirect();
        $this->get(route('vouchers.index'))->assertRedirect(route('profile.edit'));
        $this->get('/accounts')->assertRedirect(route('profile.edit'));
    }

    public function test_role_changes_revoke_existing_sessions_and_remember_tokens(): void
    {
        $admin = User::factory()->administrator()->create();
        $account = User::factory()->create();
        $token = $account->remember_token;
        $this->seedSession($account);
        $this->actingAs($admin)->patch('/accounts/'.$account->id, ['name' => 'Office Administrator', 'username' => $account->username, 'role' => 'administrator'])
            ->assertRedirect('/accounts/'.$account->id.'/edit');
        $this->assertTrue($account->fresh()->is_admin);
        $this->assertNotSame($token, $account->fresh()->remember_token);
        $this->assertDatabaseMissing('sessions', ['user_id' => $account->id]);
        $this->actingAs($account->fresh())->get('/accounts')->assertOk();
        $this->actingAs($admin)->patch('/accounts/'.$account->id, ['name' => 'Staff', 'username' => $account->username, 'role' => 'staff'])->assertSessionHasNoErrors();
        $this->actingAs($account->fresh())->get('/accounts')->assertForbidden();
    }

    public function test_password_reset_forces_a_password_change_and_revokes_sessions(): void
    {
        $admin = User::factory()->administrator()->create();
        $account = User::factory()->create();
        $token = $account->remember_token;
        $this->seedSession($account);
        $this->actingAs($admin)->put('/accounts/'.$account->id.'/password', ['password' => 'ResetPassword123!', 'password_confirmation' => 'ResetPassword123!'])
            ->assertRedirect('/accounts/'.$account->id.'/edit');
        $this->assertTrue(Hash::check('ResetPassword123!', $account->fresh()->password));
        $this->assertTrue($account->fresh()->must_change_password);
        $this->assertNotSame($token, $account->fresh()->remember_token);
        $this->assertDatabaseMissing('sessions', ['user_id' => $account->id]);
        $this->post('/logout');
        $this->post('/login', ['username' => $account->username, 'password' => 'ResetPassword123!'])->assertRedirect();
        $this->get(route('vouchers.index'))->assertRedirect(route('profile.edit'));
    }

    public function test_an_administrator_cannot_delete_or_demote_their_own_account(): void
    {
        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin)->delete('/accounts/'.$admin->id)->assertSessionHasErrors('account');
        $this->patch('/accounts/'.$admin->id, ['name' => $admin->name, 'username' => $admin->username, 'role' => 'staff'])->assertSessionHasErrors('role');
        $this->assertTrue($admin->fresh()->is_admin);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_invalid_password_reset_does_not_change_credentials(): void
    {
        $admin = User::factory()->administrator()->create();
        $account = User::factory()->create();
        $hash = $account->password;
        $this->actingAs($admin)->put('/accounts/'.$account->id.'/password', ['password' => 'short', 'password_confirmation' => 'different'])
            ->assertSessionHasErrors('password');
        $this->assertSame($hash, $account->fresh()->password);
        $this->put('/accounts/'.$admin->id.'/password', ['password' => 'ResetPassword123!', 'password_confirmation' => 'ResetPassword123!'])
            ->assertSessionHasErrors('password');
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_a_stale_administrator_cannot_create_accounts_after_losing_access(bool $deleted): void
    {
        $admin = User::factory()->administrator()->create();
        if ($deleted) {
            User::whereKey($admin->id)->delete();
        } else {
            User::whereKey($admin->id)->update(['is_admin' => false]);
        }
        $this->actingAs($admin)->postJson('/accounts', $this->accountData())->assertForbidden();
        $this->assertDatabaseMissing('users', ['username' => 'new.employee']);
    }

    public function test_deleting_an_uploader_preserves_vouchers_pdfs_and_uploader_history(): void
    {
        Storage::fake('local');
        $admin = User::factory()->administrator()->create();
        $account = User::factory()->create(['name' => 'Former Uploader']);
        $username = $account->username;
        VoucherYear::create(['year' => 2026]);
        $voucher = Voucher::create(['checkada_no' => '1000', 'dv_number' => '123456', 'payee' => 'Test Payee', 'amount' => '25.00',
            'year' => 2026, 'type' => 'CHECK', 'month' => 7, 'file_path' => 'vouchers/2026/CHECK/07/example.pdf', 'uploaded_by' => $account->id]);
        Storage::disk('local')->put($voucher->file_path, '%PDF original');
        $this->seedSession($account);
        $this->actingAs($admin)->delete('/accounts/'.$account->id)->assertRedirect('/accounts');
        $this->assertSoftDeleted('users', ['id' => $account->id]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $account->id]);
        $this->assertDatabaseHas('vouchers', ['id' => $voucher->id, 'uploaded_by' => $account->id]);
        $this->assertSame('%PDF original', Storage::disk('local')->get($voucher->file_path));
        $this->get(route('vouchers.show', $voucher))->assertOk()->assertSee('Former Uploader');
        $this->get('/accounts')->assertDontSee($username);
        $this->post('/logout');
        $this->post('/login', ['username' => $username, 'password' => 'PasswordTest123!'])->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    private function accountData(string $role = 'staff'): array
    {
        return ['name' => 'New Employee', 'username' => 'new.employee', 'role' => $role,
            'password' => 'TemporaryPass123!', 'password_confirmation' => 'TemporaryPass123!'];
    }

    private function seedSession(User $account): void
    {
        DB::table('sessions')->insert(['id' => 'test-account-'.$account->id, 'user_id' => $account->id, 'payload' => 'test', 'last_activity' => time()]);
    }
}
