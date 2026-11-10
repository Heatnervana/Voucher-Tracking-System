import { existsSync, realpathSync } from 'node:fs';
import { basename, dirname, relative, resolve } from 'node:path';
import { defineConfig, devices } from '@playwright/test';

function isolatedFixture() {
    const storage = process.env.LARAVEL_STORAGE_PATH;
    const configCache = process.env.APP_CONFIG_CACHE;
    const fixture = storage && existsSync(storage) ? realpathSync(storage) : null;

    if (!fixture
        || dirname(fixture) !== realpathSync('storage/framework/testing')
        || !basename(fixture).startsWith('browser-')
        || !configCache || !existsSync(configCache)
        || realpathSync(configCache) !== resolve(fixture, 'config.php')) {
        throw new Error('Prepare an isolated browser fixture before running Playwright.');
    }

    return fixture;
}

const preview = process.env.BASE_URL ? new URL(process.env.BASE_URL) : null;
if (preview && (preview.protocol !== 'https:' || preview.username || preview.password || preview.pathname !== '/' || preview.search || preview.hash)) {
    throw new Error('BASE_URL must be a preview HTTPS origin without credentials, a path, a query, or a fragment.');
}

const fixture = preview ? null : isolatedFixture();
const baseURL = preview?.origin ?? 'http://127.0.0.1:8765';

export default defineConfig({
    testDir: './tests/Browser',
    workers: 1,
    outputDir: fixture ? resolve(fixture, 'playwright-results') : 'storage/framework/testing/playwright-preview-results',
    use: {
        baseURL,
        ...(process.env.PLAYWRIGHT_CHANNEL ? { channel: process.env.PLAYWRIGHT_CHANNEL } : {}),
    },
    projects: [
        { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
        { name: 'mobile', use: { ...devices['Pixel 7'] } },
    ],
    webServer: fixture ? {
        command: 'php artisan serve --host=127.0.0.1 --port=8765 --tries=1 --no-reload --no-interaction',
        env: { APP_CONFIG_CACHE: relative(process.cwd(), resolve(fixture, 'config.php')) },
        url: baseURL + '/login',
        reuseExistingServer: false,
    } : undefined,
});
