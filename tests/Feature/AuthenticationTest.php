<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_credentials_sign_in_and_logout_removes_access(): void
    {
        $user = $this->account();
        $this->post('/login', ['username' => $user->username, 'password' => 'CurrentPass123'])->assertRedirect(route('vouchers.index'));
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->get(route('vouchers.index'))->assertRedirect(route('login'));
    }

    public function test_incorrect_credentials_do_not_sign_in(): void
    {
        $user = $this->account();
        $this->post('/login', ['username' => $user->username, 'password' => 'wrong'])->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_repeated_failed_logins_are_rate_limited(): void
    {
        $user = $this->account();
        foreach (range(1, 5) as $attempt) {
            $this->post('/login', ['username' => $user->username, 'password' => 'wrong'])->assertSessionHasErrors('username');
        }
        $this->post('/login', ['username' => $user->username, 'password' => 'CurrentPass123'])
            ->assertSessionHasErrors(['username' => 'Too many sign-in attempts. Please try again later.']);
        $this->assertGuest();
    }

    public function test_temporary_password_must_be_changed_before_opening_vouchers(): void
    {
        $user = $this->account(true);
        $this->actingAs($user)->get(route('vouchers.index'))->assertRedirect(route('profile.edit'));
        $this->put(route('profile.update'), ['current_password' => 'CurrentPass123', 'password' => 'UpdatedPass123', 'password_confirmation' => 'UpdatedPass123'])
            ->assertRedirect(route('vouchers.index'));
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check('UpdatedPass123', $user->fresh()->password));
        $this->get(route('vouchers.index'))->assertOk();
    }

    public function test_wrong_current_password_cannot_change_the_password(): void
    {
        $user = $this->account();
        $this->actingAs($user)->put(route('profile.update'), ['current_password' => 'wrong', 'password' => 'UpdatedPass123', 'password_confirmation' => 'UpdatedPass123'])
            ->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('CurrentPass123', $user->fresh()->password));
    }

    public function test_mismatched_confirmation_cannot_change_the_password(): void
    {
        $user = $this->account();
        $this->actingAs($user)->put(route('profile.update'), ['current_password' => 'CurrentPass123', 'password' => 'UpdatedPass123', 'password_confirmation' => 'MismatchPass123'])
            ->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('CurrentPass123', $user->fresh()->password));
    }

    private function account(bool $temporary = false): User
    {
        $user = User::create(['username' => 'auth-tester', 'name' => 'Auth Tester', 'password' => 'CurrentPass123']);
        $user->forceFill(['must_change_password' => $temporary])->save();

        return $user;
    }
}
