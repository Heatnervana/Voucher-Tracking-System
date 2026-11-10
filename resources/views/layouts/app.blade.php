<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Voucher Tracker') · PPA</title>
    <link rel="icon" type="image/png" href="{{ asset('images/ppa-logo.png') }}">
    <link rel="icon" type="image/svg+xml" sizes="any" href="{{ asset('images/ppa-tab-icon.svg') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body @if (! auth()->user()->must_change_password) data-saved-amounts-url="{{ route('vouchers.amount-status') }}" @endif>
    <a class="skip-link" href="#main-content">Skip to content</a>
    <header class="topbar">
        <a class="brand" href="{{ auth()->user()->must_change_password ? route('profile.edit') : route('vouchers.index') }}">
            <img class="ppa-logo" src="{{ asset('images/ppa-logo.png') }}" alt="" width="379" height="640">
            <span class="brand-copy">
                <strong class="product-title">Voucher Tracker</strong>
                <span class="agency-name">Philippine Ports Authority</span>
                <small>Port Management Office</small>
            </span>
        </a>
        @auth
            <div class="account">
                @can('manage-vouchers')
                @if (! auth()->user()->must_change_password)
                    @php($notificationLabel = $notificationAmountCount === 0 ? 'No vouchers need an amount' : $notificationAmountCount.' '.($notificationAmountCount === 1 ? 'voucher needs' : 'vouchers need').' an amount')
                    <a id="amount-notification" class="notification-bell" href="{{ route('vouchers.index', ['amount_status' => 'missing']) }}" aria-label="Notifications: {{ $notificationLabel }}" title="{{ $notificationLabel }}">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
                        @if ($notificationAmountCount > 0)
                            <span class="notification-count" aria-hidden="true">{{ $notificationAmountCount }}</span>
                        @endif
                    </a>
                @endif
                @endcan
                <a class="account-profile" href="{{ route('profile.edit') }}" aria-label="Profile for {{ auth()->user()->name }}">
                    <span class="account-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                    <span class="account-name">{{ auth()->user()->name }}</span>
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="text-button">Sign out</button>
                </form>
            </div>
        @endauth
    </header>
    <div class="workspace @if (auth()->user()->must_change_password) workspace-standalone @endif">
        @if (! auth()->user()->must_change_password)
            @include('layouts.navigation')
        @endif
        <div class="workspace-content">
            <main id="main-content" class="page-wrap" tabindex="-1">
                @if (session('status')) <div class="notice success">{{ session('status') }}</div> @endif
                @if (session('upload_errors'))
                    <div class="notice error"><strong>Some files need attention</strong><ul>@foreach (session('upload_errors') as $error)<li>{{ $error }}</li>@endforeach</ul></div>
                @endif
                @if ($errors->any()) <div class="notice error"><strong>Please check the form.</strong><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div> @endif
                @yield('content')
            </main>
            <footer>Philippine Ports Authority <span>·</span> Port Management Office</footer>
        </div>
    </div>
</body>
</html>
