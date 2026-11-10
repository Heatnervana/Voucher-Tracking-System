<label>Full name<input name="name" value="{{ old('name', $account->name ?? '') }}" maxlength="100" autocomplete="name" required></label>
<label>Username<input name="username" value="{{ old('username', $account->username ?? '') }}" minlength="3" maxlength="40" autocomplete="off" autocapitalize="none" spellcheck="false" required aria-describedby="username-help"></label>
<p id="username-help" class="hint">3–40 characters. Use letters, numbers, dots, hyphens, or underscores. Each username must be unique, including previously deleted accounts.</p>
@if (isset($account) && $account->id === auth()->id())
    <label>Role<input value="Administrator" disabled><input type="hidden" name="role" value="administrator"></label>
    <p class="hint">You cannot remove your own administrator role.</p>
@else
    <label>Role<select name="role" aria-describedby="role-help" required><option value="staff" @selected(old('role', isset($account) && $account->is_admin ? 'administrator' : 'staff') === 'staff')>Staff</option><option value="administrator" @selected(old('role', isset($account) && $account->is_admin ? 'administrator' : 'staff') === 'administrator')>Administrator</option></select></label>
    <p id="role-help" class="hint">Staff can search and view PDFs. Administrators can also upload, edit, delete, and manage accounts.</p>
@endif
