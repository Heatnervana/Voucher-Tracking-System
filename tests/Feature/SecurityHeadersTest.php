<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Vite;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_sign_in_has_browser_security_headers_on_https(): void
    {
        $this->get('https://preview.example.test/login')
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "default-src 'self'; script-src 'self'; connect-src 'self'; img-src 'self'; frame-src 'self'; object-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'")
            ->assertHeaderContains('Cache-Control', 'private')
            ->assertHeaderContains('Cache-Control', 'no-store')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'same-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    #[TestWith(['http://preview.example.test/login'])]
    #[TestWith(['https://office.example.test:8443/login'])]
    public function test_hsts_is_omitted_on_http_and_the_custom_office_https_port(string $url): void
    {
        $this->get($url)->assertOk()->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_signed_out_json_response_has_security_headers(): void
    {
        $this->getJson('https://preview.example.test/vouchers/amount-status')
            ->assertUnauthorized()
            ->assertHeaderContains('Cache-Control', 'no-store')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    #[TestWith(['local', false])]
    #[TestWith(['production', true])]
    public function test_hot_assets_relax_resource_sources_only_in_local_development(string $environment, bool $restricted): void
    {
        $this->app['env'] = $environment;
        Vite::partialMock()->shouldReceive('isRunningHot')->andReturn(true);
        Route::get('/security-header-test', fn () => response('Development assets'));

        $response = $this->get('/security-header-test')->assertOk();

        if ($restricted) {
            $response->assertHeaderContains('Content-Security-Policy', "script-src 'self'");
        } else {
            $response->assertHeader('Content-Security-Policy', "base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
        }
    }

    public function test_private_html_and_saved_pdfs_are_not_stored_in_browser_caches(): void
    {
        $user = User::factory()->administrator()->create();
        $this->actingAs($user);
        VoucherYear::create(['year' => 2026]);
        Storage::fake('local');
        Storage::disk('local')->put('vouchers/private.pdf', '%PDF-1.4 private');
        $voucher = Voucher::create(['checkada_no' => '123', 'dv_number' => '456', 'payee' => 'Private payee',
            'amount' => '25.00', 'year' => 2026, 'type' => 'CHECK', 'month' => 7,
            'file_path' => 'vouchers/private.pdf', 'uploaded_by' => $user->id]);

        foreach ([route('vouchers.index'), route('vouchers.show', $voucher), route('accounts.index')] as $url) {
            $this->get($url)->assertOk()->assertHeaderContains('Cache-Control', 'private')->assertHeaderContains('Cache-Control', 'no-store');
        }
        foreach ([route('vouchers.pdf', $voucher), route('vouchers.download', $voucher)] as $url) {
            $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')
                ->assertHeaderContains('Cache-Control', 'no-store')->assertStreamedContent('%PDF-1.4 private');
        }
        $voucher->delete();
        $this->get(route('recycle-bin.preview', $voucher))->assertOk()->assertHeaderContains('Cache-Control', 'no-store')
            ->assertStreamedContent('%PDF-1.4 private');
        $this->actingAs(User::factory()->create())->getJson(route('accounts.index'))->assertForbidden()
            ->assertHeaderContains('Cache-Control', 'no-store');
    }

    public function test_rendered_confirmation_messages_are_escaped_data_instead_of_inline_javascript(): void
    {
        $user = User::factory()->administrator()->create();
        $this->actingAs($user);
        VoucherYear::create(['year' => 2026]);
        $voucher = Voucher::create(['checkada_no' => '123', 'dv_number' => '456', 'payee' => '<script>alert(1)</script>',
            'year' => 2026, 'type' => 'CHECK', 'month' => 7,
            'file_path' => 'vouchers/private.pdf', 'uploaded_by' => $user->id]);
        $account = User::factory()->create();

        foreach ([route('vouchers.index', ['year' => 2026, 'type' => 'CHECK', 'month' => 7]),
            route('vouchers.show', $voucher), route('accounts.edit', $account)] as $url) {
            $this->get($url)->assertOk()->assertSee('data-confirm=', false)->assertDontSee('onsubmit=', false)
                ->assertDontSee('<script>alert(1)</script>', false);
        }
        $voucher->delete();
        $this->get(route('recycle-bin.index'))->assertOk()->assertSee('data-confirm=', false)->assertDontSee('onsubmit=', false);
    }
}
