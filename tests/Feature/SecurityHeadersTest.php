<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_sign_in_has_browser_security_headers_on_https(): void
    {
        $this->get('https://preview.example.test/login')
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "base-uri 'self'; frame-ancestors 'self'")
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
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }
}
