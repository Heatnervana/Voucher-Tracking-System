@extends('layouts.app')
@section('title', 'Manage account')
@section('content')
<a class="back-link" href="{{ route('accounts.index') }}">← Accounts</a>
<section class="form-card"><h1>Manage account</h1><p class="muted">{{ $account->name }} · {{ $account->username }}</p>
    <form method="POST" action="{{ route('accounts.update', $account) }}" class="stack">@csrf @method('PATCH')
        @include('accounts._fields')
        <p class="hint">Changing the role signs this employee out so their new permissions take effect.</p>
        <div class="button-row"><button class="button primary" type="submit">Save account</button></div>
    </form>
</section>
@if ($account->id === auth()->id())
    <section class="form-card"><h2>Your password</h2><p class="muted">Change your own password from your profile.</p><a class="button secondary" href="{{ route('profile.edit') }}">Change my password</a><p class="hint account-self-help">You cannot delete the account you are signed in with.</p></section>
@else
    <section class="form-card"><h2>Reset password</h2><p class="muted">This signs the employee out. They must change this temporary password at their next sign-in.</p>
        <form method="POST" action="{{ route('accounts.password', $account) }}" class="stack">@csrf @method('PUT')
            <label>New temporary password<input type="password" name="password" autocomplete="new-password" minlength="12" required aria-describedby="reset-password-help"></label>
            <label>Confirm temporary password<input type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></label>
            <p id="reset-password-help" class="hint">At least 12 characters, including upper and lower case letters.</p>
            <div class="button-row"><button class="button secondary" type="submit">Reset password</button></div>
        </form>
    </section>
    <section class="form-card"><h2>Delete account</h2><p class="muted">Remove this person's sign-in access. Their uploaded PDFs and uploader history will be kept.</p>
        <form method="POST" action="{{ route('accounts.destroy', $account) }}" onsubmit="return confirm('Delete this account? The person will lose access. Their saved PDFs will be kept.');">@csrf @method('DELETE')<button class="button danger" type="submit">Delete account</button></form>
    </section>
@endif
@endsection
