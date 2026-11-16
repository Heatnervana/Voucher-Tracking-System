<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    /**
     * SECURITY: Restrict browser execution, framing and caching at the response boundary.
     * TRUST-BOUNDARY: The server generates correlation IDs; callers cannot choose log context.
     * SECURITY-TODO: Review PDF object-src and native server upgrades in docs/SECURITY_AUDIT.md.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // SECURITY-FIX: 2026-11-12 Correlate private server errors without trusting client-supplied IDs.
        $requestId = (string) Str::uuid();
        Log::withContext(['request_id' => $requestId]);
        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);
        // SECURITY-FIX: 2026-11-12 Private records must not persist in browser or shared caches.
        $response->headers->set('Cache-Control', 'private, no-store');

        // SECURITY-FIX: 2026-11-12 Block injected scripts while retaining same-origin PDF viewing.
        $policy = "default-src 'self'; script-src 'self'; connect-src 'self'; img-src 'self'; frame-src 'self'; object-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'";
        // SECURITY-FIX: 2026-11-12 Only local hot reload needs assets from the development server.
        if (app()->environment('local') && Vite::isRunningHot()) {
            $policy = "base-uri 'self'; form-action 'self'; frame-ancestors 'self'";
        }
        $response->headers->set('Content-Security-Policy', $policy);
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // HSTS on the office hostname would rewrite its HTTP port 8080 shortcut to HTTPS on the same port.
        if ($request->isSecure() && $request->getPort() === 443) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
