@extends('layouts.app')
@section('title', 'Vouchers')
@section('content')
@php($canManage = auth()->user()->can('manage-vouchers'))
@php($hasFilters = collect($filters)->contains(fn ($value) => $value !== null && $value !== ''))
@php($selectedVoucher = $vouchers->first())
<header class="voucher-library-header">
    <div class="voucher-library-heading">
        <div>
            <h1>Voucher library</h1>
            <p class="muted">{{ number_format($vouchers->total()) }} {{ $hasFilters ? 'matching' : 'stored' }} {{ $vouchers->total() === 1 ? 'voucher' : 'vouchers' }}. Search by DV number, Check / ADA, or payee.</p>
        </div>
        @if ($canManage)
            <a class="button primary" href="{{ route('vouchers.create') }}">
                <svg class="interface-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m-4 4 4-4 4 4M5 14v5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-5"/></svg>
                Upload vouchers
            </a>
        @endif
    </div>
    <form method="GET" action="{{ route('vouchers.index') }}" class="panel filters library-filters" role="search" aria-label="Voucher library">
        <div class="library-search-row">
            <label class="search-field">
                <span class="sr-only">Search vouchers</span>
                <svg class="interface-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="5.5"/><path d="m15 15 4 4"/></svg>
                <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="DV number, Check / ADA, or payee">
            </label>
            <button class="button primary" type="submit">Search</button>
        </div>
        <div class="filter-fields library-filter-row">
            <label>Folder<select name="type"><option value="">All folders</option><option @selected(($filters['type'] ?? '') === 'ADA')>ADA</option><option @selected(($filters['type'] ?? '') === 'CHECK')>CHECK</option></select></label>
            <label>Year<select name="year"><option value="">All years</option>@foreach ($years as $year)<option value="{{ $year }}" @selected(($filters['year'] ?? '') == $year)>{{ $year }}</option>@endforeach</select></label>
            <label>Month<select name="month"><option value="">All months</option>@foreach (range(1, 12) as $month)<option value="{{ $month }}" @selected(($filters['month'] ?? '') == $month)>{{ \Carbon\Carbon::create()->month($month)->format('F') }}</option>@endforeach</select></label>
            @if ($canManage)
                <label>Amount status<select name="amount_status"><option value="">All amounts</option><option value="missing" @selected(($filters['amount_status'] ?? '') === 'missing')>Amount needed</option></select></label>
            @endif
        </div>
        @if ($hasFilters)<a class="clear-filters" href="{{ route('vouchers.index') }}">Clear search and filters</a>@endif
    </form>
</header>
@if ($canManage)
    <div id="missing-amount-notice" class="notice" @if ($missingAmountCount === 0) hidden @endif><strong>@if ($missingAmountCount > 0){{ $missingAmountCount }} voucher(s) need an amount.@endif</strong> <a class="subtle-link" href="{{ route('vouchers.index', ['amount_status' => 'missing']) }}">View vouchers missing amounts</a></div>
@endif
<div class="library-results @if ($selectedVoucher) library-has-preview @endif">
<section class="panel table-panel library-table"><div class="panel-heading"><div><h2>Stored vouchers</h2><p class="muted">{{ number_format($vouchers->total()) }} {{ $vouchers->total() === 1 ? 'record' : 'records' }}</p></div></div>
@if ($canManage)
<details id="record-management" class="record-management">
    <summary>Manage records</summary>
    <p class="management-help">Select PDFs to delete records. Filter by folder and year to manage a folder.</p>
    <a class="subtle-link management-year" href="{{ route('years.create') }}">New year</a>
@endif
@if ($canManage && isset($filters['year'], $filters['type']))
    <section class="panel bulk-delete-panel" aria-label="Delete saved vouchers by folder or month">
        <div><strong>Delete by folder or month</strong><p class="muted">These PDFs will move to the recycle bin. An administrator can restore them later.</p></div>
        <div class="bulk-delete-actions">
            @if (isset($filters['month']))
                @php($monthName = \Carbon\Carbon::create()->month((int) $filters['month'])->format('F'))
                <form method="POST" action="{{ route('vouchers.destroy-month') }}" onsubmit="return confirm('Move {{ $monthCount }} voucher(s) and PDF(s) from {{ $filters['year'] }} / {{ $filters['type'] }} / {{ $monthName }} to the recycle bin?');">@csrf @method('DELETE')<input type="hidden" name="year" value="{{ $filters['year'] }}"><input type="hidden" name="type" value="{{ $filters['type'] }}"><input type="hidden" name="month" value="{{ $filters['month'] }}"><button class="button danger" type="submit" @disabled($monthCount === 0)>Delete {{ $monthName }} ({{ $monthCount }})</button></form>
            @endif
            <form method="POST" action="{{ route('vouchers.destroy-folder') }}" onsubmit="return confirm('Move all {{ $folderCount }} voucher(s) and PDF(s) from the {{ $filters['year'] }} / {{ $filters['type'] }} folder, across every month, to the recycle bin?');">@csrf @method('DELETE')<input type="hidden" name="year" value="{{ $filters['year'] }}"><input type="hidden" name="type" value="{{ $filters['type'] }}"><button class="button danger" type="submit" @disabled($folderCount === 0)>Delete entire {{ $filters['year'] }} / {{ $filters['type'] }} folder ({{ $folderCount }})</button></form>
        </div>
    </section>
