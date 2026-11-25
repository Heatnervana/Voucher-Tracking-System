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
                    const category = document.querySelector('[data-saved-category]');
                    if (category) {
                        category.textContent = ({ electricity: 'Electricity', water: 'Water' })[voucher.category]
                            ?? (voucher.category_status === 'manual' ? 'Category needed' : voucher.category_status?.startsWith('pending') ? 'Reading category…' : '');
                        category.closest('[data-saved-category-row]').hidden = category.textContent === '';
                    }
                }
            }

            if (bell && Number.isInteger(result.manual_count)) {
                const count = result.manual_count;
                const categoryCount = Number.isInteger(result.category_manual_count) ? result.category_manual_count : 0;
                const total = count + categoryCount;
                const amountLabel = count === 0 ? 'No vouchers need an amount'
                    : `${count} ${count === 1 ? 'voucher needs' : 'vouchers need'} an amount`;
                const label = categoryCount === 0 ? amountLabel
                    : `${amountLabel}; ${categoryCount} ${categoryCount === 1 ? 'voucher needs' : 'vouchers need'} a category`;
                bell.href = categoryCount > 0 ? bell.dataset.reviewUrl : bell.dataset.amountReviewUrl;
                bell.setAttribute('aria-label', `Notifications: ${label}`);
                bell.title = label;
                let badge = bell.querySelector('.notification-count');
                if (total > 0) {
                    if (!badge) {
                        badge = document.createElement('span');
                        badge.className = 'notification-count';
                        badge.setAttribute('aria-hidden', 'true');
                        bell.append(badge);
                    }
                    badge.textContent = total;
                } else badge?.remove();
                const categoryNotice = document.querySelector('#missing-category-notice');
                if (categoryNotice) {
                    categoryNotice.hidden = categoryCount === 0;
                    categoryNotice.querySelector('strong').textContent = categoryCount > 0 ? `${categoryCount} voucher(s) need a category.` : '';
                }

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
