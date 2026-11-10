<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MissingAmountBellTest extends TestCase
{
    use RefreshDatabase;

    public function test_bell_shows_the_global_count_and_links_to_missing_amounts_for_admins(): void
    {
        $this->signIn(true);
        $missing = $this->voucher('100001', null);
        $this->voucher('100002', null);
        $this->voucher('100003', '123.45');
        foreach ([route('profile.edit'), route('vouchers.show', $missing), route('vouchers.index', ['q' => 'no matching payee'])] as $url) {
            $this->get($url)->assertOk()->assertSee('id="amount-notification"', false)
                ->assertSee('Notifications: 2 vouchers need an amount')
                ->assertSee(route('vouchers.index', ['amount_status' => 'missing']))
                ->assertSee('class="notification-count"', false);
        }
    }

    public function test_staff_do_not_see_the_notification_bell(): void
    {
        $this->signIn(false);
        $missing = $this->voucher('100001', null);
        foreach ([route('profile.edit'), route('vouchers.show', $missing), route('vouchers.index')] as $url) {
            $this->get($url)->assertOk()->assertDontSee('id="amount-notification"', false)
                ->assertDontSee('class="notification-count"', false);
        }
    }

    public function test_bell_count_clears_after_amounts_are_completed(): void
    {
        $this->signIn(true);
        $voucher = $this->voucher('100001', null);
        $this->get(route('profile.edit'))->assertSee('Notifications: 1 voucher needs an amount');
        $this->patch(route('vouchers.update-amount', $voucher), ['amount' => '25.00'])->assertSessionHasNoErrors();
        $this->get(route('profile.edit'))->assertOk()->assertSee('Notifications: No vouchers need an amount')
            ->assertDontSee('class="notification-count"', false);
    }

    public function test_bell_is_hidden_until_first_password_change_and_for_guests(): void
    {
        $this->signIn(false);
        auth()->user()->forceFill(['must_change_password' => true])->save();
        $this->get(route('profile.edit'))->assertOk()->assertDontSee('id="amount-notification"', false);
        auth()->logout();
        $this->get(route('login'))->assertOk()->assertDontSee('id="amount-notification"', false);
    }

    private function signIn(bool $admin): void
    {
        $user = User::create(['username' => 'bell-tester', 'name' => 'Bell Tester', 'password' => 'TestPassword123!']);
        $user->forceFill(['is_admin' => $admin, 'must_change_password' => false])->save();
        $this->actingAs($user);
        VoucherYear::create(['year' => 2026]);
    }

    private function voucher(string $number, ?string $amount): Voucher
    {
        return Voucher::create(['checkada_no' => $number, 'dv_number' => $number, 'payee' => 'Test Payee', 'amount' => $amount,
            'year' => 2026, 'type' => 'ADA', 'month' => 10, 'file_path' => "vouchers/2026/ADA/10/$number.pdf", 'uploaded_by' => auth()->id()]);
    }
}
