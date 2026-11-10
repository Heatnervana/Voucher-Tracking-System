import { watchVoucherAmounts } from './voucher-amounts';
import { watchSavedVoucherAmounts } from './saved-voucher-amounts';

function makeWarningDismissible(notice) {
    notice.classList.add('dismissible-notice');
    notice.setAttribute('role', 'alert');
    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'notice-close';
    closeButton.dataset.dismissNotice = '';
    closeButton.setAttribute('aria-label', 'Close warning');
    closeButton.textContent = '×';
    closeButton.addEventListener('click', () => {
        notice.hidden = true;
        const content = document.querySelector('main');
        if (content) {
            content.setAttribute('tabindex', '-1');
            content.focus({ preventScroll: true });
        }
    });
    notice.append(closeButton);
}

document.querySelectorAll('.notice.error').forEach(makeWarningDismissible);
watchSavedVoucherAmounts(message => {
    const notice = document.createElement('div');
    notice.className = 'notice error';
    notice.textContent = message;
    makeWarningDismissible(notice);
    document.querySelector('main').prepend(notice);
});

const voucherPreview = document.querySelector('#voucher-preview');

if (voucherPreview) {
    const previewAmount = voucherPreview.querySelector('[data-preview-amount]');
    const previewHeading = voucherPreview.querySelector('#voucher-preview-heading');
    const rows = [...document.querySelectorAll('[data-voucher-preview]')];
    let selectedRow = rows[0];
    const updatePreviewAmount = () => {
        previewAmount.textContent = selectedRow.querySelector('[data-saved-amount]').textContent.trim();
    };
    const amountObserver = new MutationObserver(updatePreviewAmount);
    const watchPreviewAmount = () => {
        amountObserver.disconnect();
        amountObserver.observe(selectedRow.querySelector('[data-saved-amount]'), {
            childList: true, characterData: true, subtree: true,
        });
        updatePreviewAmount();
    };

    voucherPreview.querySelector('[data-close-preview]').addEventListener('click', () => {
        voucherPreview.classList.remove('voucher-preview-open');
        selectedRow.querySelector('[data-select-preview]').focus();
    });

    for (const row of rows) {
        const button = row.querySelector('[data-select-preview]');
        button.hidden = false;
        button.addEventListener('click', () => {
            selectedRow.removeAttribute('data-preview-selected');
            selectedRow.querySelector('[data-select-preview]').setAttribute('aria-pressed', 'false');
            selectedRow = row;
            row.setAttribute('data-preview-selected', '');
            button.setAttribute('aria-pressed', 'true');

            const voucherLink = row.querySelector('.record-link');
            voucherPreview.querySelector('[data-preview-title]').textContent = row.querySelector('.voucher-payee').textContent.trim();
            voucherPreview.querySelector('[data-preview-dv]').textContent = voucherLink.textContent.trim();
            voucherPreview.querySelector('[data-preview-check]').textContent = row.querySelector('.voucher-check').textContent.trim();
            voucherPreview.querySelector('[data-preview-type]').textContent = row.dataset.previewFolder;
            voucherPreview.querySelector('[data-preview-period-value]').textContent = row.dataset.previewPeriod;
            voucherPreview.querySelector('[data-preview-open]').href = voucherLink.href;
            watchPreviewAmount();

            if (window.matchMedia('(max-width: 760px)').matches) {
                voucherPreview.classList.add('voucher-preview-open');
                previewHeading.focus();
            }
        });
        row.addEventListener('click', event => {
            if (!event.target.closest('a, button, input, label, form') && window.getSelection()?.isCollapsed) {
                button.click();
            }
        });
    }
    watchPreviewAmount();
}

const form = document.querySelector('#voucher-upload-form');

