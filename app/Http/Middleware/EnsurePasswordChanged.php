<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    /**
     * TRUST-BOUNDARY: A valid login with a temporary password cannot access the library yet.
     * SECURITY: Profile and logout routes remain available to complete the required password change.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            return redirect()->route('profile.edit')->with('password_notice', 'Change your temporary password before continuing.');
        }

        return $next($request);
    }
}
