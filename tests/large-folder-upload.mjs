import test from 'node:test';
import { createReadStream } from 'node:fs';
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { mkdir, writeFile, readFile, readdir, open, stat, truncate, rm } from 'node:fs/promises';
import { resolve, relative, join, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'node:net';
import { randomUUID, createHash } from 'node:crypto';

const project = resolve(fileURLToPath(new URL('..', import.meta.url)));
const php = process.env.BROWSER_TEST_PHP ?? 'C:\\xampp\\php\\php.exe';
const edge = process.env.BROWSER_TEST_EDGE ?? 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
const apache = process.env.BROWSER_TEST_APACHE ?? 'C:\\xampp\\apache\\bin\\httpd.exe';
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

async function writePdf(path, size, marker) {
    let length = size;
    let header;
    let footer;
    for (let attempt = 0; attempt < 5; attempt++) {
        header = `%PDF-1.4\n% large-folder-${marker}\n`;
        const offsets = [0];
        for (const object of [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << >> /Contents 4 0 R >>',
        ]) {
            offsets.push(Buffer.byteLength(header));
            header += `${offsets.length - 1} 0 obj\n${object}\nendobj\n`;
        }
        offsets.push(Buffer.byteLength(header));
        header += `4 0 obj\n<< /Length ${length} >>\nstream\n`;
        const end = '\nendstream\nendobj\n';
        const xref = Buffer.byteLength(header) + length + Buffer.byteLength(end);
        footer = `${end}xref\n0 5\n0000000000 65535 f \n${offsets.slice(1).map(offset => `${String(offset).padStart(10, '0')} 00000 n \n`).join('')}trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`;
        const next = size - Buffer.byteLength(header) - Buffer.byteLength(footer);
        if (next === length) break;
        length = next;
    }
    assert.equal(Buffer.byteLength(header) + length + Buffer.byteLength(footer), size);
    const file = await open(path, 'w');
    const hash = createHash('sha256');
    const padding = Buffer.alloc(8 * 1024 * 1024, ' ');
    const write = async bytes => {
        let offset = 0;
        while (offset < bytes.length) {
            const result = await file.write(bytes, offset, bytes.length - offset);
            assert.ok(result.bytesWritten > 0);
            offset += result.bytesWritten;
        }
        hash.update(bytes);
    };
    try {
        await write(Buffer.from(header));
        while (length > 0) {
            const chunk = padding.subarray(0, Math.min(length, padding.length));
            await write(chunk);
            length -= chunk.length;
        }
        await write(Buffer.from(footer));
    } finally { await file.close(); }
    assert.equal((await stat(path)).size, size);
    return hash.digest('hex');
}

async function pdfHashes(directory) {
    const result = [];
    for (const entry of await readdir(directory, { withFileTypes: true }).catch(error => {
        if (error.code === 'ENOENT') return [];
        throw error;
    })) {
        const path = join(directory, entry.name);
        if (entry.isDirectory()) result.push(...await pdfHashes(path));
        else if (entry.name.endsWith('.pdf')) {
            const hash = createHash('sha256');
            for await (const chunk of createReadStream(path)) hash.update(chunk);
            result.push({ size: (await stat(path)).size, hash: hash.digest('hex') });
        }
    }
    return result.sort((left, right) => left.hash.localeCompare(right.hash));
}

test('a real 5,000,000,000-byte folder uploads, stages and saves intact in Edge', { timeout: 1800000 }, async () => {
    const testingParent = join(project, 'storage', 'framework', 'testing');
    const directory = join(testingParent, `browser-large-${randomUUID()}`);
    const inputDirectory = join(directory, 'month-folder');
    const port = await freePort();
    const debugPort = await freePort();
    const origin = `http://127.0.0.1:${port}`;
    const environment = { ...process.env, QUEUE_CONNECTION: 'database', LARAVEL_STORAGE_PATH: directory, APP_CONFIG_CACHE: relative(project, join(directory, 'config.php')) };
    const inspect = () => JSON.parse(execFileSync(php, ['tests/Browser/fixture.php', 'inspect', directory], { cwd: project, env: environment, encoding: 'utf8' }));
    const started = Date.now();
    const log = message => console.log(`[${((Date.now() - started) / 1000).toFixed(1)}s] ${message}`);
    let server;
    let browser;
    let page;
    let logs = '';
    let heartbeat;
    try {
        for (const folder of ['private', 'cache', 'locks', 'sessions', 'views', 'month-folder/nested', 'framework/locks', 'php-uploads']) {
            await mkdir(join(directory, folder), { recursive: true });
        }
        await writeFile(join(directory, 'database.sqlite'), '');
        log(`Isolated test directory: ${directory}`);
        const expected = [];
        const inputPaths = [];
        for (let index = 0; index < 10; index++) {
            const path = join(inputDirectory, index < 5 ? '' : 'nested', `${7000 + index}_${2026700000 + index}_LARGE FOLDER TEST.pdf`);
            const hash = await writePdf(path, 500_000_000, index);
            expected.push({ size: 500_000_000, hash });
            inputPaths.push(path);
            log(`Generated ${index + 1}/10 PDFs (${(index + 1) * 500_000_000} bytes).`);
        }
        expected.sort((left, right) => left.hash.localeCompare(right.hash));
        assert.equal(expected.reduce((sum, file) => sum + file.size, 0), 5_000_000_000);
        execFileSync(php, ['tests/Browser/fixture.php', 'prepare', directory, origin], { cwd: project, env: environment, encoding: 'utf8' });
        const apachePath = path => path.replaceAll('\\', '/');
        const apacheConfig = join(directory, 'httpd.conf');
        await writeFile(apacheConfig, `ServerRoot "C:/xampp/apache"
Listen 127.0.0.1:${port}
ServerName 127.0.0.1
PidFile "${apachePath(join(directory, 'httpd.pid'))}"
ErrorLog "${apachePath(join(directory, 'apache-error.log'))}"
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule dir_module modules/mod_dir.so
LoadModule mime_module modules/mod_mime.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule env_module modules/mod_env.so
LoadFile "C:/xampp/php/php8ts.dll"
LoadFile "C:/xampp/php/libpq.dll"
LoadFile "C:/xampp/php/libsqlite3.dll"
LoadModule php_module "C:/xampp/php/php8apache2_4.dll"
PHPINIDir "C:/xampp/php"
TypesConfig conf/mime.types
DirectoryIndex index.php
Timeout 600
LimitRequestBody 1073741824
DocumentRoot "${apachePath(join(project, 'public'))}"
SetEnv LARAVEL_STORAGE_PATH "${apachePath(directory)}"
SetEnv APP_CONFIG_CACHE "${apachePath(relative(project, join(directory, 'config.php')))}"
php_admin_value upload_tmp_dir "${apachePath(join(directory, 'php-uploads'))}"
<Directory "${apachePath(join(project, 'public'))}">
    Options FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
<FilesMatch "\\.php$">
    SetHandler application/x-httpd-php
</FilesMatch>
`);
        execFileSync(apache, ['-t', '-f', apacheConfig], { windowsHide: true, encoding: 'utf8' });
        server = spawn(apache, ['-f', apacheConfig], {
            cwd: join(project, 'public'), env: environment, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'],
        });
        server.stdout.on('data', chunk => { logs = (logs + chunk).slice(-30000); });
        server.stderr.on('data', chunk => { logs = (logs + chunk).slice(-30000); });
        await untilHttp(`${origin}/login`, server, () => logs);
        browser = spawn(edge, ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--disable-background-networking', `--remote-debugging-port=${debugPort}`, `--user-data-dir=${join(directory, 'edge-profile')}`, 'about:blank'], {
            windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'],
        });
        browser.stderr.on('data', chunk => { logs = (logs + chunk).slice(-30000); });
        await untilHttp(`http://127.0.0.1:${debugPort}/json/version`, browser, () => logs);
        const target = await (await fetch(`http://127.0.0.1:${debugPort}/json/new?about:blank`, { method: 'PUT' })).json();
        page = await BrowserPage.connect(target.webSocketDebuggerUrl);
        await page.navigate(`${origin}/login`);
        await page.evaluate("document.querySelector('[name=username]').value='browser-employee'; document.querySelector('[name=password]').value='BrowserEmployee123!';");
        await page.click('button[type=submit]');
        await page.wait("location.pathname === '/vouchers'");
        await page.navigate(`${origin}/vouchers/upload`);

        // Add one byte to an existing PDF to test the exact folder boundary without a second 5 GB copy.
        const firstFile = await open(inputPaths[0], 'a');
        await firstFile.write(Buffer.from(' '));
        await firstFile.close();
        await page.files('#voucher-folder-input', [inputDirectory]);
        await page.wait("document.querySelector('#voucher-folder-input').files.length === 10");
        assert.equal(await page.evaluate("[...document.querySelector('#voucher-folder-input').files].reduce((sum, file) => sum + file.size, 0)"), 5_000_000_001);
        await page.click('#voucher-upload-button');
        await page.wait("document.querySelector('#voucher-selection-summary').textContent.includes('maximum is 5 GB')");
        assert.equal(inspect().staged_pdfs, 0);
        assert.equal(page.responses.some(response => new URL(response.url).pathname.includes('/upload/start')), false);
        log('5,000,000,001-byte folder rejected before upload.');
        await truncate(inputPaths[0], 500_000_000);

        const oversized = join(directory, 'oversized.pdf');
        await writePdf(oversized, 524_288_001, 'oversized');
        await page.files('#voucher-files-input', [oversized]);
        await page.click('#voucher-upload-button');
        await page.wait("document.querySelector('#voucher-selection-summary').textContent.includes('500 MB per-file limit')");
        assert.equal(inspect().staged_pdfs, 0);
        log('524,288,001-byte single PDF rejected before upload.');
        await rm(oversized);

        await page.files('#voucher-folder-input', [inputDirectory]);
        await page.wait("document.querySelector('#voucher-folder-input').files.length === 10");
        const selected = await page.evaluate("[...document.querySelector('#voucher-folder-input').files].map(file => ({ size: file.size, path: file.webkitRelativePath }))");
        assert.equal(selected.length, 10);
        assert.equal(selected.reduce((sum, file) => sum + file.size, 0), 5_000_000_000);
        assert.equal(selected.filter(file => file.path.includes('/nested/')).length, 5);
        const uploadStarted = Date.now();
        log('Starting actual folder upload: 10 PDFs, 5,000,000,000 bytes, 5 nested PDFs.');
        heartbeat = setInterval(() => {
            page.evaluate("document.querySelector('#voucher-upload-status')?.textContent ?? location.pathname").then(log).catch(() => {});
        }, 15000);
        await page.click('#voucher-upload-button');
        await page.wait("location.pathname === '/vouchers/confirm' && document.querySelectorAll('[data-review-item]').length === 10", 1200000);
        clearInterval(heartbeat);
        heartbeat = null;
        const uploadSeconds = (Date.now() - uploadStarted) / 1000;
        log(`Upload and review ready in ${uploadSeconds.toFixed(3)} seconds; hashing staged PDFs.`);
        const staged = await pdfHashes(join(directory, 'private', 'staging'));
        assert.deepEqual(staged, expected, 'Every staged file must match its source SHA-256 and size.');
        assert.equal(inspect().staged_pdfs, 10);
        log('All 10 staged SHA-256 hashes and exact byte sizes match.');
        await page.evaluate("[...document.querySelectorAll('[data-voucher-amount]')].forEach(input => { input.value='5250.00'; input.dispatchEvent(new Event('input', {bubbles:true})); });");
        const saveStarted = Date.now();
        await page.click('#voucher-finalize-form button[type=submit].primary');
        await page.wait("location.pathname === '/vouchers' && document.body.textContent.includes('10 voucher(s) saved.')", 120000);
        const saveSeconds = (Date.now() - saveStarted) / 1000;
        assert.deepEqual(await pdfHashes(join(directory, 'private', 'vouchers')), expected, 'Every saved PDF must retain its source bytes.');
        assert.deepEqual(await pdfHashes(inputDirectory), expected, 'Source PDFs must remain unchanged.');
        const state = inspect();
        assert.equal(state.vouchers, 10);
        assert.equal(state.saved_pdfs, 10);
        assert.equal(state.staged_pdfs, 0);
        assert.deepEqual(page.exceptions, []);
        const chunks = page.responses.filter(response => new URL(response.url).pathname.endsWith('/upload/chunk'));
        assert.equal(chunks.length, 10);
        assert.ok(chunks.every(response => response.status === 200));
        log(JSON.stringify({ bytes: 5_000_000_000, files: 10, chunkRequests: chunks.length, uploadSeconds, saveSeconds, sourceStagedSavedHashesMatch: true, folderPlusOneRejected: true, fileLimitPlusOneRejected: true, server: 'isolated Apache with XAMPP PHP over loopback', ocrWorkerStarted: false }));
    } catch (error) {
        console.error(logs.slice(-12000));
        console.error(await readFile(join(directory, 'apache-error.log'), 'utf8').catch(() => 'No Apache error log.'));
        if (page) console.error(await page.evaluate('document.body.innerText').catch(() => 'Browser unavailable.'));
        throw error;
    } finally {
        if (heartbeat) clearInterval(heartbeat);
        page?.close();
        for (const child of [browser, server]) {
            if (!child || child.exitCode !== null) continue;
            if (process.platform === 'win32') {
                try { execFileSync('taskkill', ['/PID', String(child.pid), '/T', '/F'], { windowsHide: true, stdio: 'ignore' }); } catch {}
            } else child.kill('SIGTERM');
        }
        assert.ok(directory.startsWith(testingParent + sep) && directory.slice(testingParent.length + 1).startsWith('browser-large-'));
        await rm(directory, { recursive: true, force: true, maxRetries: 20, retryDelay: 100 });
        log('Removed this test’s isolated database, files, PHP temporary uploads and browser profile.');
    }
});


