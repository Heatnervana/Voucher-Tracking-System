import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../resources/js/app.js', import.meta.url), 'utf8').replace(/^import[^\n]*\n/gm, '');

class Element extends EventTarget {
    constructor(properties = {}) {
        super();
        Object.assign(this, { children: [], dataset: {}, disabled: false, hidden: false, value: '', name: '' }, properties);
    }

    append(child) { this.children.push(child); child.parent = this; }
    replaceChildren() { this.children = []; }
    setAttribute(name, value) { this[name] = value; }
    remove() { this.parent.children = this.parent.children.filter(child => child !== this); }
    focus() { this.focused = true; }
    reportValidity() { this.reported = true; return this.valid !== false; }
    checkValidity() { return this.valid !== false; }
    scrollIntoView() {}
}

function filenameSetup(response) {
    const field = new Element({ value: 'Cancelled' });
    const status = new Element({ textContent: '' });
    const button = new Element();
    const savedFilename = new Element({ textContent: 'original.pdf' });
    const dv = new Element({ textContent: '00000000111' });
    const check = new Element({ textContent: 'CANCELLED' });
    const payee = new Element({ textContent: 'Old Payee' });
    const deleteForm = new Element({ dataset: { dvNumber: '00000000111' } });
    const labels = { '[data-saved-filename]': [savedFilename], '[data-voucher-dv]': [dv], '[data-voucher-check]': [check], '[data-voucher-payee]': [payee] };
    const form = new Element({ action: '/vouchers/1/filename',
        querySelector: selector => selector === '[name="filename"]' ? field : selector === '[data-filename-status]' ? status : button });
    const calls = [];
    vm.runInNewContext(source, {
        document: { querySelectorAll: selector => labels[selector] ?? [],
            querySelector: selector => selector === '#voucher-filename-form' ? form : selector === '[data-voucher-delete]' ? deleteForm : null },
        window: new EventTarget(), watchSavedVoucherAmounts() {},
        FormData: class { constructor(value) { this.form = value; } },
        fetch: async (url, options) => { calls.push({ url, options }); return response; },
    });
    return { field, status, button, savedFilename, form, calls, dv, check, payee, deleteForm };
}

test('structured renaming updates the DV heading, Check number, payee and delete confirmation immediately', async () => {
    const ui = filenameSetup({ ok: true, json: async () => ({ filename: '11033_11033221_CANCELLED.pdf',
        voucher: { dv_number: '11033221', checkada_no: '11033', payee: 'CANCELLED' }, message: 'Saved PDF filename updated.' }) });
    ui.form.dispatchEvent(new Event('submit', { cancelable: true }));
    await new Promise(setImmediate);
    assert.equal(ui.dv.textContent, '11033221');
    assert.equal(ui.check.textContent, '11033');
    assert.equal(ui.payee.textContent, 'CANCELLED');
    assert.equal(ui.deleteForm.dataset.dvNumber, '11033221');
});

test('saving a filename updates the displayed name and keeps confirmation beside the field', async () => {
    const ui = filenameSetup({ ok: true, json: async () => ({ filename: 'Cancelled.pdf', message: 'Saved PDF filename updated.' }) });
    ui.form.dispatchEvent(new Event('submit', { cancelable: true }));
    await new Promise(setImmediate);
    assert.equal(ui.calls.length, 1);
    assert.equal(ui.calls[0].options.headers.Accept, 'application/json');
    assert.equal(ui.field.value, 'Cancelled.pdf');
    assert.equal(ui.savedFilename.textContent, 'Cancelled.pdf');
    assert.equal(ui.status.textContent, 'Saved PDF filename updated.');
    assert.equal(ui.button.disabled, false);
});

test('a rejected filename shows its exact error beside the field and permits correction', async () => {
    const ui = filenameSetup({ ok: false, json: async () => ({ errors: { filename: ['That filename already exists.'] } }) });
    ui.form.dispatchEvent(new Event('submit', { cancelable: true }));
    await new Promise(setImmediate);
    assert.equal(ui.status.textContent, 'That filename already exists.');
    assert.equal(ui.field.focused, true);
    assert.equal(ui.field['aria-invalid'], 'true');
    assert.equal(ui.field.value, 'Cancelled');
    assert.equal(ui.savedFilename.textContent, 'original.pdf');
    assert.equal(ui.button.disabled, false);
});

