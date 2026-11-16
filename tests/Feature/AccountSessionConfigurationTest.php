<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AccountSessionConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.account_sessions' => config('database.connections.sqlite'),
            'session.driver' => 'database',
            'session.connection' => 'account_sessions',
            'session.table' => 'staff_sessions',
        ]);
        Schema::connection('account_sessions')->create('staff_sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity');
        });
    }

    #[TestWith(['role'])]
    #[TestWith(['password'])]
    #[TestWith(['delete'])]
    public function test_account_changes_revoke_only_the_target_users_configured_sessions(string $operation): void
    {
        $admin = User::factory()->administrator()->create();
        $account = User::factory()->create();
        $other = User::factory()->create();
        $token = $account->remember_token;
        $this->seedSessions($account, $other);

        $this->actingAs($admin);
        $this->changeAccount($operation, $account)->assertRedirect();

        $this->assertDatabaseMissing('staff_sessions', ['user_id' => $account->id], 'account_sessions');
        $this->assertDatabaseHas('staff_sessions', ['id' => 'unrelated', 'user_id' => $other->id], 'account_sessions');
        $this->assertDatabaseHas('sessions', ['id' => 'default-decoy', 'user_id' => $account->id]);
        $account->refresh();
        $this->assertNotSame($token, $account->remember_token);
        $this->assertSame($operation === 'role', $account->is_admin);
        $this->assertSame($operation === 'delete', $account->trashed());
        $this->assertSame($operation === 'password', Hash::check('ResetPassword123!', $account->password));
    }

    #[TestWith(['role'])]
    #[TestWith(['password'])]
    #[TestWith(['delete'])]
    public function test_failed_configured_session_revocation_rolls_back_the_account_change(string $operation): void
    {
        $admin = User::factory()->administrator()->create();
        $account = User::factory()->create();
        $other = User::factory()->create();
        $this->seedSessions($account, $other);
        $before = $account->fresh()->getAttributes();
        DB::connection('account_sessions')->unprepared("CREATE TRIGGER reject_revocation BEFORE DELETE ON staff_sessions BEGIN SELECT RAISE(FAIL, 'Session revocation unavailable'); END");

        $this->actingAs($admin);
        $this->changeAccount($operation, $account)->assertServerError();

        $this->assertSame($before, $account->fresh()->getAttributes());
        $this->assertDatabaseHas('staff_sessions', ['id' => 'target', 'user_id' => $account->id], 'account_sessions');
        $this->assertDatabaseHas('staff_sessions', ['id' => 'unrelated', 'user_id' => $other->id], 'account_sessions');
        $this->assertDatabaseHas('sessions', ['id' => 'default-decoy', 'user_id' => $account->id]);
    }

    private function seedSessions(User $account, User $other): void
    {
        foreach (['target' => $account, 'unrelated' => $other] as $id => $user) {
            DB::connection('account_sessions')->table('staff_sessions')->insert([
                'id' => $id, 'user_id' => $user->id, 'payload' => base64_encode('{}'), 'last_activity' => time(),
            ]);
        }
        DB::table('sessions')->insert([
            'id' => 'default-decoy', 'user_id' => $account->id, 'payload' => base64_encode('{}'), 'last_activity' => time(),
        ]);
    }

    private function changeAccount(string $operation, User $account): TestResponse
    {
        return match ($operation) {
            'role' => $this->patch(route('accounts.update', $account), [
                'name' => 'Promoted Employee', 'username' => $account->username, 'role' => 'administrator',
            ]),
            'password' => $this->put(route('accounts.password', $account), [
                'password' => 'ResetPassword123!', 'password_confirmation' => 'ResetPassword123!',
            ]),
            'delete' => $this->delete(route('accounts.destroy', $account)),
        };
    }
}
