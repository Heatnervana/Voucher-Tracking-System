<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * SECURITY-FIX: 2026-11-12 Recover stale sign-out navigation without allowing GET to end a session.
     */
    public function redirectLogout(Request $request): RedirectResponse
    {
        return redirect()->route($request->user() === null ? 'login' : 'vouchers.index');
    }

    /**
     * SECURITY: Generic failures avoid username disclosure; account/IP limits bound guessing.
     * TRUST-BOUNDARY: Successful authentication rotates the visitor's session identifier.
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['username' => ['required', 'string', 'max:255'], 'password' => ['required', 'string']]);
        $key = 'login:'.mb_strtolower($credentials['username']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['username' => 'Too many sign-in attempts. Please try again later.']);
        }

        if (! Auth::attempt($credentials)) {
            RateLimiter::hit($key, 900);
            throw ValidationException::withMessages(['username' => 'The username or password is incorrect.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->intended(route('vouchers.index'));
    }

    /**
     * SECURITY: Invalidate session state and its CSRF token when relinquishing access.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
