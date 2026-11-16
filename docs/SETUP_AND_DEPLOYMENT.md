# Setup, office deployment, and recovery

Setup instructions updated: 2026-11-16. Native deployment observations below are from 2026-11-12. Examples use placeholder names such as `office.example.test` and `app.example.test`; substitute values from the approved office configuration. These instructions preserve the private office/Tailscale topology. They do not authorize deployment changes or create a device-specific administrator policy.

## Prerequisites and runtime checks

Use the committed Composer and npm lockfiles. Confirm the installed versions before changing package APIs:

```powershell
php --version
composer show --direct
node --version
npm --version
```

The 2026-11-16 cleanup verified Laravel 12.72.0 with PHP 8.2.12. The earlier 2026-11-12 review observed Laravel 12.70.1, PHP 8.5.11 on the CLI, and a separate XAMPP PHP 8.2.12 used by Apache and the amount worker. Apache reported 2.4.58 and OpenSSL 3.1.3. Composer models PHP 8.2.12 through `config.platform.php`; a successful install under a newer CLI does not prove that the web runtime has every required extension. Verify the Apache PHP configuration and worker executable as well. Do not expose `phpinfo()` through a public diagnostic route.

Install the extensions required by Composer and the application, including SQLite/PDO SQLite, mbstring, DOM/XML, and file handling support. CI uses Node 24 and tests PHP 8.2, 8.3, 8.4, and 8.5. Native PDF parsing/OCR needs Poppler (`pdftotext`, `pdftoppm`) and Tesseract where available; Windows can render a PDF page through the supplied PowerShell fallback. Missing tools leave amounts for manual entry. Review native version support and replacement in a separate maintenance window.

## Fresh local installation

Use a new checkout with no existing database, environment, or recovery material. Run these commands from its project directory:

```powershell
composer run setup
php artisan db:seed --no-interaction
```

The setup guard runs before dependency installation, key generation, and migrations. It refuses an existing `.env`, `bootstrap/cache/config.php`, or nonempty `database/database.sqlite`. It also refuses nonempty inherited `APP_KEY`, `APP_ENV`, `DB_CONNECTION`, `DB_DATABASE`, `DB_URL`, `LARAVEL_STORAGE_PATH`, `APP_CONFIG_CACHE`, `APP_PACKAGES_CACHE`, `APP_SERVICES_CACHE`, `APP_EVENTS_CACHE`, and `APP_ROUTES_CACHE` values without printing them. These cache overrides can redirect Composer cleanup and Laravel cache reads or writes. Use a fresh development terminal without those overrides. Do not delete existing installation files to bypass the guard; follow the update procedure below. If setup stops after creating `.env`, resolve the failed step and resume that step manually, preserving any key already generated.

After the guard passes, setup installs the locked dependencies, copies `.env.example`, creates SQLite if absent, generates the initial key, runs migrations, and builds the frontend. The example uses `DB_CONNECTION=sqlite` and `database/database.sqlite` inside the checkout, with `APP_ENV=local`, `APP_DEBUG=false`, `LOG_LEVEL=info`, `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`, and `FILESYSTEM_DISK=local`. `SESSION_DOMAIN=null` keeps cookies host-scoped. A nullable `SESSION_SECURE_COOKIE` lets the framework mark cookies secure on HTTPS; HTTP local development must not force a secure-only cookie.

Generate `APP_KEY` only for a fresh installation. Preserve the existing key on an update or restore. `composer run setup` is a fresh-install command, not an update or recovery procedure.

Use database seeding only for initial account bootstrap. The bootstrap seeder creates a new administrator with a generated temporary password and requires a password change; transfer that password through a private channel. It preserves an existing account's credentials and role. Confirm that behavior in the deployed revision before using the seeder. Manage later accounts through the authenticated account screen, or create a staff account with:

```powershell
php artisan users:create-staff example.staff --name="Example Staff" --no-interaction
```

The command prints a temporary password. Keep terminal captures private. `composer run dev` starts the Laravel server, the database `voucher-ocr` worker, and Vite together using the installed `concurrently` package. Laravel and Vite bind to `127.0.0.1`; Ctrl+C stops the processes. The worker uses one attempt and a 60-second timeout. The local Vite hot-server exception relaxes resource CSP directives only when `APP_ENV=local` and the hot file exists. Build assets with `npm run build` before checking the production CSP.

