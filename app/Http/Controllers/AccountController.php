<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function index(): View
    {
        return view('accounts.index', ['accounts' => User::orderBy('name')->orderBy('id')->paginate(20)]);
    }

    public function create(): View
    {
        return view('accounts.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'regex:/\A[A-Za-z0-9._-]{3,40}\z/', 'unique:users,username'],
            'role' => ['required', Rule::in(['staff', 'administrator'])],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()],
        ]);
        DB::transaction(function () use ($request, $data): void {
            $this->lockAdministrators($request);
            $account = new User(['name' => $data['name'], 'username' => $data['username'], 'password' => $data['password']]);
            $account->forceFill(['is_admin' => $data['role'] === 'administrator', 'must_change_password' => true])->save();
        }, 3);

        return redirect()->route('accounts.index')->with('status', 'Account created. Give the employee their username and temporary password. They must change it at first sign-in.');
    }

    public function edit(User $account): View
    {
        return view('accounts.edit', ['account' => $account]);
    }

    public function update(Request $request, User $account): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'regex:/\A[A-Za-z0-9._-]{3,40}\z/', Rule::unique('users', 'username')->ignore($account)],
            'role' => ['required', Rule::in(['staff', 'administrator'])],
        ]);
        DB::transaction(function () use ($request, $account, $data): void {
            $administrators = $this->lockAdministrators($request);
            $current = User::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $isAdmin = $data['role'] === 'administrator';
            if ($current->id === $request->user()->id && ! $isAdmin) {
                throw ValidationException::withMessages(['role' => 'You cannot remove your own administrator role.']);
            }
            if ($current->is_admin && ! $isAdmin && $administrators->count() <= 1) {
                throw ValidationException::withMessages(['role' => 'Keep at least one administrator account.']);
            }
            $roleChanged = $current->is_admin !== $isAdmin;
            $current->fill(['name' => $data['name'], 'username' => $data['username']]);
            $current->is_admin = $isAdmin;
            $current->save();
            if ($roleChanged) {
                $this->invalidateSessions($current);
            }
        }, 3);

        return redirect()->route('accounts.edit', $account)->with('status', 'Account updated. If the role changed, the employee must sign in again.');
    }

    public function resetPassword(Request $request, User $account): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'confirmed', Password::min(12)->mixedCase()]]);
        if ($account->id === $request->user()->id) {
            throw ValidationException::withMessages(['password' => 'Use Change password in your profile to change your own password.']);
        }
        DB::transaction(function () use ($request, $account, $data): void {
            $this->lockAdministrators($request);
            $current = User::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $current->password = $data['password'];
            $current->must_change_password = true;
            $current->save();
            $this->invalidateSessions($current);
        }, 3);

        return redirect()->route('accounts.edit', $account)->with('status', 'Password reset. The employee must sign in with the temporary password and choose a new one.');
    }

    public function destroy(Request $request, User $account): RedirectResponse
    {
        DB::transaction(function () use ($request, $account): void {
            $administrators = $this->lockAdministrators($request);
            $current = User::whereKey($account->id)->lockForUpdate()->firstOrFail();
            if ($current->id === $request->user()->id) {
                throw ValidationException::withMessages(['account' => 'You cannot delete the account you are signed in with.']);
            }
            if ($current->is_admin && $administrators->count() <= 1) {
                throw ValidationException::withMessages(['account' => 'Keep at least one administrator account.']);
            }
            $this->invalidateSessions($current);
            $current->delete();
        }, 3);

        return redirect()->route('accounts.index')->with('status', 'Account deleted. Access has been removed; its saved PDFs and uploader history are preserved.');
    }

    /** @return Collection<int, User> */
    private function lockAdministrators(Request $request): Collection
    {
        $administrators = User::where('is_admin', true)->orderBy('id')->lockForUpdate()->get();
        abort_unless($administrators->contains('id', $request->user()->id), 403);

        return $administrators;
    }

    private function invalidateSessions(User $account): void
    {
        $account->remember_token = Str::random(60);
        $account->save();
        DB::table('sessions')->where('user_id', $account->id)->delete();
    }
}
