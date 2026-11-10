import test from 'node:test';
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { mkdir, writeFile, rm } from 'node:fs/promises';
import { resolve, join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'node:net';
import { randomUUID } from 'node:crypto';

const project = resolve(fileURLToPath(new URL('..', import.meta.url)));
const php = process.env.BROWSER_TEST_PHP ?? 'C:\\xampp\\php\\php.exe';
const pause = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));

async function freePort() {
    const server = createServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const port = server.address().port;
    await new Promise(resolve => server.close(resolve));
    return port;
}

class EmployeeSession {
    cookies = new Map();

    constructor(origin) { this.origin = origin; }

    async request(path, data) {
        const multipart = data instanceof FormData;
        const response = await fetch(this.origin + path, {
            method: data ? 'POST' : 'GET', redirect: 'manual',
            headers: {
                Accept: multipart ? 'application/json' : 'text/html', Cookie: [...this.cookies].map(([key, value]) => `${key}=${value}`).join('; '),
                Referer: this.origin + '/vouchers',
            },
            body: multipart ? data : data ? new URLSearchParams({ _token: this.csrf, ...data }) : undefined,
            signal: AbortSignal.timeout(30000),
        });
        for (const cookie of response.headers.getSetCookie()) {
            const pair = cookie.split(';', 1)[0];
            const separator = pair.indexOf('=');
            this.cookies.set(pair.slice(0, separator), pair.slice(separator + 1));
        }
        const body = await response.text();
        return { status: response.status, body, location: response.headers.get('location') };
    }

    async login(username, password) {
        const login = await this.request('/login');
        assert.equal(login.status, 200);
        this.csrf = login.body.match(/name="_token" value="([^"]+)"/)[1];
        assert.equal((await this.request('/login', { username, password })).status, 302);
        const library = await this.request('/vouchers');
        assert.equal(library.status, 200, 'The isolated employee must be authenticated with no temporary password restriction.');
        this.csrf = library.body.match(/name="_token" value="([^"]+)"/)[1];
    }
}