## Existing office installation

Before an update, identify the running checkout, database, private storage, service identity, PHP executable, Apache configuration, task definitions, and certificate material. Verify a current backup and a disposable restore. Keep `.env`, `APP_KEY`, existing accounts, and PDFs intact.

For a reviewed release, install the lockfile dependencies and build assets in a staging checkout. An administrator can install production PHP dependencies with `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction`; the build machine still needs npm development dependencies to run Vite. Check the migration diff before applying `php artisan migrate --force --no-interaction` to an existing database. Take the pre-migration backup first. Avoid `migrate:fresh`, `db:wipe`, and bootstrap seeding during routine updates.

The deployed environment should use `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=info`, a correct HTTPS `APP_URL`, database sessions, and private local storage. On an HTTPS-only installation set `SESSION_SECURE_COOKIE=true`; verify sign-in on both the approved office and Tailscale entry points. A host-scoped cookie gives each hostname its own browser session. Use the reviewed hostname rather than trusting arbitrary Host or forwarding headers.

Point the web document root at `public/` or preserve the existing reviewed static-serving arrangement. Grant the service identity write access to the SQLite database directory, `storage/`, and `bootstrap/cache/`. Restrict `.env`, private PDFs, database files, backup archives, certificate keys, and logs to designated identities. Do not create a link or web alias to `storage/app/private`. `storage:link` is not needed for voucher PDFs and must not expose them.

After changing configuration, refresh the application's configuration cache through the approved release process and restart long-running workers so they load the release. A cached configuration can retain old paths and credentials. Verify `/up`, sign-in, role restrictions, PDF display/download, review/save, and OCR/manual fallback before ending maintenance.

## HTTPS and Windows tasks

The existing office entry point uses HTTPS on port 8443. The legacy HTTP port 8080 redirects GET/HEAD requests and rejects state-changing methods. Tailscale Serve uses HTTPS 443 and forwards to the HTTPS backend over loopback. Apache trusts forwarded client-IP headers only from that loopback hop through `mod_remoteip`. Preserve network and hostname allowlists; do not substitute a wildcard proxy trust rule.

`Set up office HTTPS.ps1` and `Set up Tailscale HTTPS.ps1` have preview modes. They contain environment-specific assumptions and can alter Apache, certificates, or tasks in apply mode. Review the generated configuration, archives, hostnames, and rollback before an administrator applies a change. `Install office certificate.ps1` changes client trust and also requires review. Do not distribute CA private keys to clients. Keep certificate expiry monitoring and renewal records in the office's protected operational records.

`Start amount reader.cmd` uses the existing amount-reader scheduled task when present, otherwise starts its worker loop. The worker command is:

```powershell
php artisan queue:work database --queue=voucher-ocr --sleep=1 --tries=1 --timeout=60 --memory=256 --max-time=3600 --no-interaction
```

The Windows wrapper prefers XAMPP PHP when available. Check that executable's version/extensions and the worker log; the CLI on `PATH` may differ. The wrapper restarts a stopped worker loop. Do not launch duplicate workers as a substitute for checking the existing scheduled task.

`Run office task.ps1` supports backup and HTTPS uptime tasks with explicit arguments. Example values are placeholders:

```powershell
powershell -NoProfile -File '.\Run office task.ps1' -Task Backup -BackupDestination 'D:\PrivateVoucherBackups'
powershell -NoProfile -File '.\Run office task.ps1' -Task Uptime -HealthUrl 'https://office.example.test:8443/up'
```

Review backup-task, uptime, worker, Laravel, and Apache logs through a protected administrative process. A successful `/up` check does not prove OCR, backup recovery, account authorization, or PDF availability. Investigate failed jobs with the installed Artisan queue commands; inspect `php artisan list --no-interaction` and command help before retrying work.

## Backup and restore rehearsal

Create a backup to an existing approved private destination:

```powershell
php artisan vouchers:backup 'D:\PrivateVoucherBackups' --no-interaction
```

The command supports SQLite. It coordinates with voucher file changes, creates a database snapshot, checks SQLite integrity, copies saved/recycled PDFs, verifies SHA-256 values, and writes `manifest.json` before publishing a complete directory. It includes `.env` when present. Protect backup copies as secrets, including offline copies; a directory name or successful task log alone is not a recovery test.

