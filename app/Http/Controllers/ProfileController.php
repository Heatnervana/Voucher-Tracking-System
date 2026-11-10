<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(): View
    {
        return view('profile.edit');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()],
        ]);
        $user = $request->user();
        $user->password = $data['password'];
        $user->must_change_password = false;
        DB::transaction(fn () => $user->save());

        $request->session()->regenerate();

        return redirect()->route('vouchers.index')->with('status', 'Password updated.');
    }
}
