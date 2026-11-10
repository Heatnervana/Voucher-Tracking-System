@extends('layouts.app')
@section('title', 'Recycle bin')
@section('content')
<section class="hero-row"><div><h1>Recycle bin</h1><p class="muted">Restore deleted PDFs to their original folders, or permanently remove them.</p></div><a class="button secondary" href="{{ route('vouchers.index') }}">Voucher library</a></section>
<section class="panel toolbar"><form class="filters" method="GET" action="{{ route('recycle-bin.index') }}"><label class="search-field"><span class="sr-only">Search deleted PDFs</span><input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search DV, Check / ADA no. or payee..."></label><button class="button secondary" type="submit">Search</button>@if ($filters['q'] ?? null)<a class="subtle-link" href="{{ route('recycle-bin.index') }}">Clear search</a>@endif</form></section>
<section class="panel table-panel"><div class="panel-heading"><div><h2>Deleted PDFs</h2><p class="muted">{{ $vouchers->total() }} PDF(s). Nothing here is automatically deleted. Times are shown in office time (UTC+8).</p></div></div>
    <form id="recycle-selection-form" method="POST" action="{{ route('recycle-bin.restore-selected') }}" class="selection-toolbar">@csrf
        <label class="pdf-selection"><input type="checkbox" id="select-recycle-page" @disabled($vouchers->isEmpty())> Select all on this page</label>
        <span id="recycle-selection-count" role="status" aria-live="polite">0 PDFs selected</span>
        <button type="submit" class="button secondary" data-recycle-submit disabled>Restore selected</button>
        <button type="submit" class="button danger" name="_method" value="DELETE" formaction="{{ route('recycle-bin.destroy-selected') }}" data-recycle-submit data-recycle-purge disabled>Permanently delete selected</button>
    </form>
    <div class="table-scroll"><table class="voucher-table"><thead><tr><th scope="col">Select</th><th scope="col">DV number</th><th scope="col">Check / ADA</th><th scope="col">Payee / filename</th><th scope="col">Original folder</th><th scope="col">Deleted</th><th scope="col">Deleted by</th><th scope="col">Actions</th></tr></thead><tbody>
    @forelse ($vouchers as $voucher)
        <tr class="voucher-record">
            <td class="recycle-select"><label class="pdf-selection"><input type="checkbox" name="voucher_ids[]" value="{{ $voucher->id }}" form="recycle-selection-form" data-select-recycle><span class="sr-only">Select deleted PDF for DV {{ $voucher->dv_number }}</span></label></td>
            <td class="voucher-dv" data-label="DV number">{{ $voucher->dv_number }}</td><td class="voucher-check" data-label="Check / ADA">{{ $voucher->checkada_no }}</td>
            <td class="voucher-payee" data-label="Payee / filename">{{ $voucher->payee }}<small class="recycle-filename">{{ basename($voucher->file_path) }}</small></td>
            <td class="voucher-folder" data-label="Original folder">{{ $voucher->year }} / {{ $voucher->type }} / {{ \Carbon\Carbon::create()->month($voucher->month)->format('F') }}</td>
            <td class="recycle-deleted" data-label="Deleted">{{ $voucher->deleted_at->timezone('Asia/Singapore')->format('M j, Y g:i A') }}</td><td class="recycle-actor" data-label="Deleted by">{{ $voucher->deleter?->name ?? 'System' }}</td>
            <td class="recycle-actions"><a class="subtle-link" href="{{ route('recycle-bin.preview', $voucher) }}" target="_blank" rel="noopener">View PDF</a><form method="POST" action="{{ route('recycle-bin.restore', $voucher) }}">@csrf<button class="button secondary" type="submit">Restore</button></form><form method="POST" action="{{ route('recycle-bin.destroy', $voucher) }}" onsubmit="return confirm('Permanently delete this PDF? It cannot be restored from the recycle bin afterward.');">@csrf @method('DELETE')<button class="text-danger" type="submit">Permanently delete</button></form></td>
        </tr>
    @empty
        <tr><td class="empty-state" colspan="8"><strong>{{ ($filters['q'] ?? null) ? 'No deleted PDFs match your search' : 'The recycle bin is empty' }}</strong><span>Deleted vouchers will appear here so an administrator can restore them.</span></td></tr>
    @endforelse
    </tbody></table></div>
    <nav class="voucher-pagination" aria-label="Recycle bin pages"><p>Page <strong>{{ $vouchers->currentPage() }}</strong> of <strong>{{ $vouchers->lastPage() }}</strong></p>@if ($vouchers->hasPages())<div class="voucher-page-controls">@if ($vouchers->previousPageUrl())<a class="button secondary" href="{{ $vouchers->previousPageUrl() }}">Previous</a>@endif @foreach (collect([1, $vouchers->currentPage() - 1, $vouchers->currentPage(), $vouchers->currentPage() + 1, $vouchers->lastPage()])->filter(fn ($page) => $page >= 1 && $page <= $vouchers->lastPage())->unique()->sort() as $page)<a class="button secondary voucher-page-number" href="{{ $vouchers->url($page) }}" @if ($page === $vouchers->currentPage()) aria-current="page" @endif>{{ $page }}</a>@endforeach @if ($vouchers->nextPageUrl())<a class="button secondary" href="{{ $vouchers->nextPageUrl() }}">Next</a>@endif</div>@endif</nav>
</section>
@endsection
