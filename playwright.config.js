import { existsSync, realpathSync } from 'node:fs';
import { basename, dirname, relative, resolve } from 'node:path';
import { defineConfig, devices } from '@playwright/test';

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

const baseURL = 'http://127.0.0.1:8765';

export default defineConfig({
    testDir: './tests/Browser',
    workers: 1,
    outputDir: resolve(fixture, 'playwright-results'),
    use: {
        baseURL,
        ...(process.env.PLAYWRIGHT_CHANNEL ? { channel: process.env.PLAYWRIGHT_CHANNEL } : {}),
    },
    projects: [
        { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
        { name: 'mobile', use: { ...devices['Pixel 7'] } },
    ],
    webServer: {
        command: 'php artisan serve --host=127.0.0.1 --port=8765 --tries=1 --no-reload --no-interaction',
        env: { APP_CONFIG_CACHE: relative(process.cwd(), resolve(fixture, 'config.php')) },
        url: baseURL + '/login',
        reuseExistingServer: false,
    },
});
