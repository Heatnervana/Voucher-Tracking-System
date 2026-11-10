@extends('layouts.app')
@section('title', 'Review vouchers')
@section('content')
<a class="back-link" href="{{ route('vouchers.create') }}">Back to upload</a>
<section id="voucher-review" class="form-card wide-card" data-batch-id="{{ $upload['batch_id'] ?? '' }}" data-page-size="10" data-remove-url="{{ route('vouchers.upload.remove-file') }}" data-amounts-url="{{ route('vouchers.upload.amounts', ['batch_id' => $upload['batch_id']]) }}">
    <h1>Review voucher details</h1>
    <p class="muted">Check the details and amount for every PDF. You can remove individual PDFs or the whole unsaved folder upload before saving.</p>
    <div class="folder-summary">
        <span>Saving to <strong>{{ $upload['year'] }} / {{ $upload['type'] }} / {{ sprintf('%02d', $upload['month']) }}</strong></span>
        <span><strong id="review-file-count">{{ count($upload['files']) }}</strong> PDF file(s)</span>
    </div>
    @if (isset($upload['expected_files']))
        <p class="hint">{{ $upload['expected_files'] }} selected · {{ count($upload['errors'] ?? []) }} rejected. {{ count($upload['files']) }} PDF(s) available for review.</p>
    @endif
    @if (! empty($upload['errors']) && ! session('upload_errors'))
        <div class="notice error"><strong>Files that were not uploaded</strong><ul>@foreach ($upload['errors'] as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <form id="voucher-finalize-form" method="POST" action="{{ route('vouchers.finalize') }}" class="stack">
        @csrf
        <input type="hidden" name="batch_id" value="{{ $upload['batch_id'] }}">
        @if (($upload['folder_action'] ?? 'keep') === 'replace')
            <div class="notice folder-warning"><strong>Replacement enabled for this month.</strong> Saving will replace PDFs whose Check / ADA and DV numbers both match in {{ $upload['year'] }} / {{ $upload['type'] }} / {{ sprintf('%02d', $upload['month']) }}. Other saved PDFs will stay in the library.</div>
        @endif
        <div id="review-file-list" class="review-file-list">
            @foreach ($upload['files'] as $id => $file)
                <article class="review-row" data-review-item data-file-id="{{ $id }}" data-amount-status="{{ $file['amount_status'] }}">
                    <div class="review-file">
                        <span class="file-badge">PDF</span>
                        <strong>{{ $file['original_name'] }}</strong>
                        <a class="preview-pdf" href="{{ route('vouchers.upload.preview', ['batch_id' => $upload['batch_id'], 'file_id' => $id]) }}" target="_blank" rel="noopener">Preview PDF (opens in new tab)</a>
                    </div>
                    @if ($file['checkada_no'] === '' || $file['dv_number'] === '' || $file['payee'] === '')
                        <p class="review-warning">We couldn't read all details from this filename. Please fill in the missing information.</p>
                    @endif
                    <div class="review-fields">
                        <label>Check / ADA number
                            <input name="vouchers[{{ $id }}][checkada_no]" value="{{ old('vouchers.'.$id.'.checkada_no', $file['checkada_no']) }}" pattern="[A-Za-z0-9\-]{1,40}" maxlength="40" required>
                            <small>Use letters, numbers, or hyphens. A duplicate has both the same Check / ADA number and the same DV number as a saved voucher.</small>
                        </label>
                        <label>DV number
                            <input name="vouchers[{{ $id }}][dv_number]" value="{{ old('vouchers.'.$id.'.dv_number', $file['dv_number']) }}" inputmode="numeric" pattern="[0-9]{6,12}" maxlength="12" required>
                        </label>
                        <label class="wide-field">Payee
                            <input name="vouchers[{{ $id }}][payee]" value="{{ old('vouchers.'.$id.'.payee', $file['payee']) }}" maxlength="255" required>
                        </label>
                        <label>Amount (PHP, optional)
                            <input data-voucher-amount name="vouchers[{{ $id }}][amount]" type="number" min="0.01" max="9999999999999.99" step="0.01" inputmode="decimal" value="{{ old('vouchers.'.$id.'.amount', $file['detected_amount']) }}">
                            <small>You can save with a blank amount. If reading fails, the bell will remind you to add it.</small>
                            <small data-amount-help role="status" aria-live="polite">{{ $file['amount_status'] === 'pending' ? 'Reading amount in the background. Reading will continue after you save.' : ($file['detected_amount'] ? 'Automatically read from the PDF. Please check before saving.' : 'The amount could not be read confidently. Enter it yourself or leave it blank to add later.') }}</small>
                        </label>
                    </div>
                    <button class="button secondary remove-staged-file" type="button" data-remove-file="{{ $id }}">Remove this PDF from upload</button>
                </article>
            @endforeach
        </div>

        <nav id="review-pagination" class="review-pagination" aria-label="Voucher review pages">
            <button class="button secondary" id="review-previous" type="button">Previous</button>
            <div id="review-page-numbers" class="review-page-numbers"></div>
            <button class="button secondary" id="review-next" type="button">Next</button>
            <p id="review-page-status" role="status" aria-live="polite"></p>
        </nav>

        <p id="review-remove-status" class="hint" role="status" aria-live="polite"></p>
        <div class="button-row">
            <button class="button secondary" type="submit" form="cancel-upload">Remove entire folder upload</button>
            <button class="button primary" type="submit" disabled>Save vouchers</button>
        </div>
        <noscript><p class="notice error">Enable JavaScript in your browser to review and save these vouchers.</p></noscript>
    </form>
</section>
<form id="cancel-upload" method="POST" action="{{ route('vouchers.cancel-upload') }}" onsubmit="return confirm('Remove all PDFs from this unsaved folder upload?');">@csrf @method('DELETE')<input type="hidden" name="batch_id" value="{{ $upload['batch_id'] }}"></form>
@endsection
