@extends('layouts.app')
@section('title', 'Accounts')
@section('content')
<section class="hero-row"><div><h1>Accounts</h1><p class="muted">Manage who can use Voucher Tracker.</p></div><a class="button primary" href="{{ route('accounts.create') }}">Create account</a></section>
<section class="panel table-panel">
    <div class="panel-heading"><div><h2>Employee accounts</h2><p class="muted">{{ $accounts->total() }} account(s). Staff can search and view PDFs. Administrators manage vouchers and accounts.</p></div></div>
    <div class="table-scroll"><table class="account-table"><thead><tr><th scope="col">Name</th><th scope="col">Username</th><th scope="col">Role</th><th scope="col">Sign-in</th><th scope="col">Actions</th></tr></thead><tbody>
    @foreach ($accounts as $account)
        <tr class="account-record">
            <td data-label="Name"><strong>{{ $account->name }}</strong> @if ($account->id === auth()->id())<span class="tag">You</span>@endif</td>
            <td data-label="Username">{{ $account->username }}</td>
            <td data-label="Role">{{ $account->is_admin ? 'Administrator' : 'Staff' }}</td>
            <td data-label="Sign-in">{{ $account->must_change_password ? 'Password change required' : 'Ready' }}</td>
            <td data-label="Actions"><a class="button secondary" href="{{ route('accounts.edit', $account) }}" aria-label="Manage account for {{ $account->name }}">Manage account</a></td>
        </tr>
    @endforeach
    </tbody></table></div>
    <nav class="voucher-pagination" aria-label="Account pages"><p>Page <strong>{{ $accounts->currentPage() }}</strong> of <strong>{{ $accounts->lastPage() }}</strong></p>
        @if ($accounts->hasPages())<div class="voucher-page-controls">
            @if ($accounts->previousPageUrl())<a class="button secondary" href="{{ $accounts->previousPageUrl() }}">Previous</a>@endif
            @foreach (collect([1, $accounts->currentPage() - 1, $accounts->currentPage(), $accounts->currentPage() + 1, $accounts->lastPage()])->filter(fn ($page) => $page >= 1 && $page <= $accounts->lastPage())->unique()->sort() as $page)
                <a class="button secondary voucher-page-number" href="{{ $accounts->url($page) }}" @if ($page === $accounts->currentPage()) aria-current="page" @endif>{{ $page }}</a>
            @endforeach
            @if ($accounts->nextPageUrl())<a class="button secondary" href="{{ $accounts->nextPageUrl() }}">Next</a>@endif
        </div>@endif
    </nav>
</section>
@endsection