if (form) {
    const folderInput = form.querySelector('#voucher-folder-input');
    const filesInput = form.querySelector('#voucher-files-input');
    const selectionSummary = form.querySelector('#voucher-selection-summary');
    const progressPanel = form.querySelector('#voucher-upload-progress');
    const progressBar = form.querySelector('#voucher-progress-bar');
    const status = form.querySelector('#voucher-upload-status');
    const submitButton = form.querySelector('#voucher-upload-button');
    const csrfToken = form.querySelector('input[name="_token"]').value;
    const maxFileBytes = 512000 * 1024;
    const maxFolderBytes = 5_000_000_000;
    const maxChunkBytes = 900 * 1024 * 1024;
    const maxChunkFiles = 5;
    let activeInput = null;
    let uploadState = null;
    let busy = false;
    let uploadFinished = false;

    function formatBytes(bytes) {
        if (bytes < 1_000_000) return `${Math.ceil(bytes / 1_000)} KB`;
        if (bytes < 1_000_000_000) return `${(bytes / 1_000_000).toFixed(1)} MB`;
        return `${(bytes / 1_000_000_000).toFixed(2)} GB`;
    }

    function newId() {
        if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, character => {
            const random = Math.floor(Math.random() * 16);
            return (character === 'x' ? random : (random & 3) | 8).toString(16);
        });
    }

    function lockSelection(locked) {
        form.querySelectorAll('select, input[type="file"]').forEach(input => {
            input.disabled = locked;
        });
    }

    function showSelection() {
        const selected = Array.from(activeInput?.files ?? []);
        const pdfs = selected.filter(file => file.name.toLowerCase().endsWith('.pdf'));
        const skipped = selected.length - pdfs.length;
        const size = pdfs.reduce((total, file) => total + file.size, 0);
        const folderName = activeInput === folderInput && pdfs[0]?.webkitRelativePath
            ? pdfs[0].webkitRelativePath.split('/')[0]
            : null;
        const label = folderName ? `Folder: ${folderName}. ` : '';

        if (selected.length === 0) {
            selectionSummary.textContent = 'Select one folder or choose PDF files to begin.';
            return;
        }

        selectionSummary.textContent = `${label}${pdfs.length} PDF file(s), ${formatBytes(size)} total.${skipped ? ` ${skipped} non-PDF file(s) will be skipped.` : ''}`;
    }

    function makeChunks(files) {
        const chunks = [];
        let currentFiles = [];
        let currentSize = 0;

        files.forEach(file => {
            if (currentFiles.length && (currentFiles.length >= maxChunkFiles || currentSize + file.size > maxChunkBytes)) {
                chunks.push({ id: newId(), files: currentFiles, size: currentSize });
                currentFiles = [];
                currentSize = 0;
            }

            currentFiles.push(file);
            currentSize += file.size;
        });

        if (currentFiles.length) chunks.push({ id: newId(), files: currentFiles, size: currentSize });

        return chunks;
    }

    function request(url, data, onProgress) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', url);
            xhr.responseType = 'json';
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.upload.addEventListener('progress', event => {
                if (event.lengthComputable && onProgress) onProgress(event.loaded, event.total);
            });
            xhr.upload.addEventListener('load', () => {
                if (onProgress) status.textContent = 'Files received. Preparing your review...';
            });
            xhr.addEventListener('load', () => {
                const response = xhr.response ?? {};

                if (xhr.status >= 200 && xhr.status < 300) {
                    resolve(response);
                    return;
                }

                const validationError = Object.values(response.errors ?? {}).flat()[0];
                const error = new Error(response.message ?? validationError ?? `Upload failed (HTTP ${xhr.status}).`);
                error.status = xhr.status;
                error.code = response.code;
                error.receivedChunkIds = response.received_chunk_ids;
                reject(error);
            });
            xhr.addEventListener('error', () => reject(new Error('Connection interrupted. Retry to continue the upload.')));
            xhr.addEventListener('abort', () => reject(new Error('Upload was interrupted. Retry to continue.')));
            xhr.send(data);
        });
    }

    function updateProgress(completedBytes, activeBytes = 0, chunkIndex = 0) {
        const sent = Math.min(completedBytes + activeBytes, uploadState.totalBytes);
        const percent = uploadState.totalBytes ? Math.floor((sent / uploadState.totalBytes) * 100) : 100;

        progressBar.value = percent;
        status.textContent = `Uploading part ${Math.min(chunkIndex + 1, uploadState.chunks.length)} of ${uploadState.chunks.length} · ${percent}% (${formatBytes(sent)} of ${formatBytes(uploadState.totalBytes)})`;
    }

    function chooseFolderAction(message) {
        const warning = form.querySelector('#existing-folder-warning');
        warning.querySelector('[data-folder-warning-message]').textContent = message;
        warning.hidden = false;
        warning.querySelector('[data-folder-action="keep"]').focus();
        return new Promise(resolve => {
            const choose = event => {
                const button = event.target.closest('[data-folder-action]');
                if (!button) return;
                warning.removeEventListener('click', choose);
                warning.hidden = true;
                resolve(button.dataset.folderAction);
            };
            warning.addEventListener('click', choose);
        });
    }

    async function sendChunk(chunk, index, completedBytes) {
        const data = new FormData();
        data.append('_token', csrfToken);
        data.append('batch_id', uploadState.batchId);
        data.append('chunk_id', chunk.id);
        data.append('expected_files', chunk.files.length);
        chunk.files.forEach(file => {
            data.append('files[]', file, file.name);
            data.append('file_labels[]', file.webkitRelativePath || file.name);
        });

        for (let attempt = 0; attempt < 2; attempt++) {
            try {
                const result = await request(form.dataset.chunkUrl, data, loaded => updateProgress(completedBytes, Math.min(loaded, chunk.size), index));
                if (result.uploaded + (result.errors?.length ?? 0) !== chunk.files.length) {
                    throw new Error(`This part contained ${chunk.files.length} PDFs but the server accounted for ${result.uploaded ?? 0} uploaded and ${result.errors?.length ?? 0} rejected. Retry to check this part again.`);
                }
                uploadState.errors.push(...(result.errors ?? []));
                return;
            } catch (error) {
                if (error.status || attempt === 1) throw error;
                await new Promise(resolve => setTimeout(resolve, 800));
            }
        }
    }

    async function continueUpload() {
        busy = true;
        lockSelection(true);
        submitButton.disabled = true;
        submitButton.textContent = 'Uploading…';
        progressPanel.hidden = false;

        try {
            let completedBytes = uploadState.chunks.slice(0, uploadState.nextChunk).reduce((sum, chunk) => sum + chunk.size, 0);

            while (uploadState.nextChunk < uploadState.chunks.length) {
                const index = uploadState.nextChunk;
                const chunk = uploadState.chunks[index];
                status.textContent = `Uploading part ${index + 1} of ${uploadState.chunks.length}…`;
                if (!uploadState.receivedChunks?.has(chunk.id)) {
                    await sendChunk(chunk, index, completedBytes);
                }
                completedBytes += chunk.size;
                uploadState.nextChunk++;
                updateProgress(completedBytes, 0, index + 1);
            }

            const complete = new FormData();
            complete.append('_token', csrfToken);
            complete.append('batch_id', uploadState.batchId);
            status.textContent = 'Upload finished. Preparing the review page…';
            const response = await request(form.dataset.completeUrl, complete);
            progressBar.value = 100;

            if (uploadState.errors.length) {
                status.textContent = `${uploadState.errors.length} file(s) need attention. Opening the review page…`;
            }

            uploadFinished = true;
            window.location.assign(response.redirect);
        } catch (error) {
            if (error.code === 'incomplete_upload') {
                uploadState.receivedChunks = new Set(error.receivedChunkIds ?? []);
                uploadState.nextChunk = 0;
            }
            if (error.status === 422 && uploadState.nextChunk === uploadState.chunks.length) {
                activeInput.value = '';
                activeInput = null;
                uploadState = null;
                lockSelection(false);
                progressPanel.hidden = true;
                selectionSummary.textContent = error.message;
                submitButton.disabled = false;
                submitButton.textContent = 'Upload and review';
                busy = false;
                return;
            }

            if (error.status === 409 && error.code !== 'incomplete_upload') {
                uploadState = null;
                lockSelection(false);
                selectionSummary.textContent = 'This upload session expired. Start again to upload the selected files.';
                submitButton.textContent = 'Start upload again';
            }

            status.textContent = `${error.message} Click “Retry upload” to continue.`;
            submitButton.disabled = false;
            submitButton.textContent = 'Retry upload';
            busy = false;
        }
    }

    function chooseInput(input, otherInput) {
        otherInput.value = '';
        activeInput = input;
        uploadState = null;
        progressPanel.hidden = true;
        lockSelection(false);
        submitButton.disabled = false;
        submitButton.textContent = 'Upload and review';
        showSelection();
    }

    folderInput.addEventListener('change', () => chooseInput(folderInput, filesInput));
    filesInput.addEventListener('change', () => chooseInput(filesInput, folderInput));

    window.addEventListener('pageshow', event => {
        if (!event.persisted || !uploadFinished) return;
        folderInput.value = '';
        filesInput.value = '';
        activeInput = null;
        uploadState = null;
        uploadFinished = false;
        busy = false;
        lockSelection(false);
        progressPanel.hidden = true;
        submitButton.disabled = false;
        submitButton.textContent = 'Upload and review';
        showSelection();
    });

    form.addEventListener('submit', async event => {
        event.preventDefault();

        if (busy) return;

        if (uploadState) {
            await continueUpload();
            return;
        }

        const selected = Array.from(activeInput?.files ?? []);
        const pdfs = selected.filter(file => file.name.toLowerCase().endsWith('.pdf'));
        const oversized = pdfs.filter(file => file.size > maxFileBytes);
        const totalBytes = pdfs.reduce((total, file) => total + file.size, 0);

        if (pdfs.length === 0) {
            selectionSummary.textContent = 'No PDF files found. Choose a folder or select PDF vouchers.';
            return;
        }

        if (oversized.length) {
            selectionSummary.textContent = `${oversized.length} PDF file(s) exceed the 500 MB per-file limit. Remove or reduce those files before uploading.`;
            return;
        }

        if (totalBytes > maxFolderBytes) {
            selectionSummary.textContent = `This folder totals ${formatBytes(totalBytes)}. The maximum is 5 GB. Choose a smaller folder.`;
            return;
        }

        if (!form.reportValidity()) return;

        const start = new FormData();
        start.append('_token', csrfToken);
        start.append('year', form.elements.year.value);
        start.append('type', form.elements.type.value);
        start.append('month', form.elements.month.value);
        start.append('expected_files', pdfs.length);
        busy = true;
        lockSelection(true);
        submitButton.disabled = true;

        try {
            let started;
            try {
                started = await request(form.dataset.startUrl, start);
            } catch (error) {
                if (error.code !== 'existing_folder') throw error;
                const action = await chooseFolderAction(error.message);
                if (action === 'cancel') throw new Error('Upload canceled. Saved PDFs have not been changed.');
                start.append('folder_action', action);
                started = await request(form.dataset.startUrl, start);
            }
            uploadState = {
                batchId: started.batch_id,
                chunks: makeChunks(pdfs),
                totalBytes,
                nextChunk: 0,
                errors: [],
            };
            await continueUpload();
        } catch (error) {
            busy = false;
            lockSelection(false);
            submitButton.disabled = false;
            selectionSummary.textContent = error.message;
        }
    });
}

