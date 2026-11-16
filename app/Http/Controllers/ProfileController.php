<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(): View
    {
        return view('profile.edit');
    }

    /**
     * SECURITY-FIX: 2026-11-12. Require a new password and revoke other sessions.
     * SECURITY: The default database session store shares the credential update transaction.
     * TRUST-BOUNDARY: Legacy database sessions lack hashes for auth.session to compare.
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(12)->mixedCase()],
        ]);
        $user = $request->user();
        DB::transaction(function () use ($user, $data, $request): void {
            $user->password = $data['password'];
            $user->must_change_password = false;
            $user->remember_token = Str::random(60);
            $user->save();
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table'))
                    ->where('user_id', $user->id)
                    ->where('id', '!=', $request->session()->getId())
                    ->delete();
            }
        });

        $request->session()->regenerate(true);

        return redirect()->route('vouchers.index')->with('status', 'Password updated.');
    }
}
