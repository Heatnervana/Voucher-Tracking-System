<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · PPA Voucher Tracker</title>
    <link rel="icon" type="image/png" href="{{ asset('images/ppa-logo.png') }}">
    <link rel="icon" type="image/svg+xml" sizes="any" href="{{ asset('images/ppa-tab-icon.svg') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="login-page">
<a class="skip-link" href="#main-content">Skip to sign in</a>
<section class="login-scene" aria-label="Philippine Ports Authority">
    <div class="login-photo" aria-hidden="true"></div>
    <a class="brand login-brand" href="{{ route('login') }}"><img class="ppa-logo" src="{{ asset('images/ppa-logo.png') }}" alt="PPA logo" width="379" height="640"><span><strong>Philippine Ports Authority</strong><small>Port Management Office</small></span></a>
    <div class="login-story"><p>Find the PDF you need.<br>Keep your office records in order.</p></div>
    <p class="login-scene-caption">Philippine Ports Authority · Voucher records</p>
</section>
<main id="main-content" class="login-card" tabindex="-1"><div class="login-form-wrap">
    <p class="login-product">Voucher Tracker</p><h1>Sign in</h1><p class="login-description">Access your office voucher library.</p>
    @if ($errors->any()) <div class="notice error">{{ $errors->first() }}</div> @endif
    <form method="POST" action="{{ route('login') }}" class="stack">@csrf
        <label>Username<input name="username" value="{{ old('username') }}" autocomplete="username" placeholder="Enter your username" required autofocus></label>
        <label>Password<input name="password" type="password" autocomplete="current-password" placeholder="Enter your password" required></label>
        <button class="button primary" type="submit">Sign in</button>
    </form><p class="login-help">Need access or a password reset?<br>Contact your office administrator.</p></div><p class="login-foot">Office voucher library · Authorized personnel</p>
</main></body></html>
