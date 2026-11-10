export function watchVoucherAmounts(review) {
    const fields = Array.from(review.querySelectorAll('[data-review-item]')).map(row => {
        const input = row.querySelector('[data-voucher-amount]');
        const field = { row, input, edited: input.value !== '' };
        input.addEventListener('input', () => { field.edited = true; });
        return field;
    });
    const pending = () => fields.filter(field => field.row.isConnected && field.row.dataset.amountStatus === 'pending');
    let stopped = true;
    let timer;
    let slowTimer;
    let controller;

    function stop() {
        stopped = true;
        clearTimeout(timer);
        clearTimeout(slowTimer);
        controller?.abort();
    }

    async function poll() {
        if (stopped || pending().length === 0) return;
        const activeController = controller;
        try {
            const response = await fetch(review.dataset.amountsUrl, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
                signal: activeController.signal,
            });
            if (!response.ok) throw new Error('Amount reading is unavailable. Enter it yourself or leave it blank to add later.');
            const result = await response.json();
            if (stopped || activeController !== controller) return;

            pending().forEach(field => {
                const amount = result.files[field.row.dataset.fileId];
                if (!amount || amount.status === 'pending') return;
                field.row.dataset.amountStatus = amount.status;
                if (amount.status === 'ready' && !field.edited && field.input.value === '') {
                    field.input.value = amount.amount;
                }
                field.row.querySelector('[data-amount-help]').textContent = field.edited
                    ? (field.input.value === '' ? 'Amount left blank. You can save now and add it later.' : 'Your entered amount will be saved. Please check it against the PDF.')
                    : amount.status === 'ready'
                        ? 'Automatically read from the PDF. Please check before saving.'
                        : 'The amount could not be read confidently. Enter it yourself or leave it blank to add later.';
            });
        } catch (error) {
            if (stopped || activeController !== controller) return;
            pending().forEach(field => {
                field.row.querySelector('[data-amount-help]').textContent = 'Could not check amount reading. You can enter the amount yourself or leave it blank to add later; checking will continue.';
            });
        }
        if (!stopped && pending().length > 0) timer = setTimeout(poll, 2000);
        else clearTimeout(slowTimer);
    }

    function start() {
        if (!stopped || pending().length === 0) return;
        stopped = false;
        controller = new AbortController();
        slowTimer = setTimeout(() => {
            pending().forEach(field => {
                field.row.querySelector('[data-amount-help]').textContent = 'Still waiting for the amount reader. You can enter the amount yourself or leave it blank and save now. Reading will continue after saving.';
            });
        }, 60000);
        poll();
    }

    window.addEventListener('pagehide', stop);
    window.addEventListener('pageshow', event => {
        if (event.persisted) start();
    });
    review.querySelector('#voucher-finalize-form').addEventListener('submit', event => {
        if (!event.defaultPrevented) stop();
    });
    start();
    return stop;
}