const deleteSelectedForm = document.querySelector('#delete-selected-form');

if (deleteSelectedForm) {
    const checkboxes = Array.from(document.querySelectorAll('[data-select-pdf]'));
    const selectPage = document.querySelector('#select-page-pdfs');
    const deleteButton = document.querySelector('#delete-selected-button');
    const selectionCount = document.querySelector('#selected-pdf-count');
    let submitting = false;

    function updateSelection() {
        const count = checkboxes.filter(checkbox => checkbox.checked).length;
        selectionCount.textContent = count + (count === 1 ? ' PDF selected' : ' PDFs selected');
        deleteButton.disabled = submitting || count === 0;
        selectPage.checked = count > 0 && count === checkboxes.length;
        selectPage.indeterminate = count > 0 && count < checkboxes.length;
    }

    selectPage.addEventListener('change', () => {
        checkboxes.forEach(checkbox => { checkbox.checked = selectPage.checked; });
        updateSelection();
    });
    checkboxes.forEach(checkbox => checkbox.addEventListener('change', updateSelection));

    document.querySelector('#record-management')?.addEventListener('toggle', event => {
        if (!event.currentTarget.open) {
            checkboxes.forEach(checkbox => { checkbox.checked = false; });
            updateSelection();
        }
    });

    deleteSelectedForm.addEventListener('submit', event => {
        const count = checkboxes.filter(checkbox => checkbox.checked).length;
        if (submitting || count === 0 || !window.confirm('Move the ' + count + ' selected PDF(s) and their voucher records to the recycle bin? You can restore them later.')) {
            event.preventDefault();
            return;
        }
        submitting = true;
        updateSelection();
    });

    window.addEventListener('pageshow', () => {
        submitting = false;
        checkboxes.forEach(checkbox => { checkbox.checked = false; });
        updateSelection();
    });
    updateSelection();
}

