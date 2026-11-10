import test from 'node:test';
import assert from 'node:assert/strict';
import { watchVoucherAmounts } from '../resources/js/voucher-amounts.js';

function setup(t, initialValue = '') {
    const input = new EventTarget();
    input.value = initialValue;
    const help = { textContent: '' };
    const row = {
        isConnected: true,
        dataset: { fileId: 'sample', amountStatus: 'pending' },
        querySelector: selector => selector === '[data-voucher-amount]' ? input : help,
    };
    const form = new EventTarget();
    const review = {
        dataset: { amountsUrl: '/amounts?batch_id=private-batch' },
        querySelectorAll: () => [row],
        querySelector: () => form,
    };
    let resolveFetch;
    let rejectFetch;
    const timers = [];
    t.mock.method(globalThis, 'setTimeout', (callback, delay) => { timers.push({ callback, delay }); return timers.length; });
    t.mock.method(globalThis, 'clearTimeout', () => {});
    const previousWindow = globalThis.window;
    globalThis.window = new EventTarget();
    t.after(() => { globalThis.window = previousWindow; });
    t.mock.method(globalThis, 'fetch', () => new Promise((resolve, reject) => { resolveFetch = resolve; rejectFetch = reject; }));
    const stop = watchVoucherAmounts(review);
    t.after(stop);

    return {
        row, input, help, form, timers, rejectFetch,
        finish: async (status = 'ready', amount = '5250.00') => {
            resolveFetch({ ok: true, json: async () => ({ files: { sample: { status, amount } } }) });
            await new Promise(resolve => setImmediate(resolve));
        },
    };
}

test('the completed background amount fills an untouched field', async t => {
    const ui = setup(t);
    await ui.finish();
    assert.equal(ui.input.value, '5250.00');
    assert.match(ui.help.textContent, /Automatically read/);
});

test('an amount typed while reading is pending is preserved', async t => {
    const ui = setup(t);
    ui.input.value = '5300.00';
    ui.input.dispatchEvent(new Event('input'));
    await ui.finish();
    assert.equal(ui.input.value, '5300.00');
    assert.match(ui.help.textContent, /Your entered amount/);
});

test('an existing amount or deliberately cleared field is preserved', async t => {
    const ui = setup(t, '5200.00');
    ui.input.value = '';
    ui.input.dispatchEvent(new Event('input'));
    await ui.finish();
    assert.equal(ui.input.value, '');
    assert.match(ui.help.textContent, /save now and add it later/);
});

test('a removed PDF receives no late update', async t => {
    const ui = setup(t);
    ui.row.isConnected = false;
    await ui.finish();
    assert.equal(ui.input.value, '');
});

test('an unreadable amount explains saving without an amount', async t => {
    const ui = setup(t);
    await ui.finish('manual', null);
    assert.equal(ui.input.value, '');
    assert.match(ui.help.textContent, /leave it blank to add later/);
});

test('a stopped worker leaves manual entry available while polling continues', async t => {
    const ui = setup(t);
    await ui.finish('pending', null);
    ui.timers.find(timer => timer.delay === 60000).callback();
    assert.match(ui.help.textContent, /leave it blank and save now/);
    assert.ok(ui.timers.some(timer => timer.delay === 2000));
});

test('saving ignores a late background response', async t => {
    const ui = setup(t);
    ui.form.dispatchEvent(new Event('submit'));
    await ui.finish();
    assert.equal(ui.input.value, '');
});

test('a cancelled review submission keeps background amount reading active', async t => {
    const ui = setup(t);
    const submission = new Event('submit', { cancelable: true });
    submission.preventDefault();
    ui.form.dispatchEvent(submission);
    await ui.finish();
    assert.equal(ui.input.value, '5250.00');
});

test('network failure keeps manual entry and retries', async t => {
    const ui = setup(t);
    ui.rejectFetch(new Error('Disconnected'));
    await new Promise(resolve => setImmediate(resolve));
    assert.match(ui.help.textContent, /You can enter the amount yourself/);
    assert.ok(ui.timers.some(timer => timer.delay === 2000));
});

test('browser Back resumes pending amount reading after a cached page is restored', async t => {
    const ui = setup(t);
    window.dispatchEvent(new Event('pagehide'));
    const restored = new Event('pageshow');
    Object.defineProperty(restored, 'persisted', { value: true });
    window.dispatchEvent(restored);
    await ui.finish();
    assert.equal(ui.input.value, '5250.00');
});
