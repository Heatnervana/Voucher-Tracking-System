@extends('layouts.app')
@section('title', $voucher->dv_number)
@section('content')
<a class="back-link" href="{{ route('vouchers.index') }}">← Voucher library</a><section class="detail-heading"><div><p class="eyebrow">{{ $voucher->year }} · {{ $voucher->type }} · {{ sprintf('%02d', $voucher->month) }}</p><h1>DV <span data-voucher-dv>{{ $voucher->dv_number }}</span></h1><p class="muted"><span data-voucher-payee>{{ $voucher->payee }}</span> <span>·</span> Check / ADA <span data-voucher-check>{{ $voucher->checkada_no }}</span></p></div><div class="button-row">@can('manage-vouchers')<a class="button secondary" href="{{ route('vouchers.download', $voucher) }}">↓ Download</a>@endcan<a class="button primary" href="{{ route('vouchers.pdf', $voucher) }}" target="_blank" rel="noopener">Open PDF ↗</a></div></section>
@can('manage-vouchers')
<div class="saved-voucher-delete"><form method="POST" action="{{ route('vouchers.destroy', $voucher) }}" data-voucher-delete data-dv-number="{{ $voucher->dv_number }}" onsubmit="return confirm('Move voucher DV ' + this.dataset.dvNumber + ' and its saved PDF to the recycle bin?');">@csrf @method('DELETE')<button class="button danger" type="submit">Delete saved voucher and PDF</button></form></div>
@endcan
<section class="detail-grid"><article class="panel pdf-panel"><iframe title="Voucher PDF" src="{{ route('vouchers.pdf', $voucher) }}"></iframe></article><aside class="panel metadata"><p class="eyebrow">VOUCHER DETAILS</p><div><small>Amount</small><strong class="large-amount" data-saved-amount data-saved-voucher-id="{{ $voucher->id }}">@if ($voucher->amount === null){{ $voucher->amount_status === 'pending' ? 'Reading amount…' : 'Amount needed' }}
@else ₱ {{ number_format((float) $voucher->amount, 2) }}@endif</strong></div><div><small>Uploaded by</small><strong>{{ $voucher->uploader->name }}</strong></div><div><small>Saved filename</small><strong class="break-word" data-saved-filename>{{ basename($voucher->file_path) }}</strong></div>
@can('manage-vouchers')
<p class="hint" data-pending-amount-help @if ($voucher->amount_status !== 'pending') hidden @endif>Reading continues in the background. You can enter an amount yourself; it will not be overwritten.</p>
<details class="filename-editor" id="amount-editor" @if ($voucher->amount === null || $errors->has('amount')) open @endif>
<summary class="button secondary">{{ $voucher->amount === null ? 'Add amount' : 'Edit amount' }}</summary>
<form method="POST" action="{{ route('vouchers.update-amount', $voucher) }}" class="stack">
    @csrf @method('PATCH')
    <label>Amount (PHP)
        <input name="amount" type="number" min="0.01" max="9999999999999.99" step="0.01" inputmode="decimal" value="{{ old('amount', $voucher->amount) }}" required aria-describedby="amount-help">
    </label>
    <p id="amount-help" class="hint">Enter the correct amount shown on the PDF. This updates the voucher record; the PDF stays the same.</p>
    <button class="button primary" type="submit">Save amount</button>
</form>
</details>
<details class="filename-editor" @if ($errors->has('filename')) open @endif>
    <summary class="button secondary">Edit filename</summary>
    <form id="voucher-filename-form" method="POST" action="{{ route('vouchers.rename', $voucher) }}" class="stack">
        @csrf @method('PATCH')
        <label>Full saved filename
            <input name="filename" value="{{ old('filename', basename($voucher->file_path)) }}" maxlength="150" required aria-describedby="filename-help filename-status">
        </label>
        <p id="filename-help" class="hint">Use CHECK/ADA_DV_PAYEE.pdf to update the voucher numbers and payee too. Other names change only the filename. Names without an extension receive .pdf automatically. Maximum 150 characters, including .pdf.</p>
        <p id="filename-status" data-filename-status role="status" aria-live="polite">{{ $errors->first('filename') }}</p>
        <button class="button primary" type="submit">Save filename</button>
    </form>
</details>
<hr><p class="eyebrow">MOVE TO FOLDER</p><form method="POST" action="{{ route('vouchers.move', $voucher) }}" class="stack">@csrf
    <label>Year<select name="year" required>@foreach ($years as $year)<option value="{{ $year }}" @selected($voucher->year == $year)>{{ $year }}</option>@endforeach</select></label>
    <label>Type<select name="type"><option @selected($voucher->type === 'ADA')>ADA</option><option @selected($voucher->type === 'CHECK')>CHECK</option></select></label>
    <label>Month<select name="month">@foreach (range(1, 12) as $month)<option value="{{ $month }}" @selected($voucher->month === $month)>{{ \Carbon\Carbon::create()->month($month)->format('F') }}</option>@endforeach</select></label>
    <button class="button secondary" type="submit">Move voucher</button>
</form>@endcan</aside></section>
@endsection