test('two PHP processes serialize conflicting PDF changes without losing bytes', { timeout: 180000 }, async t => {
    const parent = join(project, 'storage', 'framework', 'testing');
    const directory = join(parent, `browser-${randomUUID()}`);
    const servers = [];
    let logs = '';
    await mkdir(directory, { recursive: true });
    for (const folder of ['private', 'cache', 'locks', 'sessions', 'views', 'framework']) await mkdir(join(directory, folder));
    await writeFile(join(directory, 'database.sqlite'), '');
    const environment = { ...process.env, LARAVEL_STORAGE_PATH: directory, APP_CONFIG_CACHE: relative(project, join(directory, 'config.php')) };
    const fixture = (mode, argument) => execFileSync(php, ['tests/Browser/fixture.php', mode, directory, ...(argument === undefined ? [] : [String(argument)])], {
        cwd: project, env: environment, encoding: 'utf8', timeout: 30000,
    });
    const inspect = () => JSON.parse(fixture('inspect-parallel'));
    const expected = new Map();
    const expectedTrash = new Map();
    const assertIntact = state => {
        assert.equal(state.records.length, expected.size);
        const allRecords = [...state.records, ...state.trash_records];
        assert.deepEqual([...state.files].sort(), allRecords.map(record => record.path).sort(), 'Every PDF must belong to an active or recycled record, without temporary leftovers.');
        for (const record of state.records) assert.equal(record.hash, expected.get(record.id), `PDF bytes changed or disappeared for voucher ${record.id}.`);
        assert.equal(state.trash_records.length, expectedTrash.size);
        for (const record of state.trash_records) assert.equal(record.hash, expectedTrash.get(record.id), `A recycled PDF changed or disappeared: ${record.id}.`);
    };

    try {
        const origins = [`http://127.0.0.1:${await freePort()}`, `http://127.0.0.1:${await freePort()}`];
        fixture('prepare', origins[0]);
        let seeded = JSON.parse(fixture('seed-parallel', 0));
        for (const origin of origins) {
            const server = spawn(php, ['-S', origin.replace('http://', ''), '-t', join(project, 'public'), join(project, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], {
                cwd: join(project, 'public'), env: environment, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'],
            });
            servers.push(server);
            server.stdout.on('data', chunk => { logs += chunk; });
            server.stderr.on('data', chunk => { logs += chunk; });
            let ready = false;
            for (let attempt = 0; attempt < 100; attempt++) {
                if (server.exitCode !== null) throw new Error(`Private PHP server exited: ${logs}`);
                try { if ((await fetch(origin + '/login')).ok) { ready = true; break; } } catch {}
                await pause(100);
            }
            assert.equal(ready, true, 'Both independent PHP server processes must start.');
        }
        const first = new EmployeeSession(origins[0]);
        const second = new EmployeeSession(origins[1]);
        await Promise.all([first.login('browser-tester', 'TemporaryBrowser123!'), second.login('browser-employee', 'BrowserEmployee123!')]);

        await t.test('103 PDFs remain accounted for while another tab polls the same session', async () => {
            const started = await first.request('/vouchers/upload/start', { year: '2026', type: 'CHECK', month: '6', expected_files: '103' });
            assert.equal(started.status, 200);
            const batchId = JSON.parse(started.body).batch_id;
            const otherTab = new EmployeeSession(origins[1]);
            otherTab.cookies = new Map(first.cookies);
            otherTab.csrf = first.csrf;
            for (let offset = 0; offset < 103; offset += 5) {
                const data = new FormData();
                data.append('_token', first.csrf);
                data.append('batch_id', batchId);
                data.append('chunk_id', randomUUID());
                const count = Math.min(5, 103 - offset);
                data.append('expected_files', String(count));
                for (let index = offset; index < offset + count; index++) {
                    const name = `${70000 + index}_${9000000000 + index}_Parallel.pdf`;
                    data.append('files[]', new Blob(['%PDF-1.4\n', new Uint8Array(64000), '\n%%EOF'], { type: 'application/pdf' }), name);
                    data.append('file_labels[]', `June/${name}`);
                }
                const [chunk, poll] = await Promise.all([
                    first.request('/vouchers/upload/chunk', data),
                    otherTab.request('/vouchers/amount-status'),
                ]);
                assert.equal(chunk.status, 200, chunk.body);
                assert.equal(poll.status, 200, poll.body);
                assert.equal(JSON.parse(chunk.body).files_total, offset + count);
            }
            const completed = await first.request('/vouchers/upload/complete', { batch_id: batchId });
            assert.equal(completed.status, 200, completed.body);
            const review = await first.request('/vouchers/confirm');
            assert.equal(review.status, 200);
            assert.ok(review.body.includes('103 selected'));
            assert.ok(review.body.includes('0 rejected'));
        });

        for (let iteration = 0; iteration < 5; iteration++) {
            if (iteration !== 0) seeded = JSON.parse(fixture('seed-parallel', iteration));
            for (const voucher of Object.values(seeded)) expected.set(voucher.id, voucher.hash);

            await t.test(`competing renames preserve both different PDFs, round ${iteration + 1}`, async () => {
                const name = `shared-${iteration}.pdf`;
                const requests = [
                    () => first.request(`/vouchers/${seeded.left.id}/filename`, { _method: 'PATCH', filename: name }),
                    () => second.request(`/vouchers/${seeded.right.id}/filename`, { _method: 'PATCH', filename: name }),
                ];
                if (iteration % 2) requests.reverse();
                const responses = await Promise.all(requests.map(request => request()));
                assert.deepEqual(responses.map(response => response.status), [302, 302]);
                const state = inspect();
                assertIntact(state);
                assert.equal(state.records.filter(record => record.path === `vouchers/2026/ADA/10/${name}`).length, 1);
            });

            await t.test(`concurrent rename and delete leave no orphan or missing PDF, round ${iteration + 1}`, async () => {
                const requests = [
                    () => first.request(`/vouchers/${seeded.victim.id}/filename`, { _method: 'PATCH', filename: `deleted-${iteration}.pdf` }),
                    () => second.request(`/vouchers/${seeded.victim.id}`, { _method: 'DELETE' }),
                ];
                if (iteration % 2) requests.reverse();
                const responses = await Promise.all(requests.map(request => request()));
                assert.ok(responses.every(response => [302, 404].includes(response.status)), JSON.stringify(responses.map(response => ({ status: response.status, body: response.body.slice(0, 150) }))));
                expected.delete(seeded.victim.id);
                expectedTrash.set(seeded.victim.id, seeded.victim.hash);
                const state = inspect();
                assertIntact(state);
                assert.equal(state.records.some(record => record.id === seeded.victim.id), false);
            });
        }
    } catch (error) {
        console.error(logs.slice(-12000));
        throw error;
    } finally {
        for (const server of servers) {
            if (server.exitCode !== null) continue;
            if (process.platform === 'win32') {
                try { execFileSync('taskkill', ['/PID', String(server.pid), '/T', '/F'], { windowsHide: true, stdio: 'ignore' }); } catch { server.kill(); }
            } else server.kill('SIGTERM');
        }
        assert.ok(directory.startsWith(parent + sep) && directory.slice(parent.length + 1).startsWith('browser-'));
        await rm(directory, { recursive: true, force: true, maxRetries: 20, retryDelay: 100 });
    }
});