function reviewSetup(count = 11) {
    const rows = Array.from({ length: count }, (_, index) => {
        const values = [`ADA-${index}`, String(100000 + index), `Payee ${index}`, '123.45'];
        const fields = ['checkada_no', 'dv_number', 'payee', 'amount'].map((name, field) => new Element({ name: `vouchers[file-${index}][${name}]`, value: values[field] }));
        return new Element({ dataset: { fileId: `file-${index}` }, fields, querySelectorAll: () => fields });
    });
    const form = new Element();
    const save = new Element();
    form.querySelector = selector => selector.startsWith('button') ? save : { value: 'csrf' };
    form.querySelectorAll = () => rows.flatMap(row => row.fields);
    const selectors = Object.fromEntries(['review-pagination', 'review-page-numbers', 'review-previous', 'review-next', 'review-page-status', 'review-file-count', 'review-remove-status'].map(id => [`#${id}`, new Element()]));
    selectors['#voucher-finalize-form'] = form;
    const review = new Element({ dataset: { pageSize: '10', batchId: 'batch' }, querySelectorAll: () => rows, querySelector: selector => selectors[selector] });
    const window = new EventTarget();
    vm.runInNewContext(source, {
        document: { querySelectorAll: () => [], querySelector: selector => selector === '#voucher-review' ? review : null, createElement: () => new Element() },
        window, watchVoucherAmounts() {}, watchSavedVoucherAmounts() {},
    });
    return { form, rows, window, save };
}

test('saving reveals and reports an invalid required field on another review page', () => {
    const { form, rows } = reviewSetup();
    const invalid = rows[10].fields[3];
    invalid.valid = false;
    const event = new Event('submit', { cancelable: true });
    form.dispatchEvent(event);
    assert.equal(event.defaultPrevented, true);
    assert.equal(rows[10].hidden, false);
    assert.equal(invalid.focused, true);
    assert.equal(invalid.reported, true);
    assert.ok(rows.every(row => row.fields.every(field => !field.disabled)));
});

test('a large review submits every voucher in one JSON form field', () => {
    const { form, rows } = reviewSetup(300);
    const event = new Event('submit', { cancelable: true });
    form.dispatchEvent(event);
    assert.equal(event.defaultPrevented, false);
    const payload = form.children.find(input => input.name === 'voucher_details');
    assert.ok(payload);
    const details = JSON.parse(payload.value);
    assert.equal(Object.keys(details).length, 300);
    assert.deepEqual(details['file-299'], { checkada_no: 'ADA-299', dv_number: '100299', payee: 'Payee 299', amount: '123.45' });
    assert.ok(rows.every(row => row.fields.every(field => field.disabled)));
});

test('cached Back restores editable review fields after native submission', () => {
    const { form, rows, window, save } = reviewSetup(1);
    form.dispatchEvent(new Event('submit', { cancelable: true }));
    assert.ok(rows[0].fields.every(field => field.disabled));
    const restored = new Event('pageshow');
    Object.defineProperty(restored, 'persisted', { value: true });
    window.dispatchEvent(restored);
    assert.ok(rows.every(row => row.fields.every(field => !field.disabled)));
    assert.equal(form.children.some(input => input.name === 'voucher_details'), false);
    assert.equal(save.disabled, false);
});

