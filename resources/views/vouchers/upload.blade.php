@extends('layouts.app')
@section('title', 'Upload vouchers')
@section('content')
<a class="back-link" href="{{ route('vouchers.index') }}">← Voucher library</a>
<section class="form-card">
    <p class="eyebrow">ADD RECORDS</p>
    <h1>Upload vouchers</h1>
    <p class="muted">Choose a month folder or select PDF vouchers. The folder can contain up to 5 GB in total.</p>

    <form id="voucher-upload-form" method="POST" action="{{ route('vouchers.store') }}" enctype="multipart/form-data" class="stack"
        data-start-url="{{ route('vouchers.upload.start') }}"
        data-chunk-url="{{ route('vouchers.upload.chunk') }}"
        data-complete-url="{{ route('vouchers.upload.complete') }}">
        @csrf
        <div class="form-grid">
            <label>Year
                <select name="year" required>
                    @forelse ($years as $year)
                        <option value="{{ $year }}" @selected(old('year', $years->first()) == $year)>{{ $year }}</option>
                    @empty
                        <option value="">Create a year first</option>
                    @endforelse
                </select>
            </label>
            <label>Type
                <select name="type" required>
                    <option value="ADA">ADA</option>
                    <option value="CHECK">CHECK</option>
                </select>
            </label>
            <label>Month
                <select name="month" required>
                    @foreach (range(1, 12) as $month)
                        <option value="{{ $month }}" @selected($month === now()->month)>{{ \Carbon\Carbon::create()->month($month)->format('F') }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <div class="upload-choices">
            <label class="file-drop upload-choice">
                <span class="upload-icon">▣</span>
                <strong>Choose a month folder</strong>
                <span>Select the folder once. PDF files inside it and its subfolders will be included.</span>
                <input id="voucher-folder-input" type="file" name="files[]" accept="application/pdf,.pdf" webkitdirectory directory multiple>
            </label>
            <label class="file-drop upload-choice">
                <span class="upload-icon">↑</span>
                <strong>Select PDF vouchers</strong>
                <span>Choose individual PDFs instead, up to 500 MB each.</span>
                <input id="voucher-files-input" type="file" name="files[]" accept="application/pdf,.pdf" multiple>
            </label>
        </div>

        <p id="voucher-selection-summary" class="hint" role="status" aria-live="polite">Select one folder or choose PDF files to begin.</p>
        <p class="hint">Files upload in smaller parts. Keep this page open until the upload finishes. You can review and correct voucher details before saving.</p>

        <div id="voucher-upload-progress" class="upload-progress" hidden>
            <label for="voucher-progress-bar">Upload progress</label>
            <progress id="voucher-progress-bar" max="100" value="0"></progress>
            <p id="voucher-upload-status" role="status" aria-live="polite"></p>
        </div>
        <div id="existing-folder-warning" class="notice folder-warning" role="alert" hidden>
            <strong>This month already has saved PDFs</strong>
            <p data-folder-warning-message></p>
            <p>Replace a PDF when both its Check / ADA and DV numbers match. Keep other saved PDFs. Changes apply when you save the reviewed vouchers.</p>
            <div class="button-row">
                <button class="button secondary" type="button" data-folder-action="cancel">Cancel upload</button>
                <button class="button secondary" type="button" data-folder-action="keep">Keep saved PDFs</button>
                <button class="button primary" type="button" data-folder-action="replace">Replace matching PDFs</button>
            </div>
        </div>

        <div class="button-row">
            <a class="button secondary" href="{{ route('vouchers.index') }}">Cancel</a>
            <button id="voucher-upload-button" class="button primary" type="submit">Upload and review</button>
        </div>
    </form>
</section>
@endsection