@endif
    @if ($canManage)
    <form id="delete-selected-form" class="selection-toolbar" method="POST" action="{{ route('vouchers.destroy-selected') }}">
        @csrf @method('DELETE')
        <label class="pdf-selection"><input id="select-page-pdfs" type="checkbox" @disabled($vouchers->isEmpty())> Select all on this page</label>
        <span id="selected-pdf-count" role="status" aria-live="polite">0 PDFs selected</span>
        <button id="delete-selected-button" class="button danger" type="submit" disabled>Delete selected</button>
        <p class="muted">Selections apply to this page only.</p>
    </form>
    </details>
    @endif
    <div class="table-scroll"><table role="table" class="voucher-table"><thead><tr role="row">@if ($canManage)<th scope="col" class="management-cell">Select</th>@endif<th scope="col">DV number</th><th scope="col">Check / ADA</th><th scope="col">Payee</th><th scope="col" class="amount">Amount</th><th scope="col">Folder</th><th scope="col">Month</th><th scope="col">Actions</th></tr></thead><tbody role="rowgroup">
    @forelse ($vouchers as $voucher)
        @php($voucherMonth = \Carbon\Carbon::create()->month($voucher->month)->format('F'))
        <tr role="row" class="voucher-record" data-voucher-preview data-preview-folder="{{ $voucher->type }}" data-preview-period="{{ $voucherMonth }} {{ $voucher->year }}" @if ($loop->first) data-preview-selected @endif>
            @if ($canManage)<td role="cell" class="management-cell"><label class="pdf-selection"><input type="checkbox" name="voucher_ids[]" value="{{ $voucher->id }}" form="delete-selected-form" data-select-pdf><span class="sr-only">Select PDF for DV {{ $voucher->dv_number }}, {{ $voucher->payee }}</span></label></td>@endif
            <td role="cell" class="voucher-dv" data-label="DV number"><a class="record-link" href="{{ route('vouchers.show', $voucher) }}">{{ $voucher->dv_number }}</a></td><td role="cell" class="voucher-check" data-label="Check / ADA">{{ $voucher->checkada_no }}</td><td role="cell" class="voucher-payee" data-label="Payee">{{ $voucher->payee }}</td><td role="cell" class="amount" data-label="Amount" data-saved-amount data-saved-voucher-id="{{ $voucher->id }}">@if ($voucher->amount === null)<span class="tag">{{ $voucher->amount_status === 'pending' ? 'Reading amount…' : 'Amount needed' }}</span>@else PHP {{ number_format((float) $voucher->amount, 2) }}@endif</td><td role="cell" class="voucher-folder" data-label="Folder"><span class="tag">{{ $voucher->type }}</span><span class="muted">{{ $voucher->year }}</span></td>
            <td role="cell" class="voucher-month" data-label="Month">{{ $voucherMonth }}</td>
            <td role="cell" class="voucher-actions">
                <div class="voucher-action-list">
                    <button type="button" class="voucher-preview-button" data-select-preview aria-label="Preview DV {{ $voucher->dv_number }}" aria-controls="voucher-preview" aria-pressed="{{ $loop->first ? 'true' : 'false' }}" title="Preview voucher" hidden>
                        <svg class="interface-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12s3-6 9-6 9 6 9 6-3 6-9 6-9-6-9-6Z"/><circle cx="12" cy="12" r="2.5"/></svg>
                    </button>
                    <a class="subtle-link" href="{{ route('vouchers.show', $voucher) }}" aria-label="Open PDF for DV {{ $voucher->dv_number }}">Open PDF</a>
                    @if ($canManage)<form class="management-action" method="POST" action="{{ route('vouchers.destroy', $voucher) }}" onsubmit="return confirm('Move this saved PDF and voucher to the recycle bin?');">@csrf @method('DELETE')<button class="text-danger" type="submit" aria-label="Delete PDF for DV {{ $voucher->dv_number }}">Delete</button></form>@endif
                </div>
            </td>
        </tr>
    @empty<tr><td colspan="{{ $canManage ? 8 : 7 }}" class="empty-state">
        @if ($hasFilters)
            <strong>No vouchers match your search</strong><span>Try another number or payee, or clear the filters to see all vouchers.</span><a class="button secondary" href="{{ route('vouchers.index') }}">Clear search and filters</a>
        @else
            <strong>No vouchers here yet</strong>@if ($canManage)<span>Upload PDFs to start your library.</span><a class="button secondary" href="{{ route('vouchers.create') }}">Upload PDFs</a>@else<span>Ask your administrator to upload the voucher records.</span>@endif
        @endif
    </td></tr>@endforelse
    </tbody></table></div>
    @php($visiblePages = collect([1, $vouchers->currentPage() - 1, $vouchers->currentPage(), $vouchers->currentPage() + 1, $vouchers->lastPage()])->filter(fn ($page) => $page >= 1 && $page <= $vouchers->lastPage())->unique()->sort()->values())
    <nav class="voucher-pagination" aria-label="Voucher library pages"><p>Page <strong>{{ $vouchers->currentPage() }}</strong> of <strong>{{ $vouchers->lastPage() }}</strong> <span>({{ $vouchers->total() }} vouchers)</span></p>@if ($vouchers->hasPages())<div class="voucher-page-controls">@if ($vouchers->onFirstPage())<span class="button secondary" aria-disabled="true">Previous</span>@else<a class="button secondary" href="{{ $vouchers->previousPageUrl() }}">Previous</a>@endif @php($lastVisiblePage = 0) @foreach ($visiblePages as $pageNumber) @if ($pageNumber - $lastVisiblePage > 1)<span class="voucher-page-ellipsis" aria-hidden="true">...</span>@endif<a class="button secondary voucher-page-number" href="{{ $vouchers->url($pageNumber) }}" @if ($pageNumber === $vouchers->currentPage()) aria-current="page" @endif aria-label="Page {{ $pageNumber }}">{{ $pageNumber }}</a>@php($lastVisiblePage = $pageNumber) @endforeach @if ($vouchers->hasMorePages())<a class="button secondary" href="{{ $vouchers->nextPageUrl() }}">Next</a>@else<span class="button secondary" aria-disabled="true">Next</span>@endif</div>@endif</nav>
