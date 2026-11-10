import test from 'node:test';
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { mkdir, writeFile, readFile, readdir, copyFile, rm } from 'node:fs/promises';
import { resolve, relative, join, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'node:net';
import { randomUUID, createHash } from 'node:crypto';

const project = resolve(fileURLToPath(new URL('..', import.meta.url)));
const php = process.env.BROWSER_TEST_PHP ?? 'C:\\xampp\\php\\php.exe';
const edge = process.env.BROWSER_TEST_EDGE ?? 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
const pause = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));

async function freePort() {
    const server = createServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const port = server.address().port;
    await new Promise(resolve => server.close(resolve));
    return port;
}

class BrowserPage {
    constructor(socket) {
        this.socket = socket;
        this.nextId = 0;
        this.pending = new Map();
        this.exceptions = [];
        this.responses = [];
        socket.addEventListener('message', event => {
            const message = JSON.parse(event.data);
            if (message.method === 'Runtime.exceptionThrown') this.exceptions.push(message.params.exceptionDetails);
            if (message.method === 'Network.responseReceived') this.responses.push({ ...message.params.response, type: message.params.type });
            const request = this.pending.get(message.id);
            if (!request) return;
            this.pending.delete(message.id);
            clearTimeout(request.timer);
            if (message.error) request.reject(new Error(JSON.stringify(message.error)));
            else request.resolve(message.result);
        });
        socket.addEventListener('close', () => {
            for (const request of this.pending.values()) {
                clearTimeout(request.timer);
                request.reject(new Error('Browser connection closed.'));
            }
            this.pending.clear();
        });
    }

