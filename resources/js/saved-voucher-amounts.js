export function watchSavedVoucherAmounts(showWarning) {
    const endpoint = document.body.dataset.savedAmountsUrl;
    const amounts = [...document.querySelectorAll('[data-saved-amount]')];
    const bell = document.querySelector('#amount-notification');
    if (!endpoint || (!bell && amounts.length === 0)) return;

    const url = new URL(endpoint, window.location.href);
    [...new Set(amounts.map(element => element.dataset.savedVoucherId))]
        .slice(0, 20).forEach(id => url.searchParams.append('ids[]', id));
    const formatter = new Intl.NumberFormat('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    let timer;
    let controller;
    let stopped = false;
    let warned = false;

    async function poll() {
        if (stopped) return;
        const activeController = new AbortController();
        controller = activeController;
        try {
            const response = await fetch(url, {
                headers: { Accept: 'application/json' }, credentials: 'same-origin',
                cache: 'no-store', signal: activeController.signal,
            });
            if (!response.ok) throw new Error('Amount status unavailable');
            const result = await response.json();
            if (stopped || controller !== activeController) return;

            for (const voucher of result.vouchers) {
                for (const element of amounts.filter(element => element.dataset.savedVoucherId === String(voucher.id))) {
                    const label = voucher.status === 'ready' ? `PHP ${formatter.format(Number(voucher.amount))}`
                        : voucher.status === 'pending' ? 'Reading amount…' : 'Amount needed';
                    if (element.textContent.trim() !== label) element.textContent = label;
                    const editor = document.querySelector('#amount-editor summary');
                    if (editor) editor.textContent = voucher.status === 'ready' ? 'Edit amount' : 'Add amount';
                    const help = document.querySelector('[data-pending-amount-help]');
                    if (help) help.hidden = voucher.status !== 'pending';
                }
            }

            if (bell && Number.isInteger(result.manual_count)) {
                const count = result.manual_count;
                const label = count === 0 ? 'No vouchers need an amount'
                    : `${count} ${count === 1 ? 'voucher needs' : 'vouchers need'} an amount`;
                bell.setAttribute('aria-label', `Notifications: ${label}`);
                bell.title = label;
                let badge = bell.querySelector('.notification-count');
                if (count > 0) {
                    if (!badge) {
                        badge = document.createElement('span');
                        badge.className = 'notification-count';
                        badge.setAttribute('aria-hidden', 'true');
                        bell.append(badge);
                    }
                    badge.textContent = count;
                } else badge?.remove();
                const notice = document.querySelector('#missing-amount-notice');
                if (notice) {
                    notice.hidden = count === 0;
                    notice.querySelector('strong').textContent = count > 0 ? `${count} voucher(s) need an amount.` : '';
                }
            }
        } catch (error) {
            if (stopped || controller !== activeController) return;
            if (!warned) {
                warned = true;
                showWarning('Amount updates could not be checked. Your saved PDFs are safe. Checking will continue; refresh if the amounts do not update.');
            }
        }
        if (!stopped && controller === activeController) timer = setTimeout(poll, 5000);
    }

    window.addEventListener('pagehide', () => {
        stopped = true;
        clearTimeout(timer);
        controller?.abort();
    });
    window.addEventListener('pageshow', event => {
        if (event.persisted) {
            stopped = false;
            poll();
        }
    });
    poll();
}