const recycleForm = document.querySelector('#recycle-selection-form');
if (recycleForm) {
    const boxes = [...document.querySelectorAll('[data-select-recycle]')];
    const selectAll = document.querySelector('#select-recycle-page');
    const buttons = [...recycleForm.querySelectorAll('[data-recycle-submit]')];
    let submitting = false;
    const update = () => {
        const count = boxes.filter(box => box.checked).length;
        document.querySelector('#recycle-selection-count').textContent = `${count} PDF(s) selected`;
        buttons.forEach(button => { button.disabled = count === 0; });
        selectAll.checked = count > 0 && count === boxes.length;
        selectAll.indeterminate = count > 0 && count < boxes.length;
    };
    selectAll.addEventListener('change', () => { boxes.forEach(box => { box.checked = selectAll.checked; }); update(); });
    boxes.forEach(box => box.addEventListener('change', update));
    recycleForm.addEventListener('submit', event => {
        if (submitting || !boxes.some(box => box.checked) || (event.submitter?.hasAttribute('data-recycle-purge') && !window.confirm('Permanently delete the selected PDFs? They cannot be restored from the recycle bin afterward.'))) {
            event.preventDefault();
            return;
        }
        submitting = true;
    });
    window.addEventListener('pageshow', () => { submitting = false; boxes.forEach(box => { box.checked = false; }); update(); });
    update();
}

