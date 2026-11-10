@extends('layouts.app')
@section('title', 'Create account')
@section('content')
<a class="back-link" href="{{ route('accounts.index') }}">← Accounts</a>
<section class="form-card"><h1>Create account</h1><p class="muted">Set a temporary password and share it with the employee. They will choose their own password at first sign-in.</p>
    <form method="POST" action="{{ route('accounts.store') }}" class="stack">@csrf
        @include('accounts._fields')
        <label>Temporary password<input type="password" name="password" autocomplete="new-password" minlength="12" required aria-describedby="password-help"></label>
        <label>Confirm temporary password<input type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></label>
        <p id="password-help" class="hint">At least 12 characters, including upper and lower case letters.</p>
        <div class="button-row"><a class="button secondary" href="{{ route('accounts.index') }}">Cancel</a><button class="button primary" type="submit">Create account</button></div>
    </form>
</section>
@endsection
