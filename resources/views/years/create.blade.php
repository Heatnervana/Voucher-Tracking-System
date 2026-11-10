@extends('layouts.app')
@section('title', 'New year folder')
@section('content')
<a class="back-link" href="{{ route('vouchers.index') }}">← Voucher library</a><section class="form-card"><p class="eyebrow">ORGANIZE RECORDS</p><h1>New year folder</h1><p class="muted">Create a year so vouchers can be filed under it.</p><form method="POST" action="{{ route('years.store') }}" class="stack">@csrf
    <label>Year<input type="number" name="year" min="2000" max="2200" value="{{ old('year', now()->year) }}" required></label>
    <div class="button-row"><a class="button secondary" href="{{ route('vouchers.index') }}">Cancel</a><button class="button primary">Create year</button></div>
</form></section>
@endsection
