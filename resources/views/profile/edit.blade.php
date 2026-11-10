@extends('layouts.app')
@section('title', 'Change password')
@section('content')
<section class="form-card"><p class="eyebrow">ACCOUNT SECURITY</p><h1>Change password</h1><p class="muted">Choose a password with at least 12 characters, including upper and lower case letters.</p>
@if (session('password_notice')) <div class="notice error">{{ session('password_notice') }}</div> @endif
<form method="POST" action="{{ route('profile.update') }}" class="stack">@csrf @method('PUT')
    <label>Current password<input type="password" name="current_password" autocomplete="current-password" required></label>
    <label>New password<input type="password" name="password" autocomplete="new-password" minlength="12" required></label>
    <label>Confirm new password<input type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></label>
    <div class="button-row"><button class="button primary" type="submit">Update password</button></div>
</form></section>
@endsection
