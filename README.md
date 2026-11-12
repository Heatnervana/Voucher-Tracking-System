# PPA Voucher Tracker

An office voucher library built with Laravel 12, Blade, Vite and SQLite. Staff search and view the shared PDFs. Administrators manage uploads, folders, voucher details, accounts, the recycle bin and activity history. Local OCR suggests amounts; uncertain amounts remain available for manual completion inside the voucher view.

The current deployment is private office hosting on a Windows PC, with optional private Tailscale HTTPS access. It is not a public internet deployment. Existing administrator access is not limited to one device by this application patch.

## Fresh development installation

Use the installed package versions and committed lockfiles. The package PHP requirement is `^8.2`; verify a maintained compatible runtime and its extensions. The audit used PHP 8.5.11 and Node 24 tooling. See [runtime differences and setup](docs/SETUP_AND_DEPLOYMENT.md) before operating the existing Windows server.

```powershell
composer install --prefer-dist --no-interaction
Copy-Item .env.example .env
php artisan key:generate --no-interaction
New-Item -ItemType File database/database.sqlite
php artisan migrate --no-interaction
npm ci
npm run build
php artisan db:seed --no-interaction
```

Run these commands only for a new disposable installation. Keep the generated application key and private database safe. Never replace a live `.env`, recreate its database, or regenerate its key as an update procedure. The bootstrap seeder generates a one-time temporary administrator password only when no accounts exist; change it at first sign-in. Treat its console output as a credential. It skips existing installations without changing accounts.

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

In a second terminal, start the amount worker when local OCR tools are configured:

```powershell
php artisan queue:work database --queue=voucher-ocr --tries=1 --timeout=60 --no-interaction
```

For frontend development, `npm run dev` starts Vite; the hot-server CSP exception is restricted to the local environment. Production uses `npm run build` and must not retain a stale `public/hot` file. Uploaded PDFs belong on the private local disk, never under `public/`.

## Tests and security checks

Run tests in a disposable checkout. PHPUnit and browser fixtures isolate their records/storage; do not substitute production credentials or PDFs.

```powershell
$env:VOUCHER_OCR_SAMPLE=(Resolve-Path tests/Fixtures/amount-5250-scan.pdf).Path
php vendor/bin/phpunit --colors=never
php vendor/bin/pint --dirty --format agent
node --test tests/voucher-forms.test.mjs tests/voucher-amounts.test.mjs
npm run build
composer audit --locked --format=json
npm audit --json
```

The optional OCR integration requires native PDF/OCR tools. Windows Edge browser tests use `node --test tests/browser-flows.mjs`; configure `BROWSER_TEST_PHP` and `BROWSER_TEST_EDGE` for the installed binaries. Windows configuration checks are in `tests/office-https.test.ps1`, `tests/tailscale-https.test.ps1`, and `tests/office-backup-security.test.ps1`. They test disposable configuration/fixtures rather than provision the live network. See setup documentation for details, parallel-request tests and the separate resource-intensive folder test.

## Office operation and documentation

Existing office task/startup/backup scripts are operating tools, not fresh-install defaults. Review their effective paths, account, network, ACL and TLS configuration before running an elevated setup. Updating application packages does not update Apache, PHP, OpenSSL or native OCR parsers. The security review identified an older native web stack that requires a separately staged maintenance upgrade.

- [Security audit and remaining decisions](docs/SECURITY_AUDIT.md)
- [Security policy and incident handling](SECURITY.md)
- [Architecture and trust boundaries](docs/ARCHITECTURE.md)
- [Current release readiness and remaining acceptance](docs/RELEASE_READINESS.md)
- [Dependency inventory and advisory checks](docs/DEPENDENCIES.md)
- [Setup, private deployment, backups and recovery](docs/SETUP_AND_DEPLOYMENT.md)
- [Developer handoff, business rules and test selection](docs/DEVELOPER_GUIDE.md)
- [Office configuration and machine portability catalog](docs/OFFICE_CONFIGURATION.md)
- [Change history](CHANGELOG.md)

Do not commit `.env`, credentials, private certificate keys, database files, vouchers, backups or runtime logs. Report suspected vulnerabilities privately as described in the security policy.