</section>
@if ($selectedVoucher)
    <aside id="voucher-preview" class="panel voucher-preview" aria-labelledby="voucher-preview-heading">
        <div class="voucher-preview-heading">
            <h2 id="voucher-preview-heading" tabindex="-1">Selected voucher</h2>
            <p data-preview-title aria-live="polite">{{ $selectedVoucher->payee }}</p>
            <p class="preview-amount" data-preview-amount>{{ $selectedVoucher->amount !== null ? 'PHP '.number_format((float) $selectedVoucher->amount, 2) : ($selectedVoucher->amount_status === 'pending' ? 'Reading amount…' : 'Amount needed') }}</p>
        </div>
        <dl class="preview-metadata">
            <div><dt>DV number</dt><dd data-preview-dv>{{ $selectedVoucher->dv_number }}</dd></div>
            <div><dt>Check / ADA</dt><dd data-preview-check>{{ $selectedVoucher->checkada_no }}</dd></div>
            <div><dt>Folder</dt><dd data-preview-type>{{ $selectedVoucher->type }}</dd></div>
            <div><dt>Period</dt><dd data-preview-period-value>{{ \Carbon\Carbon::create()->month($selectedVoucher->month)->format('F') }} {{ $selectedVoucher->year }}</dd></div>
        </dl>
        <div class="preview-document" aria-hidden="true">
            <svg viewBox="0 0 72 88" fill="none"><rect x="6" y="4" width="60" height="80" rx="3" fill="white" stroke="#c5d2e7"/><path d="M17 17h25" stroke="#8da2c5" stroke-width="3" stroke-linecap="round"/><path d="M17 28h37m-37 9h30m-30 9h37m-37 9h24m-24 9h31" stroke="#cbd7e9" stroke-width="2" stroke-linecap="round"/></svg>
        </div>
        <a class="button primary" data-preview-open href="{{ route('vouchers.show', $selectedVoucher) }}">Open PDF</a>
        <button type="button" class="button secondary preview-close" data-close-preview>Back to results</button>
    </aside>
@endif
</div>
@endsection
