<nav class="nav-links" aria-label="Main navigation">
    <a href="{{ route('vouchers.index') }}" @if (request()->routeIs('vouchers.*')) aria-current="page" @endif>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h8l4 4v14H6zM14 3v5h4M9 12h6m-6 4h6"/></svg>
        Vouchers
    </a>
    @can('manage-vouchers')
        <a href="{{ route('years.create') }}" @if (request()->routeIs('years.*')) aria-current="page" @endif>
            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="6" width="16" height="14" rx="2"/><path d="M8 3v5m8-5v5M4 11h16"/></svg>
            Years
        </a>
        <a href="{{ route('recycle-bin.index') }}" @if (request()->routeIs('recycle-bin.*')) aria-current="page" @endif>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 10v7m4-7v7"/></svg>
            Recycle bin
        </a>
    @endcan
    @can('manage-accounts')
        <a href="{{ route('accounts.index') }}" @if (request()->routeIs('accounts.*')) aria-current="page" @endif>
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10" cy="8" r="3"/><path d="M4 20v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2m1-15a3 3 0 0 1 0 6m2 3a4 4 0 0 1 2 4v2"/></svg>
            Accounts
        </a>
        <a href="{{ route('activity.index') }}" @if (request()->routeIs('activity.*')) aria-current="page" @endif>
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
            Activity history
        </a>
    @endcan
</nav>