const filenameForm = document.querySelector('#voucher-filename-form');

if (filenameForm) {
    const field = filenameForm.querySelector('[name="filename"]');
    const status = filenameForm.querySelector('[data-filename-status]');
    const button = filenameForm.querySelector('button[type="submit"]');
    filenameForm.addEventListener('submit', async event => {
        event.preventDefault();
        if (button.disabled || !filenameForm.reportValidity()) return;
        button.disabled = true;
        field.setAttribute('aria-invalid', 'false');
        status.textContent = 'Saving filename…';
        try {
            const response = await fetch(filenameForm.action, {
                method: 'POST', body: new FormData(filenameForm),
                headers: { Accept: 'application/json' }, credentials: 'same-origin',
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.errors?.filename?.[0] ?? result.message ?? 'The filename could not be saved. Please try again.');
            field.value = result.filename;
            document.querySelectorAll('[data-saved-filename]').forEach(element => { element.textContent = result.filename; });
            if (result.voucher) {
                for (const [selector, value] of [['[data-voucher-dv]', result.voucher.dv_number], ['[data-voucher-check]', result.voucher.checkada_no], ['[data-voucher-payee]', result.voucher.payee]]) {
                    document.querySelectorAll(selector).forEach(element => { element.textContent = value; });
                }
                document.title = `${result.voucher.dv_number} · PPA`;
                const deleteForm = document.querySelector('[data-voucher-delete]');
                if (deleteForm) deleteForm.dataset.dvNumber = result.voucher.dv_number;
            }
            status.textContent = result.message;
        } catch (error) {
            status.textContent = error instanceof SyntaxError ? 'The filename could not be saved. Refresh this page and try again.' : error.message;
            field.setAttribute('aria-invalid', 'true');
            field.focus();
        } finally {
            button.disabled = false;
        }
    });
}

const review = document.querySelector('#voucher-review');

if (review) {
    let rows = Array.from(review.querySelectorAll('[data-review-item]'));
    const pageSize = Number(review.dataset.pageSize) || 10;
    const pagination = review.querySelector('#review-pagination');
    const pageNumbers = review.querySelector('#review-page-numbers');
    const previous = review.querySelector('#review-previous');
    const next = review.querySelector('#review-next');
    const pageStatus = review.querySelector('#review-page-status');
    const fileCount = review.querySelector('#review-file-count');
    const removeStatus = review.querySelector('#review-remove-status');
    const finalizeForm = review.querySelector('#voucher-finalize-form');
    const saveButton = finalizeForm.querySelector('button.primary[type="submit"]');
    const csrfToken = finalizeForm.querySelector('input[name="_token"]').value;
    const batchId = review.dataset.batchId;
    let page = 1;
    let detailsInput;
    let submitting = false;
    finalizeForm.noValidate = true;

    function renderPageNumbers(totalPages) {
        pageNumbers.replaceChildren();
        const wanted = new Set([1, totalPages, page - 1, page, page + 1]);
        const visible = [...wanted].filter(number => number >= 1 && number <= totalPages).sort((a, b) => a - b);
        let last = 0;

        visible.forEach(number => {
            if (number - last > 1) {
                const ellipsis = document.createElement('span');
                ellipsis.className = 'review-ellipsis';
                ellipsis.textContent = '…';
                pageNumbers.append(ellipsis);
            }

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'button secondary review-page-button';
            button.textContent = String(number);
            button.setAttribute('aria-label', `Page ${number}`);
            if (number === page) button.setAttribute('aria-current', 'page');
            button.addEventListener('click', () => {
                page = number;
                render();
                review.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
            pageNumbers.append(button);
            last = number;
        });
    }

    function render() {
        const totalPages = Math.ceil(rows.length / pageSize);
        page = Math.max(1, Math.min(page, totalPages || 1));
        rows.forEach((row, index) => {
            row.hidden = Math.floor(index / pageSize) + 1 !== page;
        });
        pagination.hidden = totalPages <= 1;
        previous.disabled = page <= 1;
        next.disabled = page >= totalPages;
        pageStatus.textContent = `Page ${page} of ${totalPages || 1}`;
        fileCount.textContent = String(rows.length);
        saveButton.disabled = rows.length === 0;
        renderPageNumbers(totalPages);
    }

    previous.addEventListener('click', () => {
        if (page > 1) {
            page -= 1;
            render();
            review.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });

    next.addEventListener('click', () => {
        if (page < Math.ceil(rows.length / pageSize)) {
            page += 1;
            render();
            review.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });

    finalizeForm.addEventListener('submit', event => {
        if (event.defaultPrevented) return;
        if (submitting || rows.length === 0) {
            event.preventDefault();
            return;
        }

        const fields = rows.flatMap(row => Array.from(row.querySelectorAll('input')));
        const invalid = fields.find(input => !input.checkValidity());
        if (invalid) {
            event.preventDefault();
            const rowIndex = rows.findIndex(row => Array.from(row.querySelectorAll('input')).includes(invalid));
            page = Math.floor(rowIndex / pageSize) + 1;
            render();
            invalid.focus();
            invalid.reportValidity();
            return;
        }

        const details = Object.fromEntries(rows.map(row => [row.dataset.fileId, Object.fromEntries(
            Array.from(row.querySelectorAll('input')).map(input => [input.name.slice(input.name.lastIndexOf('[') + 1, -1), input.value])
        )]));
        detailsInput = document.createElement('input');
        detailsInput.type = 'hidden';
        detailsInput.name = 'voucher_details';
        detailsInput.value = JSON.stringify(details);
        finalizeForm.append(detailsInput);
        fields.forEach(input => { input.disabled = true; });
        submitting = true;
        saveButton.disabled = true;
    });

    window.addEventListener('pageshow', event => {
        if (!event.persisted) return;
        rows.forEach(row => row.querySelectorAll('input').forEach(input => { input.disabled = false; }));
        detailsInput?.remove();
        detailsInput = null;
        submitting = false;
        render();
    });

    review.addEventListener('click', async event => {
        const button = event.target.closest('[data-remove-file]');
        if (!button || !confirm('Remove this PDF from the unsaved folder upload?')) return;

        button.disabled = true;
        removeStatus.textContent = 'Removing PDF…';
        const body = new FormData();
        body.append('_token', csrfToken);
        body.append('batch_id', batchId);
        body.append('file_id', button.dataset.removeFile);

        try {
            const response = await fetch(review.dataset.removeUrl, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body,
                credentials: 'same-origin',
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || 'The PDF could not be removed.');

            const row = button.closest('[data-review-item]');
            rows = rows.filter(item => item !== row);
            row.remove();
            removeStatus.textContent = 'PDF removed from this upload.';
            if (result.redirect) {
                window.location.assign(result.redirect);
                return;
            }
            render();
        } catch (error) {
            button.disabled = false;
            removeStatus.textContent = error.message || 'The PDF could not be removed. Please try again.';
        }
    });

    render();
    watchVoucherAmounts(review);
}