test('cached Back unlocks a completed upload and permits another upload', async () => {
    const folder = new Element({ files: [] });
    const files = new Element({ files: [new File(['%PDF-test'], 'voucher.pdf', { type: 'application/pdf' })] });
    const button = new Element();
    const selectors = { '#voucher-folder-input': folder, '#voucher-files-input': files, '#voucher-selection-summary': new Element(), '#voucher-upload-progress': new Element(), '#voucher-progress-bar': new Element(), '#voucher-upload-status': new Element(), '#voucher-upload-button': button };
    const form = new Element({
        dataset: { startUrl: '/start', chunkUrl: '/chunk', completeUrl: '/complete' },
        elements: { year: { value: '2026' }, type: { value: 'ADA' }, month: { value: '1' } },
        querySelector: selector => selectors[selector] ?? { value: 'csrf' }, querySelectorAll: () => [folder, files],
    });
    let resolveNavigation;
    let navigation = new Promise(resolve => { resolveNavigation = resolve; });
    let starts = 0;
    const window = Object.assign(new EventTarget(), { location: { assign: () => resolveNavigation() } });
    class Request extends EventTarget {
        constructor() { super(); this.upload = new EventTarget(); this.status = 200; }
        open(method, url) { this.url = url; }
        setRequestHeader() {}
        send(data) {
            if (this.url === '/start') starts++;
            this.response = this.url === '/start' ? { batch_id: 'batch' } : this.url === '/complete' ? { redirect: '/review' } : { uploaded: data.getAll('files[]').length, errors: [] };
            this.dispatchEvent(new Event('load'));
        }
    }
    vm.runInNewContext(source, { document: { querySelectorAll: () => [], querySelector: selector => selector === '#voucher-upload-form' ? form : null }, window, watchSavedVoucherAmounts() {}, XMLHttpRequest: Request, FormData, setTimeout, clearTimeout });
    files.dispatchEvent(new Event('change'));
    form.dispatchEvent(new Event('submit', { cancelable: true }));
    await navigation;
    const restored = new Event('pageshow');
    Object.defineProperty(restored, 'persisted', { value: true });
    window.dispatchEvent(restored);
    assert.equal(folder.disabled, false);
    assert.equal(files.disabled, false);
    assert.equal(button.disabled, false);
    navigation = new Promise(resolve => { resolveNavigation = resolve; });
    files.dispatchEvent(new Event('change'));
    form.dispatchEvent(new Event('submit', { cancelable: true }));
    await navigation;
    assert.equal(starts, 2);
});

test('retrying a 103 PDF upload resends only the missing five-file part', async () => {
    const folder = new Element({ files: [] });
    const files = new Element({ files: Array.from({ length: 103 }, (_, index) => new File(['%PDF-test'], `voucher-${index}.pdf`, { type: 'application/pdf' })) });
    const button = new Element();
    const selectors = { '#voucher-folder-input': folder, '#voucher-files-input': files, '#voucher-selection-summary': new Element(), '#voucher-upload-progress': new Element(), '#voucher-progress-bar': new Element(), '#voucher-upload-status': new Element(), '#voucher-upload-button': button };
    const form = new Element({
        dataset: { startUrl: '/start', chunkUrl: '/chunk', completeUrl: '/complete' },
        elements: { year: { value: '2026' }, type: { value: 'ADA' }, month: { value: '1' } },
        querySelector: selector => selectors[selector] ?? { value: 'csrf' }, querySelectorAll: () => [folder, files],
    });
    const received = new Map();
    const sent = [];
    let completions = 0;
    let navigated = false;
    let lostId;
    const window = Object.assign(new EventTarget(), { location: { assign: () => { navigated = true; } } });
    class Request extends EventTarget {
        constructor() { super(); this.upload = new EventTarget(); this.status = 200; }
        open(method, url) { this.url = url; }
        setRequestHeader() {}
        send(data) {
            if (this.url === '/start') this.response = { batch_id: 'batch' };
            if (this.url === '/chunk') {
                const id = data.get('chunk_id');
                sent.push(id);
                received.set(id, data.getAll('files[]').length);
                this.response = { uploaded: received.get(id), errors: [] };
            }
            if (this.url === '/complete') {
                if (completions++ === 0) {
                    lostId = [...received.keys()][10];
                    received.delete(lostId);
                }
                const total = [...received.values()].reduce((sum, count) => sum + count, 0);
                this.status = total === 103 ? 200 : 409;
                this.response = total === 103 ? { redirect: '/review' } : {
                    code: 'incomplete_upload', message: `Expected 103 PDFs but received ${total}.`,
                    received_chunk_ids: [...received.keys()],
                };
            }
            this.dispatchEvent(new Event('load'));
        }
    }
    vm.runInNewContext(source, { document: { querySelectorAll: () => [], querySelector: selector => selector === '#voucher-upload-form' ? form : null }, window, watchSavedVoucherAmounts() {}, XMLHttpRequest: Request, FormData, setTimeout, clearTimeout });
    files.dispatchEvent(new Event('change'));
    form.dispatchEvent(new Event('submit', { cancelable: true }));
    await new Promise(setImmediate);
    assert.equal(button.textContent, 'Retry upload');
    assert.equal(sent.length, 21);
    form.dispatchEvent(new Event('submit', { cancelable: true }));
    await new Promise(setImmediate);
    assert.equal(navigated, true, 'Retry must recover the missing part and reach the review page.');
    assert.equal(sent.length, 22, 'Previously received parts must not be sent again.');
    assert.equal(sent.at(-1), lostId);
    assert.equal([...received.values()].reduce((sum, count) => sum + count, 0), 103);
});