    static async connect(url, pageTarget = true) {
        const socket = new WebSocket(url);
        await new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error('Browser WebSocket did not open.')), 10000);
            socket.addEventListener('open', () => { clearTimeout(timer); resolve(); }, { once: true });
            socket.addEventListener('error', error => { clearTimeout(timer); reject(error); }, { once: true });
            socket.addEventListener('close', () => { clearTimeout(timer); reject(new Error('Browser closed before WebSocket connected.')); }, { once: true });
        });
        const page = new BrowserPage(socket);
        try {
            if (pageTarget) {
                await page.send('Page.enable');
                await page.send('Runtime.enable');
                await page.send('Network.enable');
            }
            return page;
        } catch (error) {
            socket.close();
            throw error;
        }
    }

    send(method, params = {}) {
        const id = ++this.nextId;
        return new Promise((resolve, reject) => {
            const timer = setTimeout(() => {
                this.pending.delete(id);
                reject(new Error(`CDP timeout: ${method}`));
            }, 30000);
            this.pending.set(id, { resolve, reject, timer });
            this.socket.send(JSON.stringify({ id, method, params }));
        });
    }

    async evaluate(expression) {
        const result = await this.send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true, userGesture: true });
        if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description ?? result.exceptionDetails.text);
        return result.result.value;
    }

    async wait(expression, timeout = 30000) {
        const deadline = Date.now() + timeout;
        let lastError;
        while (Date.now() < deadline) {
            try {
                const result = await this.evaluate(expression);
                if (result) return result;
            } catch (error) { lastError = error; }
            await pause(75);
        }
        const location = await this.evaluate('location.href').catch(() => 'unavailable');
        throw new Error(`Browser condition timed out at ${location}: ${expression}${lastError ? `; ${lastError.message}` : ''}`);
    }

    async navigate(url) {
        await this.send('Page.navigate', { url });
        await this.wait(`location.href === ${JSON.stringify(url)} && document.readyState === 'complete'`);
    }

    async click(selector) {
        await this.evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`);
    }

    async key(key, code, virtualKeyCode) {
        await this.send('Input.dispatchKeyEvent', { type: 'keyDown', key, code, windowsVirtualKeyCode: virtualKeyCode, text: key === 'Enter' ? '\r' : key === ' ' ? ' ' : undefined });
        await this.send('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode: virtualKeyCode });
    }

    async files(selector, paths) {
        const { root } = await this.send('DOM.getDocument');
        const { nodeId } = await this.send('DOM.querySelector', { nodeId: root.nodeId, selector });
        await this.send('DOM.setFileInputFiles', { nodeId, files: paths });
    }

    async response(path, status, after) {
        for (let attempt = 0; attempt < 400; attempt++) {
            if (this.responses.slice(after).some(response => new URL(response.url).pathname === path && response.status === status && response.type === 'Document')) return;
            await pause(75);
        }
        throw new Error(`Browser did not receive ${status} for ${path}.`);
    }

    close() { this.socket.close(); }
}

async function untilHttp(url, child, output) {
    for (let attempt = 0; attempt < 160; attempt++) {
        if (child.exitCode !== null) throw new Error(`Child exited: ${output()}`);
        try {
            const response = await fetch(url);
            if (response.ok) return response;
        } catch {}
        await pause(100);
    }
    throw new Error(`Server did not start: ${url}\n${output()}`);
}

test('real Edge voucher workflows use isolated data', { timeout: 360000 }, async t => {
    const testingParent = join(project, 'storage', 'framework', 'testing');
    const directory = join(testingParent, `browser-${randomUUID()}`);
    const port = await freePort();
    const debugPort = await freePort();
    const origin = `http://127.0.0.1:${port}`;
    let server;
    let browser;
    let browserControl;
    let worker;
    let logs = '';
    const pages = [];
    const focusedWorkflows = [
        ['BROWSER_TEST_SECURITY_ONLY', 'security workflow'],
        ['BROWSER_TEST_ACCOUNTS_ONLY', 'accounts management'],
        ['BROWSER_TEST_RECORD_EDITING_ONLY', 'record editing'],
        ['BROWSER_TEST_UPLOAD_RELIABILITY_ONLY', 'upload reliability'],
        ['BROWSER_TEST_SAVED_OCR_ONLY', 'saved OCR'],
        ['BROWSER_TEST_DUPLICATE_ONLY', 'duplicate warning'],
        ['BROWSER_TEST_USABILITY_ONLY', 'library usability'],
        ['BROWSER_TEST_MISSING_AMOUNT_ONLY', 'a voucher saves without'],
    ];
    const focus = focusedWorkflows.find(([variable]) => process.env[variable] === '1')?.[1];
    const runTest = (name, callback) => t.test(name, {
        skip: name.startsWith('login forces') ? false : focus
            ? !name.startsWith(focus) && !(focus === 'saved OCR' && name.startsWith('a voucher saves without'))
            : focusedWorkflows.filter(([, prefix]) => prefix !== 'a voucher saves without').some(([, prefix]) => name.startsWith(prefix)),
    }, async () => {
        try {
            await callback();
        } catch (error) {
            console.error(logs.slice(-12000));
            console.error(JSON.stringify(pages.flatMap(page => page.exceptions)));
            throw error;
        }
    });
    await mkdir(directory, { recursive: true });
    for (const folder of ['private', 'cache', 'locks', 'sessions', 'views', 'inputs', 'downloads', 'framework/locks']) {
        await mkdir(join(directory, folder), { recursive: true });
    }
    await writeFile(join(directory, 'database.sqlite'), '');
    const environment = { ...process.env, LARAVEL_STORAGE_PATH: directory, APP_CONFIG_CACHE: relative(project, join(directory, 'config.php')) };
    const inspect = () => JSON.parse(execFileSync(php, ['tests/Browser/fixture.php', 'inspect', directory], { cwd: project, env: environment, encoding: 'utf8' }));
    const inspectReliability = () => JSON.parse(execFileSync(php, ['tests/Browser/fixture.php', 'inspect-upload-reliability', directory], { cwd: project, env: environment, encoding: 'utf8' }));
    const runWorker = () => {
        assert.ok(!worker || worker.exitCode !== null, 'The previous isolated OCR worker must have stopped.');
        worker = spawn(php, ['artisan', 'queue:work', 'database', '--queue=voucher-ocr', '--stop-when-empty', '--sleep=1', '--tries=1', '--timeout=60', '--no-interaction'], {
            cwd: project, env: environment, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'],
        });
        worker.stdout.on('data', chunk => { logs += chunk; });
        worker.stderr.on('data', chunk => { logs += chunk; });
        return new Promise((resolve, reject) => {
            const timer = setTimeout(() => {
                if (process.platform === 'win32') {
                    try { execFileSync('taskkill', ['/PID', String(worker.pid), '/T', '/F'], { windowsHide: true, stdio: 'ignore' }); } catch {}
                } else worker.kill('SIGTERM');
                reject(new Error('The isolated OCR worker exceeded 75 seconds.'));
            }, 75000);
            worker.once('error', error => { clearTimeout(timer); reject(error); });
            worker.once('exit', code => {
                clearTimeout(timer);
                if (code === 0) resolve();
                else reject(new Error(`Isolated OCR worker exited with code ${code}.`));
            });
        });
    };
    const newPage = async (independent = false) => {
        let target;
        if (independent) {
            const { browserContextId } = await browserControl.send('Target.createBrowserContext');
            const { targetId } = await browserControl.send('Target.createTarget', { url: 'about:blank', browserContextId });
            target = (await (await fetch(`http://127.0.0.1:${debugPort}/json/list`)).json()).find(target => target.id === targetId);
        } else {
            target = await (await fetch(`http://127.0.0.1:${debugPort}/json/new?about:blank`, { method: 'PUT' })).json();
        }
        const page = await BrowserPage.connect(target.webSocketDebuggerUrl);
        pages.push(page);
        return page;
    };
    const inputFiles = async (count, first) => {
        const files = [];
        for (let index = 0; index < count; index++) {
            const filename = join(directory, 'inputs', `${first + index}_${2026000000 + first + index}_BROWSER TEST.pdf`);
            await writeFile(filename, '%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n');
            files.push(filename);
        }
        return files;
    };
    const upload = async (page, paths) => {
        await page.navigate(`${origin}/vouchers/upload`);
        await page.files('#voucher-files-input', paths);
        await page.wait(`document.querySelector('#voucher-selection-summary').textContent.includes('${paths.length} PDF')`);
        await page.click('#voucher-upload-button');
        await page.wait("location.pathname === '/vouchers/confirm' || document.querySelector('#existing-folder-warning')?.checkVisibility()", 120000);
        if (await page.evaluate("document.querySelector('#existing-folder-warning')?.checkVisibility() === true")) {
            await page.click('[data-folder-action=keep]');
        }
        await page.wait(`location.pathname === '/vouchers/confirm' && document.readyState === 'complete' && document.querySelector('#voucher-finalize-form')?.noValidate === true && document.querySelectorAll('[data-review-item]').length === ${paths.length}`, 120000);
        return page.evaluate("document.querySelector('#voucher-review').dataset.batchId");
    };
    const fillAmounts = (page, exceptLast = false) => page.evaluate(`
        [...document.querySelectorAll('[data-voucher-amount]')].forEach((input, index, inputs) => {
            if (${exceptLast} && index === inputs.length - 1) return;
            input.value = '5250.00';
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
    `);

    try {
        execFileSync(php, ['tests/Browser/fixture.php', 'prepare', directory, origin], { cwd: project, env: environment, encoding: 'utf8' });
        server = spawn(php, ['-d', 'max_input_vars=1000', '-S', `127.0.0.1:${port}`, '-t', join(project, 'public'), join(project, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], {
            cwd: join(project, 'public'), env: environment, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'],
        });
        server.stdout.on('data', chunk => { logs += chunk; });
        server.stderr.on('data', chunk => { logs += chunk; });
        await untilHttp(`${origin}/login`, server, () => logs);
        browser = spawn(edge, ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--disable-background-networking', `--remote-debugging-port=${debugPort}`, `--user-data-dir=${join(directory, 'edge-profile')}`, 'about:blank'], {
            windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'],
        });
        browser.stderr.on('data', chunk => { logs += chunk; });
        await untilHttp(`http://127.0.0.1:${debugPort}/json/version`, browser, () => logs);
        const version = await (await fetch(`http://127.0.0.1:${debugPort}/json/version`)).json();
        browserControl = await BrowserPage.connect(version.webSocketDebuggerUrl, false);
        const page = await newPage();

        await runTest('login forces a temporary password change before vouchers', async () => {
            await page.navigate(`${origin}/login`);
            await page.evaluate(`document.querySelector('[name=username]').value='browser-tester'; document.querySelector('[name=password]').value='TemporaryBrowser123!';`);
            await page.click('button[type=submit]');
            await page.wait("location.pathname === '/profile' && !!document.querySelector('[name=current_password]')");
            await page.evaluate(`document.querySelector('[name=current_password]').value='TemporaryBrowser123!'; document.querySelector('[name=password]').value='ChangedBrowser123!'; document.querySelector('[name=password_confirmation]').value='ChangedBrowser123!';`);
            await page.click('button[type=submit]');
            await page.wait("location.pathname === '/vouchers' && document.body.textContent.includes('Password updated.')");
            assert.equal(inspect().temporary_password, false);
        });

        await runTest('security workflow restores deleted PDFs, permanently removes recycled files, and shows audited changes', async () => {
            await upload(page, await inputFiles(2, 88000));
            await fillAmounts(page);
            await page.click('#voucher-finalize-form button[type=submit].primary');
            await page.wait("location.pathname === '/vouchers' && document.body.textContent.includes('2 voucher(s) saved.')");
            const firstUrl = await page.evaluate("document.querySelector('a.record-link').href");
            await page.navigate(firstUrl);
            await page.evaluate("document.querySelector('#voucher-filename-form').closest('details').open=true; document.querySelector('[name=filename]').value='SEC-101_2026088888_SECURITY TEST.pdf'");
            await page.click('#voucher-filename-form button[type=submit]');
            await page.wait("document.querySelector('[data-voucher-dv]').textContent === '2026088888'");
            await page.evaluate("document.querySelector('#amount-editor').open=true; document.querySelector('#amount-editor [name=amount]').value='7000.00'");
            await page.click('#amount-editor button[type=submit]');
            await page.wait("document.body.textContent.includes('Amount saved.')");
            await page.navigate(`${origin}/vouchers`);
            await page.click('#record-management summary');
            await page.click('#select-page-pdfs');
            await page.evaluate('window.confirm=()=>true');
            await page.click('#delete-selected-button');
            await page.wait("document.body.textContent.includes('moved to the recycle bin')");
            assert.equal(inspect().vouchers, 0);
            assert.equal(inspect().trashed_pdfs, 2);
            assert.equal(inspect().stored_pdfs, 2);
            await page.navigate(`${origin}/recycle-bin`);
            assert.equal(await page.evaluate("document.querySelectorAll('[data-select-recycle]').length"), 2);
            assert.equal(await page.evaluate("fetch(document.querySelector('.recycle-actions a').href).then(response=>response.status)"), 200);
            for (const width of [1440, 390]) {
                await page.send('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width === 390 });
                await page.evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
                assert.equal(await page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), true);
                const { data } = await page.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
                await writeFile(join(testingParent, `recycle-bin-${width}.png`), Buffer.from(data, 'base64'));
            }
            await page.send('Emulation.clearDeviceMetricsOverride');
            await page.click('#select-recycle-page');
            await page.click('[data-recycle-submit]:not([data-recycle-purge])');
            await page.wait("document.body.textContent.includes('2 PDF(s) restored')");
            assert.equal(inspect().vouchers, 2);
            assert.equal(inspect().trashed_pdfs, 0);
            await page.navigate(firstUrl);
            await page.evaluate('window.confirm=()=>true');
            await page.click('.saved-voucher-delete button');
            await page.wait("location.pathname === '/vouchers' && document.body.textContent.includes('moved to the recycle bin')");
            await page.navigate(`${origin}/recycle-bin`);
            await page.click('#select-recycle-page');
            await page.evaluate('window.confirm=()=>true');
            await page.click('[data-recycle-purge]');
            await page.wait("document.body.textContent.includes('1 PDF(s) permanently deleted.')");
            assert.equal(inspect().vouchers, 1);
            assert.equal(inspect().trashed_pdfs, 0);
            assert.equal(inspect().stored_pdfs, 1);
            await page.navigate(`${origin}/activity?q=SEC-101`);
            const text = await page.evaluate('document.body.textContent');
            for (const label of ['PDF renamed', 'Amount changed', 'PDF moved to recycle bin', 'PDF restored', 'PDF permanently deleted']) assert.ok(text.includes(label), `Missing audit action: ${label}`);
            assert.ok(text.includes('Browser Tester'));
            const { data } = await page.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
            await writeFile(join(testingParent, 'activity-history-desktop.png'), Buffer.from(data, 'base64'));
            await page.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 900, deviceScaleFactor: 1, mobile: true });
            assert.equal(await page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), true);
            const { data: mobile } = await page.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
            await writeFile(join(testingParent, 'activity-history-mobile.png'), Buffer.from(mobile, 'base64'));
            await page.send('Emulation.clearDeviceMetricsOverride');
        });

        await runTest('accounts management creates users, changes roles, resets passwords and deletes access', async () => {
            await page.navigate(`${origin}/accounts`);
            await page.click('a[href$="/accounts/create"]');
            await page.wait("location.pathname === '/accounts/create' && !!document.querySelector('[name=role]')");
            await page.evaluate("document.querySelector('[name=name]').value='Browser Staff'; document.querySelector('[name=username]').value='account-browser'; document.querySelector('[name=role]').value='staff'; document.querySelector('[name=password]').value='TemporaryAccount123!'; document.querySelector('[name=password_confirmation]').value='TemporaryAccount123!'");
            await page.click('form.stack button[type=submit]');
            await page.wait("location.pathname === '/accounts' && document.body.textContent.includes('account-browser')");
            const editUrl = await page.evaluate("[...document.querySelectorAll('.account-record')].find(row => row.textContent.includes('account-browser')).querySelector('a').href");
            for (const width of [1440, 390]) {
                await page.send('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width === 390 });
                await page.evaluate('new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))');
                assert.equal(await page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), true, 'Accounts must fit the viewport.');
                const { data } = await page.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
                await writeFile(join(testingParent, `accounts-dashboard-${width}.png`), Buffer.from(data, 'base64'));
            }
            await page.send('Emulation.clearDeviceMetricsOverride');
            const employee = await newPage(true);
            const signIn = async password => {
                await employee.navigate(`${origin}/login`);
                await employee.evaluate(`document.querySelector('[name=username]').value='account-browser'; document.querySelector('[name=password]').value=${JSON.stringify(password)}`);
                await employee.click('button[type=submit]');
            };
            await signIn('TemporaryAccount123!');
            await employee.wait("location.pathname === '/profile' && !!document.querySelector('[name=current_password]')");
            await employee.evaluate("document.querySelector('[name=current_password]').value='TemporaryAccount123!'; document.querySelector('[name=password]').value='EmployeeChosen123!'; document.querySelector('[name=password_confirmation]').value='EmployeeChosen123!'");
            await employee.click('button[type=submit]');
            await employee.wait("location.pathname === '/vouchers' && document.readyState === 'complete'");
            assert.equal(await employee.evaluate("document.querySelector('nav a[href$=\"/accounts\"]') === null"), true);
            assert.equal(await employee.evaluate("fetch('/accounts', {headers:{Accept:'application/json'}}).then(response => response.status)"), 403);
            await page.navigate(editUrl);
            await page.evaluate("document.querySelector('[name=role]').value='administrator'");
            await page.click('form button.primary[type=submit]');
            await page.wait("document.body.textContent.includes('Account updated.')");
            await employee.send('Page.navigate', { url: `${origin}/accounts` });
            await employee.wait("location.pathname === '/login' && !!document.querySelector('[name=username]')");
            await signIn('EmployeeChosen123!');
            await employee.wait("(location.pathname === '/vouchers' || location.pathname === '/accounts') && document.readyState === 'complete'");
            await employee.navigate(`${origin}/accounts`);
            assert.equal(await employee.evaluate("document.body.textContent.includes('Create account')"), true);
            await page.evaluate("document.querySelector('form[action$=\"/password\"] [name=password]').value='ResetAccount123!'; document.querySelector('form[action$=\"/password\"] [name=password_confirmation]').value='ResetAccount123!'");
            await page.click('form[action$="/password"] button[type=submit]');
            await page.wait("document.body.textContent.includes('Password reset.')");
            await employee.send('Page.navigate', { url: `${origin}/accounts` });
            await employee.wait("location.pathname === '/login' && !!document.querySelector('[name=username]')");
            await signIn('ResetAccount123!');
            await employee.wait("location.pathname === '/profile' && !!document.querySelector('[name=current_password]')");
            await page.evaluate('window.confirm = () => true');
            await page.click('button.danger[type=submit]');
            await page.wait("location.pathname === '/accounts' && document.body.textContent.includes('Account deleted.')");
            assert.equal(await page.evaluate("document.body.textContent.includes('account-browser')"), false);
            await employee.send('Page.navigate', { url: `${origin}/vouchers` });
            await employee.wait("location.pathname === '/login' && !!document.querySelector('[name=username]')");
        });

        await runTest('upload reliability includes all 75 directory PDFs and five nested files', async () => {
            const folder = join(directory, 'inputs', 'June');
            await mkdir(join(folder, 'nested'), { recursive: true });
            const paths = await inputFiles(75, 9500);
            for (const [index, path] of paths.entries()) {
                await copyFile(path, join(folder, index < 70 ? '' : 'nested', path.split(sep).at(-1)));
            }
            await page.navigate(`${origin}/vouchers/upload`);
            await page.evaluate("document.querySelector('[name=type]').value='CHECK'; document.querySelector('[name=month]').value='6'");
            await page.files('#voucher-folder-input', [folder]);
            await page.wait("document.querySelector('#voucher-folder-input').files.length === 75");
            assert.equal(await page.evaluate("[...document.querySelector('#voucher-folder-input').files].filter(file => file.webkitRelativePath.includes('/nested/')).length"), 5);
            assert.match(await page.evaluate("document.querySelector('#voucher-selection-summary').textContent"), /75 PDF file\(s\)/);
            const responseCount = page.responses.length;
            await page.click('#voucher-upload-button');
            await page.wait("location.pathname === '/vouchers/confirm' && document.querySelectorAll('[data-review-item]').length === 75", 120000);
            assert.equal(inspect().staged_pdfs, 75);
            assert.equal(await page.evaluate("[...document.querySelectorAll('.review-file strong')].filter(file => file.textContent.includes('/nested/')).length"), 5);
            const chunks = page.responses.slice(responseCount).filter(response => new URL(response.url).pathname.endsWith('/upload/chunk'));
            assert.equal(chunks.length, 15);
            assert.ok(chunks.every(response => response.status === 200));
            await fillAmounts(page);
            await page.click('#voucher-finalize-form button[type=submit].primary');
            await page.wait("location.pathname === '/vouchers' && document.body.textContent.includes('75 voucher(s) saved.')", 120000);
            assert.equal(inspect().vouchers, 75);
            assert.equal(inspect().saved_pdfs, 75);
            assert.equal(inspect().staged_pdfs, 0);
            assert.ok(inspectReliability().every(record => record.hash));
            console.log('Directory picker: 75 enumerated, 5 nested, 15 successful chunks, 75 reviewed and saved.');
        });

        await runTest('upload reliability cancels populated-folder upload and replaces only the matching Check and DV pair', async () => {
            const before = inspectReliability();
            const original = before.find(record => record.checkada_no === '9500');
            const replacement = join(directory, 'inputs', '9500_2026009500_REPLACED PDF.pdf');
            await writeFile(replacement, '%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n% replacement bytes\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n');
            await page.navigate(`${origin}/vouchers/upload`);
            await page.evaluate("document.querySelector('[name=type]').value='CHECK'; document.querySelector('[name=month]').value='6'");
            await page.files('#voucher-files-input', [replacement]);
            await page.click('#voucher-upload-button');
            await page.wait("document.querySelector('#existing-folder-warning')?.checkVisibility()");
            assert.match(await page.evaluate("document.querySelector('[data-folder-warning-message]').textContent"), /75 PDFs/);
            await page.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
            assert.equal(await page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), true);
            const { data: warning } = await page.send('Page.captureScreenshot', { format: 'png' });
            await writeFile(join(testingParent, 'upload-reliability-folder-warning-mobile.png'), Buffer.from(warning, 'base64'));
            await page.send('Emulation.clearDeviceMetricsOverride');
            await page.click('[data-folder-action=cancel]');
            await page.wait("!document.querySelector('#voucher-upload-button').disabled && document.querySelector('#voucher-selection-summary').textContent.includes('Upload canceled')");
            assert.deepEqual(inspectReliability(), before);
            assert.equal(inspect().staged_pdfs, 0);
            await page.click('#voucher-upload-button');
            await page.wait("document.querySelector('#existing-folder-warning')?.checkVisibility()");
            await page.click('[data-folder-action=replace]');
            await page.wait("location.pathname === '/vouchers/confirm' && document.querySelectorAll('[data-review-item]').length === 1");
            assert.deepEqual(inspectReliability(), before, 'Replacement must wait until the reviewed voucher is saved.');
            await fillAmounts(page);
            await page.click('#voucher-finalize-form button[type=submit].primary');
            await page.wait("location.pathname === '/vouchers' && document.body.textContent.includes('1 voucher(s) saved.')");
            const after = inspectReliability();
            const replaced = after.find(record => record.checkada_no === '9500');
            assert.equal(replaced.id, original.id);
            assert.notEqual(replaced.hash, original.hash);
            assert.match(replaced.path, /REPLACED PDF\.pdf$/);
            assert.deepEqual(after.filter(record => record.id !== original.id), before.filter(record => record.id !== original.id));
            assert.equal(inspect().saved_pdfs, 75);
            assert.equal(inspect().staged_pdfs, 0);
            console.log('Folder warning: cancel preserved every record and PDF hash; replace retained the matching ID and all 74 unrelated PDFs.');
        });

        await runTest('upload reliability sends failed OCR directly to manual entry without retry controls', async () => {
            await upload(page, await inputFiles(3, 9700));
            await page.click('#voucher-finalize-form button[type=submit].primary');
            await page.wait("location.pathname === '/vouchers' && document.body.textContent.includes('3 voucher(s) saved.')");
            await runWorker();
            await page.navigate(`${origin}/vouchers?amount_status=missing`);
            await page.wait("document.querySelectorAll('.voucher-record').length === 3 && document.querySelector('#amount-notification .notification-count')?.textContent.trim() === '3'");
            assert.ok(inspectReliability().filter(record => Number(record.checkada_no) >= 9700).every(record => record.amount_status === 'manual' && record.amount_retry_count === 0));
            assert.equal(await page.evaluate("document.body.textContent.includes('Retry OCR')"), false);
            assert.equal(await page.evaluate("document.querySelectorAll('.voucher-actions a[href$=\"#amount-editor\"]').length"), 0);
            await page.click('.voucher-actions a');
            await page.wait("document.querySelector('#amount-editor')?.open");
            assert.equal(await page.evaluate("document.body.textContent.includes('Retry OCR')"), false);
            assert.equal(await page.evaluate("document.querySelector('#amount-editor summary').textContent"), 'Add amount');
        });

        await runTest('record editing saves cancelled filenames and shows months without OCR retry controls', async () => {
            await upload(page, await inputFiles(2, 9950));
            await fillAmounts(page);
            await page.click('#voucher-finalize-form button[type=submit].primary');
            await page.wait("location.pathname === '/vouchers' && document.body.textContent.includes('2 voucher(s) saved.')");
            assert.deepEqual(await page.evaluate("[...document.querySelectorAll('thead th:not(.management-cell)')].map(th => th.textContent.trim())"), ['DV number', 'Check / ADA', 'Payee', 'Amount', 'Folder', 'Month', 'Actions']);
            assert.match(await page.evaluate("document.querySelector('td[data-label=Month]').textContent"), /January|February|March|April|May|June|July|August|September|October|November|December/);
            assert.equal(await page.evaluate("document.body.textContent.includes('Retry OCR')"), false);
            for (const width of [1440, 768, 390]) {
                await page.send('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width === 390 });
                await page.evaluate('new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))');
                assert.equal(await page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), true);
                const { data } = await page.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
                await writeFile(join(testingParent, `record-editing-month-${width}.png`), Buffer.from(data, 'base64'));
            }
            await page.send('Emulation.clearDeviceMetricsOverride');
            const links = await page.evaluate("[...document.querySelectorAll('a.record-link')].map(link => link.href)");
            await page.navigate(links[0]);
            await page.evaluate("document.querySelector('#amount-editor').open = true; document.querySelector('#voucher-filename-form').closest('details').open = true");
            for (const width of [1366, 390]) {
                await page.send('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width === 390 });
                const gap = await page.evaluate("document.querySelector('.pdf-panel').clientHeight - document.querySelector('.pdf-panel iframe').offsetHeight");
                assert.ok(Math.abs(gap) <= 1, `The PDF should fill its panel at ${width}px, even with the editors open.`);
            }
            await page.send('Emulation.clearDeviceMetricsOverride');
            await page.evaluate("document.querySelector('#voucher-filename-form').closest('details').open = true; document.querySelector('[name=filename]').value='Cancelled'");
            await page.click('#voucher-filename-form button[type=submit]');
            await page.wait("document.querySelector('[data-filename-status]').textContent === 'Saved PDF filename updated.'");
            assert.equal(await page.evaluate("document.querySelector('[data-saved-filename]').textContent"), 'Cancelled.pdf');
            await page.navigate(links[0]);
            assert.equal(await page.evaluate("document.querySelector('[data-saved-filename]').textContent"), 'Cancelled.pdf');
            await page.evaluate("document.querySelector('#voucher-filename-form').closest('details').open = true; document.querySelector('[name=filename]').value='11033_11033221_CANCELLED.pdf'");
            await page.click('#voucher-filename-form button[type=submit]');
            await page.wait("document.querySelector('[data-voucher-dv]').textContent === '11033221'");
            assert.equal(await page.evaluate("document.querySelector('[data-voucher-check]').textContent"), '11033');
            assert.equal(await page.evaluate("document.querySelector('[data-voucher-payee]').textContent"), 'CANCELLED');
            await page.navigate(`${origin}/vouchers?q=11033221`);
            assert.equal(await page.evaluate("document.querySelectorAll('a.record-link').length"), 1);
            assert.equal(await page.evaluate("document.querySelector('a.record-link').textContent"), '11033221');
            await page.navigate(links[0]);
            assert.equal(await page.evaluate("document.querySelector('[data-voucher-dv]').textContent"), '11033221');
            await page.navigate(links[1]);
            await page.evaluate("document.querySelector('#voucher-filename-form').closest('details').open = true; document.querySelector('[name=filename]').value='11033_11033221_CANCELLED.pdf'");
            await page.click('#voucher-filename-form button[type=submit]');
            await page.wait("document.querySelector('[data-filename-status]').textContent.includes('already exist')");
            assert.equal(await page.evaluate("document.querySelector('[name=filename]').getAttribute('aria-invalid')"), 'true');
            assert.notEqual(await page.evaluate("document.querySelector('[data-saved-filename]').textContent"), 'Cancelled.pdf');
        });

        await runTest('library usability keeps preview, keyboard controls and responsive records usable', async () => {
            const viewport = async (width, height, mobile = false) => {
                await page.send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile });
                await page.evaluate('new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))');
            };
            const screenshot = async name => {
                const { cssContentSize } = await page.send('Page.getLayoutMetrics');
                const { data } = await page.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: cssContentSize.width, height: cssContentSize.height, scale: 1 } });
                await writeFile(join(testingParent, `ui-after-${name}.png`), Buffer.from(data, 'base64'));
            };
            const activate = async selector => {
                await page.send('Page.bringToFront');
                await page.evaluate(`document.querySelector(${JSON.stringify(selector)}).focus()`);
                await page.key('Enter', 'Enter', 13);
            };
            const visible = selector => page.evaluate(`document.querySelector(${JSON.stringify(selector)})?.checkVisibility() === true`);
            const assertNoOverflow = async () => {
                assert.equal(await page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), true, 'The page must fit the viewport.');
                assert.equal(await page.evaluate("document.querySelector('.table-scroll').scrollWidth <= document.querySelector('.table-scroll').clientWidth + 1"), true, 'Voucher records must fit without horizontal scrolling.');
            };

            await viewport(1366, 1000);
            const batch = await upload(page, await inputFiles(1, 9000));
            assert.equal(await visible('a.preview-pdf'), true, 'Review should offer an accessible PDF preview.');
            await page.evaluate("document.querySelector('[data-voucher-amount]').value = '123.45'; document.querySelector('[data-voucher-amount]').dispatchEvent(new Event('input', { bubbles: true }))");
            const fields = await page.evaluate("[...document.querySelectorAll('#voucher-finalize-form input')].map(input => [input.name, input.value])");
            const preview = await page.evaluate(`(async () => {
                const link = document.querySelector('a.preview-pdf');
                const url = new URL(link.href);
                const response = await fetch(url);
                return { batch: url.searchParams.get('batch_id'), file: url.searchParams.get('file_id'), status: response.status, type: response.headers.get('content-type'), body: (await response.text()).startsWith('%PDF-') };
            })()`);
            assert.equal(preview.batch, batch);
            assert.equal(preview.file, await page.evaluate("document.querySelector('[data-review-item]').dataset.fileId"));
            assert.equal(preview.status, 200);
            assert.match(preview.type, /^application\/pdf/);
            assert.equal(preview.body, true);
            assert.deepEqual(await page.evaluate("[...document.querySelectorAll('#voucher-finalize-form input')].map(input => [input.name, input.value])"), fields, 'Preview must preserve the unsaved form.');
            await screenshot('review');
            await viewport(390, 844, true);
            assert.equal(await page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), true, 'Review must fit a phone viewport.');
            assert.equal(await page.evaluate("[...document.querySelectorAll('#voucher-finalize-form .button-row button')].every(button => { const rect = button.getBoundingClientRect(); return rect.left >= 0 && rect.right <= innerWidth; })"), true, 'Review save and cancel controls must fit the phone viewport.');
            await screenshot('review-mobile');
            await viewport(1366, 1000);
            await page.click('#voucher-finalize-form button[type=submit].primary');
            await page.wait("location.pathname === '/vouchers' && document.readyState === 'complete' && document.body.textContent.includes('1 voucher(s) saved.')");
            assert.equal(await page.evaluate("document.querySelector('td.amount').textContent.trim()"), 'PHP 123.45');

            assert.equal(await page.evaluate("document.querySelector('#record-management')?.open"), false, 'Record management should initially be collapsed.');
            assert.equal(await visible('[data-select-pdf]'), false);
            assert.equal(await visible('.voucher-actions .text-danger'), false);
            assert.equal(await visible('.voucher-actions a'), true);
            await activate('#record-management summary');
            await page.wait("document.querySelector('#record-management').open && document.querySelector('[data-select-pdf]').checkVisibility()");
            assert.equal(await visible('.voucher-actions .text-danger'), true);
            await page.evaluate("document.querySelector('[data-select-pdf]').focus()");
            await page.key(' ', 'Space', 32);
            await page.wait("document.querySelector('#selected-pdf-count').textContent === '1 PDF selected'");
            await activate('#record-management summary');
            await page.wait("!document.querySelector('[data-select-pdf]').checked && document.querySelector('#delete-selected-button').disabled");
            assert.equal(await visible('[data-select-pdf]'), false);

            assert.equal(await visible('select[name=amount_status]'), true);
            assert.equal(await page.evaluate("new Set([...document.querySelectorAll('.library-filter-row select')].map(select => select.getBoundingClientRect().top)).size"), 1, 'The four filters should share a desktop row.');
            await page.evaluate("document.querySelector('.library-filter-row select[name=month]').focus()");
            await page.key('Tab', 'Tab', 9);
            assert.equal(await page.evaluate("document.activeElement.matches('select[name=amount_status]')"), true, 'Tab from Month should reach Amount status.');
            assert.equal(await page.evaluate("getComputedStyle(document.activeElement).outlineStyle !== 'none' && parseFloat(getComputedStyle(document.activeElement).outlineWidth) >= 2"), true, 'Keyboard focus must be visible on filters.');
            await page.key('ArrowDown', 'ArrowDown', 40);
            assert.notEqual(await page.evaluate('document.activeElement.value'), '', 'The keyboard should select a filter option.');
            await page.evaluate("document.querySelector('select[name=amount_status]').value = ''");
            assert.equal(await page.evaluate("getComputedStyle(document.querySelector('.table-scroll table')).display"), 'table');
            await assertNoOverflow();
            await screenshot('desktop');
            const renderedLibrary = await page.evaluate('document.documentElement.outerHTML');
            await writeFile(join(testingParent, 'redesign-library.html'), renderedLibrary
                .replaceAll(origin, 'http://127.0.0.1:8080')
                .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, ''));

            await viewport(390, 844, true);
            for (const selector of ['a.record-link', 'td.amount', '.voucher-actions a']) {
                assert.equal(await visible(selector), true, `${selector} must be visible on a phone.`);
            }
            assert.equal(await page.evaluate("[...document.querySelectorAll('tbody td')].some(cell => cell.textContent.trim() === 'BROWSER TEST' && cell.checkVisibility())"), true);
            assert.equal(await page.evaluate("document.querySelector('a.record-link').textContent.trim()"), '2026009000');
            await assertNoOverflow();
            await screenshot('mobile');
            await viewport(683, 500);
            await assertNoOverflow();
            await screenshot('zoom');

            await page.navigate(`${origin}/vouchers?q=NO-SUCH-VOUCHER`);
            assert.equal(await page.evaluate("document.querySelector('.empty-state').textContent.includes('No vouchers match your search')"), true);
            const clearLink = await page.evaluate("[...document.querySelectorAll('.empty-state a')].find(link => link.textContent.trim() === 'Clear search and filters')?.href");
            assert.equal(clearLink, `${origin}/vouchers`);
            await page.navigate(clearLink);
            assert.equal(await page.evaluate("document.querySelectorAll('a.record-link').length"), 1);

            await viewport(1366, 900);
            await upload(page, await inputFiles(1, 9001));
            await fillAmounts(page);
            await page.click('#voucher-finalize-form button[type=submit].primary');
            await page.wait("location.pathname === '/vouchers' && document.querySelectorAll('[data-select-preview]:not([hidden])').length === 2");
            await activate('[data-voucher-preview]:nth-child(2) [data-select-preview]');
            await page.wait("document.querySelector('[data-preview-dv]').textContent === '2026009001'");
            assert.equal(await page.evaluate("document.querySelector('[data-preview-open]').href === document.querySelectorAll('a.record-link')[1].href"), true);
            assert.equal(await page.evaluate("document.querySelectorAll('[data-select-preview]')[1].getAttribute('aria-pressed')"), 'true');
            await screenshot('selected-desktop');

            await viewport(837, 525);
            await assertNoOverflow();
            await screenshot('reference-width');
            await viewport(390, 844, true);
            assert.equal(await visible('#voucher-preview'), false);
            await page.click('[data-voucher-preview]:first-child [data-select-preview]');
            assert.equal(await visible('#voucher-preview'), true);
            assert.equal(await page.evaluate("document.activeElement.id"), 'voucher-preview-heading');
            assert.equal(await page.evaluate("document.querySelector('[data-preview-dv]').textContent"), '2026009000');
            await assertNoOverflow();
            await screenshot('selected-mobile');
            await activate('[data-close-preview]');
            assert.equal(await visible('#voucher-preview'), false);
            assert.equal(await page.evaluate("document.activeElement === document.querySelector('[data-voucher-preview]:first-child [data-select-preview]')"), true);
            await viewport(320, 740, true);
            await assertNoOverflow();

            for (const [path, heading] of [['/years/create', 'New year folder'], ['/recycle-bin', 'Recycle bin'], ['/accounts', 'Accounts'], ['/activity', 'Activity history']]) {
                await page.navigate(origin + path);
                assert.equal(await page.evaluate("document.querySelector('.workspace .nav-links [aria-current=page]').textContent.trim()"), heading === 'New year folder' ? 'Years' : heading);
                await viewport(390, 844, true);
                assert.equal(await page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), true, heading + ' should fit on a phone.');
                await viewport(1366, 900);
            }
            await page.send('Emulation.clearDeviceMetricsOverride');
        });

        await runTest('duplicate warning can be dismissed without removing any PDF or bypassing validation', async () => {
            const files = await inputFiles(1, 9500);
            await upload(page, files);
            await fillAmounts(page);
            await page.click('#voucher-finalize-form button[type=submit].primary');
            await page.wait("location.pathname === '/vouchers' && document.readyState === 'complete'");
            await upload(page, files);
            await fillAmounts(page);
            await page.click('#voucher-finalize-form button[type=submit].primary');
            await page.wait("document.readyState === 'complete' && document.querySelector('.notice.error')?.textContent.includes('already exist')");
            assert.equal(await page.evaluate("!!document.querySelector('.notice.error button[data-dismiss-notice]')"), true, 'Warnings need a user-operated close button.');
            const before = inspect();
            assert.equal(before.vouchers, 1);
            assert.equal(before.saved_pdfs, 1);
            assert.equal(before.staged_pdfs, 1);
            const fields = await page.evaluate("[...document.querySelectorAll('#voucher-finalize-form input')].map(input => [input.name, input.value])");
            const bounds = await page.evaluate("(() => { const r = document.querySelector('[data-dismiss-notice]').getBoundingClientRect(); return { width: r.width, height: r.height }; })()");
            assert.ok(bounds.width >= 44 && bounds.height >= 44);
            await page.evaluate("document.querySelector('[data-dismiss-notice]').focus()");
            await page.key('Enter', 'Enter', 13);
            await page.wait("!document.querySelector('.notice.error').checkVisibility()");
            assert.deepEqual(inspect(), before);
            assert.deepEqual(await page.evaluate("[...document.querySelectorAll('#voucher-finalize-form input')].map(input => [input.name, input.value])"), fields);
            assert.equal(await page.evaluate("document.activeElement.id"), 'main-content');
            await page.click('#voucher-finalize-form button[type=submit].primary');
            await page.wait("document.readyState === 'complete' && document.querySelector('.notice.error')?.checkVisibility() && document.querySelector('.notice.error').textContent.includes('already exist')");
            assert.deepEqual(inspect(), before);
        });

        await runTest('saved OCR finishes without reloading and preserves an unsaved manual amount', async () => {
            const sample = join(project, 'tests', 'Fixtures', 'amount-5250-scan.pdf');
            const sampleHash = createHash('sha256').update(await readFile(sample)).digest('hex');
            const copy = join(directory, 'inputs', '10779_2026012525_PLDT.pdf');
            await copyFile(sample, copy);
            await upload(page, [copy]);
            assert.equal(await page.evaluate("document.querySelector('[data-voucher-amount]').value"), '');
            await page.click('#voucher-finalize-form button[type=submit].primary');
            await page.wait("location.pathname === '/vouchers' && document.readyState === 'complete' && document.body.textContent.includes('1 voucher(s) saved.')");
            assert.equal(await page.evaluate("document.querySelector('td[data-saved-amount]').textContent.trim()"), 'Reading amount…');
            assert.equal(await page.evaluate("document.querySelector('#amount-notification .notification-count') === null"), true, 'Pending OCR must not ask for manual entry.');
            assert.equal(inspect().scanned_amount, null);
            assert.equal(inspect().saved_pdfs, 1);
            const libraryTimeOrigin = await page.evaluate('performance.timeOrigin');
            const details = await newPage();
            await details.navigate(await page.evaluate("document.querySelector('a.record-link').href"));
            await details.wait("document.querySelector('[data-saved-amount]')?.textContent.trim() === 'Reading amount…'");
            await details.evaluate("document.querySelector('#amount-editor').open = true; document.querySelector('input[name=amount]').value = '6789.12'; document.querySelector('input[name=amount]').dispatchEvent(new Event('input', { bubbles: true }))");
            const detailsTimeOrigin = await details.evaluate('performance.timeOrigin');
            const pdfUrl = await details.evaluate("document.querySelector('iframe[title=\"Voucher PDF\"]').src");
            await details.evaluate(`
                window.originalFetch = window.fetch;
                window.fetch = (input, options) => {
                    if (new URL(input, location.href).pathname === '/vouchers/amount-status') {
                        window.fetch = window.originalFetch;
                        return Promise.reject(new Error('Simulated temporary amount status outage'));
                    }
                    return window.originalFetch(input, options);
                };
            `);
            await details.wait("document.querySelector('.notice.error')?.checkVisibility() && document.querySelector('.notice.error').textContent.includes('Amount updates could not be checked')", 15000);
            assert.equal(await details.evaluate("document.querySelector('input[name=amount]').value"), '6789.12');
            await page.send('Page.bringToFront');
            const { data: pendingImage } = await page.send('Page.captureScreenshot', { format: 'png' });
            await writeFile(join(testingParent, 'saved-ocr-pending.png'), Buffer.from(pendingImage, 'base64'));
            await Promise.all([
                runWorker(),
                page.wait("document.querySelector('td[data-saved-amount]')?.textContent.trim() === 'PHP 5,250.00'", 80000),
                details.wait("document.querySelector('[data-saved-amount]')?.textContent.trim() === 'PHP 5,250.00'", 80000),
            ]);
            assert.equal(await page.evaluate('performance.timeOrigin'), libraryTimeOrigin, 'The saved amount must update without a page reload.');
            assert.equal(await details.evaluate('performance.timeOrigin'), detailsTimeOrigin);
            assert.equal(await details.evaluate("document.querySelector('input[name=amount]').value"), '6789.12', 'Polling must preserve an in-progress manual edit.');
            assert.equal(await details.evaluate("document.querySelector('#amount-editor').open"), true);
            assert.equal(await details.evaluate("document.querySelector('.notice.error').checkVisibility()"), true, 'A warning must remain visible after polling recovers until explicitly dismissed.');
            assert.equal(await details.evaluate("document.querySelector('iframe[title=\"Voucher PDF\"]').src"), pdfUrl);
            await details.click('.notice.error [data-dismiss-notice]');
            assert.equal(await details.evaluate("document.querySelector('.notice.error').checkVisibility()"), false);
            assert.equal(await details.evaluate("document.querySelector('input[name=amount]').value"), '6789.12');
            assert.equal(await page.evaluate("document.querySelector('#amount-notification .notification-count') === null"), true);
            assert.equal(inspect().scanned_amount, '5250.00');
            const savedFiles = JSON.parse(execFileSync(php, ['tests/Browser/fixture.php', 'inspect-parallel', directory], { cwd: project, env: environment, encoding: 'utf8' }));
            assert.equal(savedFiles.records.length, 1);
            assert.equal(savedFiles.records[0].hash, sampleHash, 'Reading a saved PDF must preserve its bytes.');
            await page.send('Page.bringToFront');
            const { data: completedImage } = await page.send('Page.captureScreenshot', { format: 'png' });
            await writeFile(join(testingParent, 'saved-ocr-completed.png'), Buffer.from(completedImage, 'base64'));
        });

        await runTest('page two validation focuses the missing eleventh payee, then saves all eleven', async () => {
            await upload(page, await inputFiles(11, 100));
            await fillAmounts(page);
            await page.evaluate(`document.querySelectorAll('[data-review-item]')[10].querySelector('input[name$="[payee]"]').value = ''`);
            await page.click('#voucher-finalize-form button[type=submit].primary');
            await page.wait("document.querySelectorAll('[data-review-item]')[10].contains(document.activeElement)");
            assert.equal(await page.evaluate("document.querySelectorAll('[data-review-item]')[10].hidden"), false);
            assert.equal(await page.evaluate(`document.activeElement.matches('input[name$="[payee]"]')`), true);
            await page.evaluate(`document.activeElement.value = 'BROWSER TEST'`);
            await page.click('#voucher-finalize-form button[type=submit].primary');
            await page.wait("location.pathname === '/vouchers' && document.body.textContent.includes('11 voucher(s) saved.')");
            assert.equal(inspect().vouchers, 11);
            assert.equal(inspect().saved_pdfs, 11);
        });

        await runTest('browser Back restores usable upload controls after successful upload', async () => {
            const history = await page.send('Page.getNavigationHistory');
            const previousUpload = history.entries.filter(entry => entry.url === `${origin}/vouchers/upload`).at(-1);
            assert.ok(previousUpload, 'Upload page should exist in browser history.');
            await page.send('Page.navigateToHistoryEntry', { entryId: previousUpload.id });
            await page.wait("location.pathname === '/vouchers/upload' && !!document.querySelector('#voucher-upload-button') && !document.querySelector('#voucher-upload-button').disabled");
            assert.equal(await page.evaluate("[...document.querySelectorAll('#voucher-upload-form select, #voucher-upload-form input[type=file]')].every(input => !input.disabled)"), true);
            assert.equal(await page.evaluate("document.querySelector('#voucher-upload-button').textContent.trim()"), 'Upload and review');
        });

        await runTest('300 PDFs save through the actual browser form despite max_input_vars=1000', async () => {
            await upload(page, await inputFiles(300, 1000));
            await fillAmounts(page);
            await page.click('#voucher-finalize-form button[type=submit].primary');
            await page.wait("location.pathname === '/vouchers' && document.body.textContent.includes('300 voucher(s) saved.')", 120000);
            const state = inspect();
            assert.equal(state.vouchers, 311);
            assert.equal(state.saved_pdfs, 311);
            assert.equal(state.staged_pdfs, 0);
        });

        await runTest('cancel from a stale review tab preserves the newer upload', async () => {
            const oldBatch = await upload(page, await inputFiles(1, 2000));
            const newer = await newPage();
            const newBatch = await upload(newer, await inputFiles(1, 2001));
            assert.notEqual(newBatch, oldBatch);
            page.socket.addEventListener('message', event => {
                if (JSON.parse(event.data).method === 'Page.javascriptDialogOpening') page.send('Page.handleJavaScriptDialog', { accept: true }).catch(() => {});
            });
            const responseCount = page.responses.length;
            await page.click('button[form=cancel-upload]');
            await page.response('/vouchers/confirm', 409, responseCount);
            await newer.navigate(`${origin}/vouchers/confirm`);
            assert.equal(await newer.evaluate("document.querySelector('#voucher-review').dataset.batchId"), newBatch);
            assert.equal(inspect().staged_pdfs, 1);
            // The newer tab must still be able to save after the stale cancellation attempt.
            await fillAmounts(newer);
            await newer.click('#voucher-finalize-form button[type=submit].primary');
            await newer.wait("location.pathname === '/vouchers' && document.body.textContent.includes('1 voucher(s) saved.')");
            assert.equal(inspect().vouchers, 312);
        });

        await runTest('two employees in separate browser contexts keep independent concurrent uploads', async () => {
            const employee = await newPage(true);
            await employee.navigate(`${origin}/login`);
            assert.ok(await employee.evaluate("!!document.querySelector('[name=username]')"), 'Independent context must not inherit the first employee login.');
            await employee.evaluate(`document.querySelector('[name=username]').value='browser-employee'; document.querySelector('[name=password]').value='BrowserEmployee123!';`);
            await employee.click('button[type=submit]');
            await employee.wait("location.pathname === '/vouchers' && !!document.querySelector('#delete-selected-form')");
            const [firstFiles, secondFiles] = await Promise.all([inputFiles(1, 3000), inputFiles(1, 3001)]);
            const [firstBatch, secondBatch] = await Promise.all([upload(page, firstFiles), upload(employee, secondFiles)]);
            assert.notEqual(firstBatch, secondBatch);
            assert.equal(inspect().staged_pdfs, 2);
            await page.click('button[form=cancel-upload]');
            await page.wait("location.pathname === '/vouchers' && !!document.querySelector('#delete-selected-form')");
            await employee.navigate(`${origin}/vouchers/confirm`);
            assert.equal(await employee.evaluate("document.querySelector('#voucher-review').dataset.batchId"), secondBatch);
            assert.equal(inspect().staged_pdfs, 1);
            await fillAmounts(employee);
            await employee.click('#voucher-finalize-form button[type=submit].primary');
            await employee.wait("location.pathname === '/vouchers' && document.body.textContent.includes('1 voucher(s) saved.')");
            assert.equal(inspect().vouchers, 313);
            assert.equal(inspect().saved_pdfs, 313);
        });

        await runTest('a saved PDF downloads and deleting two selected vouchers removes only those records and PDFs', async () => {
            await page.navigate(`${origin}/vouchers`);
            await browserControl.send('Browser.setDownloadBehavior', { behavior: 'allow', downloadPath: join(directory, 'downloads') });
            await page.click('a.record-link');
            await page.wait("!!document.querySelector('a[href$=\"/download\"]')");
            await page.click('a[href$="/download"]');
            let downloaded;
            for (let attempt = 0; attempt < 200; attempt++) {
                downloaded = (await readdir(join(directory, 'downloads'))).find(name => name.endsWith('.pdf'));
                if (downloaded) break;
                await pause(75);
            }
            assert.ok(downloaded, 'The actual download link should produce a PDF file.');
            assert.ok((await readFile(join(directory, 'downloads', downloaded), 'utf8')).startsWith('%PDF-1.4'));
            await page.navigate(`${origin}/vouchers`);
            await page.click('#record-management summary');
            await page.evaluate("[...document.querySelectorAll('[data-select-pdf]')].slice(0, 2).forEach(input => input.click())");
            assert.equal(await page.evaluate("document.querySelector('#selected-pdf-count').textContent"), '2 PDFs selected');
            const responseCount = page.responses.length;
            await page.click('#delete-selected-button');
            await page.response('/vouchers', 200, responseCount);
            await page.wait("document.querySelector('#selected-pdf-count')?.textContent === '0 PDFs selected'");
            assert.equal(inspect().vouchers, 311);
            assert.equal(inspect().saved_pdfs, 311);
        });

        await runTest('a real scanned PDF flows through HTTP upload, standalone OCR, browser polling and saving', async () => {
            const sample = process.env.VOUCHER_OCR_SAMPLE ?? join(project, '.project-backups', 'ocr-test-sample.pdf');
            const hash = bytes => createHash('sha256').update(bytes).digest('hex');
            const before = hash(await readFile(sample));
            const copy = join(directory, 'inputs', '10779_2026012525_PLDT INC..pdf');
            await copyFile(sample, copy);
            await upload(page, [copy]);
            assert.equal(await page.evaluate("document.querySelector('[data-voucher-amount]').value"), '');
            await Promise.all([
                runWorker(),
                page.wait("document.querySelector('[data-review-item]').dataset.amountStatus === 'ready' && document.querySelector('[data-voucher-amount]').value === '5250.00'", 80000),
            ]);
            await page.click('#voucher-finalize-form button[type=submit].primary');
            await page.wait("location.pathname === '/vouchers' && document.body.textContent.includes('1 voucher(s) saved.')");
            const state = inspect();
            assert.equal(state.vouchers, 312);
            assert.equal(state.saved_pdfs, 312);
            assert.equal(state.scanned_amount, '5250.00');
            assert.equal(hash(await readFile(sample)), before, 'The supplied private sample must remain unchanged.');
        });

        await runTest('a voucher saves without an amount and can be completed from its details', async () => {
            const before = inspect();
            await upload(page, await inputFiles(1, 4000));
            assert.equal(await page.evaluate("document.querySelector('[data-voucher-amount]').value"), '');
            assert.equal(await page.evaluate("document.querySelector('[data-voucher-amount]').required"), false);
            await page.click('#voucher-finalize-form button[type=submit].primary');
            await page.wait("location.pathname === '/vouchers' && document.body.textContent.includes('1 voucher(s) saved.')");
            assert.equal(inspect().vouchers, before.vouchers + 1);
            assert.equal(inspect().saved_pdfs, before.saved_pdfs + 1);
            await page.navigate(`${origin}/vouchers?q=2026004000`);
            assert.equal(await page.evaluate("document.querySelector('td[data-saved-amount]').textContent.trim()"), 'Reading amount…');
            assert.equal(await page.evaluate("document.querySelector('#amount-notification .notification-count') === null"), true);
            const timeOrigin = await page.evaluate('performance.timeOrigin');
            await Promise.all([
                runWorker(),
                page.wait("document.querySelector('#amount-notification .notification-count')?.textContent.trim() === '1'", 80000),
            ]);
            assert.equal(await page.evaluate('performance.timeOrigin'), timeOrigin, 'Failed OCR must update the bell without reloading.');
            await page.wait("document.body.textContent.includes('1 voucher(s) need an amount.')");
            assert.equal(await page.evaluate("document.querySelector('#amount-notification .notification-count').textContent.trim()"), '1');
            await page.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
            assert.equal(await page.evaluate("(() => { const rect = document.querySelector('#amount-notification').getBoundingClientRect(); return rect.left >= 0 && rect.right <= innerWidth && rect.width >= 44 && rect.height >= 44; })()"), true, 'Bell must remain visible and easy to tap on a phone-sized screen.');
            await page.click('#amount-notification');
            await page.wait("location.search === '?amount_status=missing' && document.querySelectorAll('a.record-link').length === 1");
            assert.equal(await page.evaluate("document.querySelector('td.amount').textContent.trim()"), 'Amount needed');
            await page.click('a.record-link');
            await page.wait("!!document.querySelector('form[action$=\"/amount\"]')");
            assert.equal(await page.evaluate("document.querySelector('.large-amount').textContent.trim()"), 'Amount needed');
            assert.equal(await page.evaluate(`document.querySelector('form[action$="/amount"] input[name="_method"]').value`), 'PATCH');
            await page.evaluate(`document.querySelector('input[name="amount"]').value = '6789.12'`);
            await page.click('form[action$="/amount"] button[type=submit]');
            await page.wait("document.querySelector('.large-amount')?.textContent.includes('6,789.12') && !document.querySelector('#amount-editor').open");
            await page.click('#amount-editor summary');
            assert.equal(await page.evaluate("document.querySelector('input[name=amount]').value"), '6789.12');
            await page.evaluate("document.querySelector('input[name=amount]').value = '5432.10'");
            await page.click('form[action$="/amount"] button[type=submit]');
            await page.wait("document.querySelector('.large-amount')?.textContent.includes('5,432.10') && !document.querySelector('#amount-editor').open");
            await page.click('#amount-editor summary');
            await page.evaluate("document.querySelector('input[name=amount]').value = '-1'; document.querySelector('form[action$=\"/amount\"]').noValidate = true");
            await page.click('form[action$="/amount"] button[type=submit]');
            await page.wait("!!document.querySelector('.notice.error') && document.querySelector('#amount-editor').open");
            assert.equal(await page.evaluate("document.querySelector('input[name=amount]').value"), '-1');
            assert.equal(await page.evaluate("document.querySelector('.large-amount').textContent.includes('5,432.10')"), true);
            await page.navigate(`${origin}/vouchers?amount_status=missing`);
            assert.equal(await page.evaluate("document.querySelectorAll('a.record-link').length"), 0);
            assert.equal(await page.evaluate("document.body.textContent.includes('voucher(s) need an amount.')"), false);
            assert.equal(await page.evaluate("document.querySelector('#amount-notification .notification-count') === null"), true);
            assert.equal(await page.evaluate("document.querySelector('#amount-notification').getAttribute('aria-label')"), 'Notifications: No vouchers need an amount');
            await page.send('Emulation.clearDeviceMetricsOverride');
            assert.equal(inspect().saved_pdfs, before.saved_pdfs + 1);
        });

        assert.deepEqual(pages.flatMap(page => page.exceptions), [], 'Browser JavaScript must not throw.');
    } catch (error) {
        console.error(logs.slice(-12000));
        throw error;
    } finally {
        pages.forEach(page => page.close());
        browserControl?.close();
        for (const child of [worker, browser, server]) {
            if (!child || child.exitCode !== null) continue;
            if (process.platform === 'win32') {
                try { execFileSync('taskkill', ['/PID', String(child.pid), '/T', '/F'], { windowsHide: true, stdio: 'ignore' }); } catch {}
            } else child.kill('SIGTERM');
        }
        // Only remove the random directory created by this test inside the testing root.
        assert.ok(directory.startsWith(testingParent + sep) && directory.slice(testingParent.length + 1).startsWith('browser-'));
        await rm(directory, { recursive: true, force: true, maxRetries: 20, retryDelay: 100 });
    }
});
