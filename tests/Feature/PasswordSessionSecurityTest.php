<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PasswordSessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_change_rejects_another_browser_file_session_and_preserves_the_current_browser(): void
    {
        config(['session.driver' => 'file', 'session.files' => storage_path('framework/sessions')]);
        File::ensureDirectoryExists(config('session.files'));
        $user = User::factory()->create();

        $this->useSession(Str::random(40));
        $this->post('/login', ['username' => $user->username, 'password' => 'PasswordTest123!'])->assertRedirect();
        $firstBrowser = session()->getId();
        $this->useSession(Str::random(40));
        $this->post('/login', ['username' => $user->username, 'password' => 'PasswordTest123!'])->assertRedirect();
        $secondBrowser = session()->getId();

        $this->useSession($firstBrowser);
        $this->put(route('profile.update'), $this->passwordChange())->assertRedirect(route('vouchers.index'));
        $updatedBrowser = session()->getId();

        $this->useSession($updatedBrowser);
        $this->get(route('vouchers.index'))->assertOk();
        $this->useSession($secondBrowser);
        $this->get(route('vouchers.index'))->assertRedirect(route('login'));
    }

    #[TestWith(['sessions'])]
    #[TestWith(['password_change_sessions'])]
    public function test_password_change_revokes_legacy_sessions_and_preserves_other_accounts(string $table): void
    {
        config(['session.driver' => 'database', 'session.table' => $table]);
        if ($table !== 'sessions') {
            Schema::rename('sessions', $table);
        }
        $user = User::factory()->create(['must_change_password' => true]);
        $other = User::factory()->create();
        $currentId = $this->seedLegacySession($user);
        $oldOtherId = $this->seedLegacySession($user);
        $unrelatedId = $this->seedLegacySession($other);
        $rememberToken = $user->remember_token;

        $this->useSession($currentId);
        $this->put(route('profile.update'), $this->passwordChange())
            ->assertRedirect(route('vouchers.index'));
        $newId = session()->getId();

        $this->assertTrue(Hash::check('UpdatedPassword123!', $user->fresh()->password));
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertNotSame($rememberToken, $user->fresh()->remember_token);
        $this->assertNotSame($currentId, $newId);
        $this->assertDatabaseMissing($table, ['id' => $currentId]);
        $this->assertDatabaseMissing($table, ['id' => $oldOtherId]);
        $this->assertDatabaseHas($table, ['id' => $newId, 'user_id' => $user->id]);
        $this->assertDatabaseHas($table, ['id' => $unrelatedId, 'user_id' => $other->id]);

        $this->useSession($newId);
        $this->get(route('vouchers.index'))->assertOk();
        $this->useSession($oldOtherId);
        $this->get(route('vouchers.index'))->assertRedirect(route('login'));
        $this->useSession($unrelatedId);
        $this->get(route('vouchers.index'))->assertOk();
    }

    #[TestWith(['current_password', 'wrong', 'UpdatedPassword123!', 'UpdatedPassword123!'])]
    #[TestWith(['password', 'PasswordTest123!', 'UpdatedPassword123!', 'different'])]
    #[TestWith(['password', 'PasswordTest123!', 'PasswordTest123!', 'PasswordTest123!'])]
    public function test_failed_changes_preserve_credentials_and_other_sessions(string $error, string $current, string $password, string $confirmation): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['must_change_password' => true]);
        $currentId = $this->seedLegacySession($user);
        $otherId = $this->seedLegacySession($user);
        $hash = $user->password;
        $rememberToken = $user->remember_token;

        $this->useSession($currentId);
        $this->put(route('profile.update'), [
            'current_password' => $current,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ])->assertSessionHasErrors($error);

        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame($rememberToken, $user->fresh()->remember_token);
        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertDatabaseHas('sessions', ['id' => $currentId, 'user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['id' => $otherId, 'user_id' => $user->id]);
    }

    public function test_a_session_revocation_failure_rolls_back_the_password_change(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['must_change_password' => true]);
        $currentId = $this->seedLegacySession($user);
        $otherId = $this->seedLegacySession($user);
        $hash = $user->password;
        $rememberToken = $user->remember_token;
        DB::unprepared("CREATE TRIGGER reject_session_revocation BEFORE DELETE ON sessions BEGIN SELECT RAISE(FAIL, 'Session revocation unavailable'); END");

        $this->useSession($currentId);
        $this->put(route('profile.update'), $this->passwordChange())->assertServerError();

        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame($rememberToken, $user->fresh()->remember_token);
        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertDatabaseHas('sessions', ['id' => $currentId, 'user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['id' => $otherId, 'user_id' => $user->id]);
        $this->assertSame(0, ActivityLog::where('action', 'account.password_changed')->count());
    }

    private function seedLegacySession(User $user): string
    {
        $id = Str::random(40);
        DB::table(config('session.table'))->insert([
            'id' => $id,
            'user_id' => $user->id,
            'payload' => base64_encode(json_encode([auth()->getName() => $user->id, '_token' => Str::random(40)])),
            'last_activity' => time(),
        ]);

        return $id;
    }

    private function useSession(string $id): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->driver()->flush();
        $this->withCookie(config('session.cookie'), $id);
    }

    /** @return array{current_password: string, password: string, password_confirmation: string} */
    private function passwordChange(): array
    {
        return [
            'current_password' => 'PasswordTest123!',
            'password' => 'UpdatedPassword123!',
            'password_confirmation' => 'UpdatedPassword123!',
        ];
    }
}
