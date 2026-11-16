<?php

namespace App\Http\Middleware;

use App\ActivityRecorder;
use App\Models\Voucher;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditSecurityEvents
{
    public function __construct(private ActivityRecorder $recorder) {}

    /**
     * SECURITY-FIX: 2026-11-12 Record PDF delivery and authenticated authorization denials.
     * SECURITY: Keep request bodies, credentials, and document contents out of audit records.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->user() === null) {
            return $response;
        }
        if ($response->getStatusCode() === Response::HTTP_FORBIDDEN) {
            $this->recorder->authorizationDenied($request);

            return $response;
        }

        $route = $request->route();
        $voucher = $route?->parameter('voucher');
        if ($request->isMethod('GET') && in_array($response->getStatusCode(), [200, 206], true)
            && $voucher instanceof Voucher
            && in_array($route->getName(), ['vouchers.pdf', 'vouchers.download', 'recycle-bin.preview'], true)) {
            $this->recorder->record($voucher, $route->getName() === 'vouchers.download' ? 'voucher.downloaded' : 'voucher.viewed');
        }

        return $response;
    }
}