Rehearse recovery without overwriting the live database:

1. Choose a completed backup whose manifest reports `status: complete`. Reject `.incomplete-*` directories. Copy it into a newly created, access-restricted recovery area.
2. Verify the SHA-256 of each file against `manifest.json`, including `database.sqlite`, `files/vouchers/...`, and `.env` if listed. Use `Get-FileHash -Algorithm SHA256` or another reviewed tool. Reject unexpected paths, files outside the backup root, missing files, or mismatched hashes.
3. Prepare a separate application checkout at the backed-up release. Copy the snapshot to a disposable SQLite path and the backed-up `files/vouchers/` tree to that checkout's private local disk. Keep the original archive unchanged.
4. Prepare a private recovery `.env`. Preserve the original `APP_KEY`, but replace the database path, application URL, storage/log locations, and any outbound service settings with disposable values. Confirm the effective database and filesystem configuration before starting a web process or worker. Do not run `key:generate`.
5. Open only the disposable database with a read-only SQLite tool. Require `PRAGMA integrity_check` to return `ok` and `PRAGMA foreign_key_check` to return no rows. Compare the manifest's voucher count with all saved and recycled database records, then check their PDF paths and checksums.
6. In the isolated recovery app, verify sign-in, staff/admin permissions, representative PDF opening/download, recycled records, and manual amount entry. Keep OCR and outbound integrations disabled until their disposable paths/configuration have been checked.
7. Record the rehearsal's release, backup, checks performed, and result in protected operational records. A live restore requires an approved outage, a fresh safety backup, stopped writers/workers, reviewed path mapping, and a rollback plan. Do not promote a rehearsal by copying over live files while services are writing.

The archive does not replace backups of Apache configuration, Windows scheduled tasks, certificate/CA material, native tools, or operating-system recovery data. Protect and rehearse those through the office's separate recovery process.

## Verification commands

Run tests from a disposable checkout. PHPUnit uses the test database configuration, and the browser harnesses create their own fixture database/storage. Confirm fixture isolation before browser testing. Tests are not a reason to use live accounts or production PDFs.

```powershell
php artisan test --compact
node --test tests/voucher-forms.test.mjs tests/voucher-amounts.test.mjs
powershell -NoProfile -File '.\tests\office-https.test.ps1'
powershell -NoProfile -File '.\tests\tailscale-https.test.ps1'
powershell -NoProfile -File '.\tests\office-backup-security.test.ps1'
```

The Windows browser regression harness uses installed Edge and PHP; override the executable paths when they differ:

```powershell
$env:BROWSER_TEST_PHP = 'C:\path\to\php.exe'
$env:BROWSER_TEST_EDGE = 'C:\path\to\msedge.exe'
node --test tests/browser-flows.mjs
node --test tests/parallel-requests.mjs
```

`tests/large-folder-upload.mjs` provides a separate high-volume upload check; review its resource needs before running it. CI's Playwright smoke suite uses a disposable directory under `storage/framework/testing/browser-*`, sets `LARAVEL_STORAGE_PATH` and `APP_CONFIG_CACHE`, calls `tests/Browser/fixture.php prepare` and `seed-parallel`, then runs `npx playwright test`. Follow that fixture sequence in `.github/workflows/tests.yml`; a plain Playwright invocation refuses an unprepared local fixture. Use `BASE_URL` only for an approved HTTPS preview and never for unintended writes to a live deployment.

Run `composer audit`, `npm audit`, and the approved working-tree/history secret scan before release. Review native runtime advisories separately. Browser verification should include desktop/mobile sign-in, confirm accept/cancel, structured rename, upload review, native PDF display, CSP failures, and logout access checks. No Python test suite is defined in this repository.

## Assumptions and manual decisions

These procedures assume SQLite, database sessions/queue, the private local filesystem, and the existing office TLS topology. Revisit backup/session behavior before changing those components. Test results do not replace review of Windows ACLs, Apache effective configuration, firewall rules, Tailscale access policy, certificate trust/expiry, native parser updates, or an actual restore rehearsal. Live runtime upgrades, secret rotation, trust-store changes, and recovery remain administrator decisions. See [SECURITY.md](../SECURITY.md) for reporting and rotation procedures.
