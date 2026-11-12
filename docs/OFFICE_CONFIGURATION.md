# Office configuration catalog

Reviewed against the source on 2026-11-12. This is a map of configuration ownership, existing hardcoding and portability work. It does not apply machine changes. Keep real addresses, hostnames, service identities, absolute private paths, certificate fingerprints and secrets in protected office records. Examples below use placeholders.

Read [setup and recovery](SETUP_AND_DEPLOYMENT.md) for procedures, [architecture](ARCHITECTURE.md) for the request path, and [the security audit](SECURITY_AUDIT.md) for release/cutover evidence. The earlier review observed CLI PHP 8.5.11 and a separate native stack with Apache 2.4.58, PHP 8.2.12 and OpenSSL 3.1.3. Those are **pre-upgrade observations**, not a claim about a staged or running replacement. Record the chosen replacement's provenance, effective web/worker versions, paths and smoke results in the release evidence before treating a cutover as complete.

The candidate native upgrade remains staged. Endpoint protection blocked the candidate PHP executable during validation; that test run did not reach a passing summary. Native runtime approval, verification and live cutover remain pending. The PowerShell generator tests below validate configuration handling only. See the release evidence for any later change in status.

## Deployment record to maintain privately

| Item | Record and verify |
| --- | --- |
| Application | `<AppRoot>`, approved release/commit, web document root or reviewed static-serving mapping, rollback checkout |
| Native runtime | `<NativeRoot>`, Apache executable/config/modules, web PHP module/runtime, worker PHP executable, loaded `php.ini`, OpenSSL and native parser versions |
| Private state | SQLite file and its writable parent, private PDF root, temporary upload/parser directories, framework lock/cache paths, free-space reserve |
| Identity and tasks | Designated limited Windows identity/SID, effective ACLs, task actions/working directories/principals/triggers, logs and restart policy |
| Network | Approved interface/profile/subnet, office hostnames/addresses, DNS resolution, firewall rules and Tailscale access policy |
| TLS | Office issuer/server certificates and key locations, SANs, public CA fingerprint, trust stores, expiry/renewal owner, Tailscale hostname and backend route |
| Recovery | Protected backup destination, task schedule/retention, offline copy location, last successful restore rehearsal and rollback instructions |

Keep the live SQLite/PDF/lock paths on the reviewed local filesystem. A move to a network share or a multi-host deployment changes the locking, SQLite, backup and service-identity assumptions and needs separate design/testing.

## Laravel settings that already exist

Set these in the installation's private `.env`; read their mappings in the named `config/` files. Refresh cached configuration through the release procedure and restart long-running workers after changes. Do not print whole effective configuration files into a shared ticket because they can contain credentials.

| Name | Configuration location and role |
| --- | --- |
| `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_KEY` | `config/app.php`; office uses production/debug-off and its approved HTTPS URL. Preserve the existing key through upgrades/restores. |
| `DB_CONNECTION`, `DB_DATABASE`, `DB_URL`, `DB_FOREIGN_KEYS` | `config/database.php`; SQLite database path and foreign-key setting. An inherited `DB_URL` can change the target. Default SQLite path is `<AppRoot>/database/database.sqlite`. |
| `SESSION_DRIVER`, `SESSION_CONNECTION`, `SESSION_TABLE`, `SESSION_LIFETIME` | `config/session.php`; database sessions support revocation; default lifetime is 120 minutes. |
| `SESSION_SECURE_COOKIE`, `SESSION_HTTP_ONLY`, `SESSION_SAME_SITE`, `SESSION_DOMAIN`, `SESSION_PATH`, `SESSION_ENCRYPT` | `config/session.php`; office HTTPS requires secure cookies; keep host-scoped cookies unless the deployment has a reviewed reason to share them. Separate hostnames have separate sessions. |
| `CACHE_STORE`, `DB_CACHE_CONNECTION`, `DB_CACHE_TABLE`, `DB_CACHE_LOCK_CONNECTION`, `DB_CACHE_LOCK_TABLE`, `CACHE_PREFIX` | `config/cache.php`; database cache and locks carry staged OCR results and session-blocking state. |
| `QUEUE_CONNECTION`, `DB_QUEUE_CONNECTION`, `DB_QUEUE_TABLE`, `DB_QUEUE`, `DB_QUEUE_RETRY_AFTER` | `config/queue.php`; database retry-after defaults to 90 seconds. OCR jobs explicitly choose `database` / `voucher-ocr`, regardless of the default queue setting. Keep retry-after greater than the worker/job timeout. |
| `FILESYSTEM_DISK` | `config/filesystems.php`; voucher workflows explicitly use disk `local`, rooted at `storage_path('app/private')`. Changing the default disk alone does not relocate PDFs. |
| `LOG_CHANNEL`, `LOG_STACK`, `LOG_LEVEL`, `LOG_DAILY_DAYS` | `config/logging.php`; the example environment selects daily logging, info level and 14-day Laravel log retention. Worker, task and Apache logs need their own retention plan. |
| `LARAVEL_STORAGE_PATH`, `APP_CONFIG_CACHE` | Framework bootstrap environment hooks used by isolated browser fixtures. They relocate storage/config-cache paths; do not inherit test paths into office processes or point test processes at live paths. |

`config/app.php` fixes the application timezone to UTC. Activity and recycle-bin Blade views format timestamps in `Asia/Singapore`. There is no `APP_TIMEZONE` setting in this application. Review stored timestamps and display formats together before changing time handling.

## Existing fixed values and machine-specific scripts

The names below identify current code locations, not a new supported `.env` interface. The scripts still contain fixed native installation paths and office-specific values. Editing this document does not make them portable. Review the source and generated output before running a setup on another machine.

| Source | Existing input or fixed assumption | Portability action |
| --- | --- | --- |
| `Set up office access.ps1` | Parameter `-BackupDestination`; `$root` from `$PSScriptRoot`; fixed `$apache`, `$account`, `$interface`, `$subnet`, `$taskNames`, `$configPaths`; fixed network-profile guard, document-root path and task PHP path | Map the new identity/SID, interface/profile/subnet, Apache/PHP/config roots and document-root mapping. This legacy initial HTTP setup changes firewall/tasks/configuration and can rewrite `APP_URL`; do not rerun it over a working HTTPS setup as an upgrade. |
| `Set up office HTTPS.ps1` | `-Mode Preview|Apply|Rollback`, `-Archive`; fixed `$apache`, `$openssl`, `$vhostsPath`, `$mainPath`, `$sslPath`, `$privateRoot`, `$taskName`, `$hostName`, `$addresses`; generated document-root/network rules and fixed PHP/OpenSSL config references | Preview is the default. Review every location and hostname/IP SAN, including the explicit `$addresses` assignment after discovery. Apply expects a particular pre-HTTPS configuration. Preserve its deployment archive for rollback. |
| `Set up Tailscale HTTPS.ps1` | `-Mode Preview|Apply`, optional `-HostName`; fixed Tailscale executable and Apache paths; connected node identity supplies the hostname; backend health uses loopback HTTPS 8443 | Preview first. Keep the hostname tied to the node identity. Review the generated hostname/source allowlists, proxy trust and actual Tailscale Serve state. |
| `Set up office runtime.ps1` | `-Mode Preview|Stage` (default Preview); mandatory `-ApacheRoot`, `-PhpRoot`, `-VhostsPath`, `-ConfigPath`, `-LogsPath`; `-HttpListen` defaults to `0.0.0.0:8080` | Generates a separate Apache main configuration that includes the existing reviewed vhosts file. Stage checks required files/directories and refuses to overwrite an existing output. It does not start processes or alter vhosts, tasks, firewall, trust or the live installation. |
| `Install office certificate.ps1` | `-ValidateOnly`, `-Remove`; `$expectedFingerprint` template marker and adjacent public CA file; fixed final office URL message | Use the approved server-generated client package with its pinned public CA fingerprint. The unconfigured repository template refuses installation. The installer targets `CurrentUser\Root`; it does not install trust for other Windows users or separate browser trust stores. |
| `Start amount reader.cmd` | Fixed amount-reader task name; otherwise selects native PHP if present, falling back to `php`; internally sets `VOUCHER_WORKER_PHP`; wrapper retries after five seconds | Inspect the existing scheduled task before starting. `VOUCHER_WORKER_PHP` is an internal variable reset by the wrapper, not a reliable external override. Update the reviewed task/wrapper if the runtime path changes. |
| `Run office task.ps1` | `-Task Backup|Uptime`, `-BackupDestination`, `-HealthUrl`, optional `-PhpPath` retaining the existing native PHP default; `$PSScriptRoot` working directory; Windows system curl for uptime | Backup validates and invokes the selected PHP executable as a literal path. Uptime does not require PHP. This runner does not accept `Apache` or `AmountReader` task modes. Actual Apache/OCR tasks use their executable actions. |
| `app/PdfAmountReader.php` | PATH discovery for `pdftotext`/`pdftoppm`/`tesseract`; Program Files Tesseract fallback; Windows PowerShell fallback; OS temporary directory | Verify discovery under the task identity, temporary-directory permissions and parser versions. There are no `TESSERACT_PATH` or `POPPLER_PATH` application settings. |
| `app/Jobs/ReadVoucherAmount.php`, `ReadSavedVoucherAmount.php` | Queue `voucher-ocr`, connection `database`, one attempt, 60-second job timeout; saved job fails on timeout | Preserve these with the worker configuration; the default queue name does not override the job queue. |
| `VoucherController`, `resources/js/app.js`, `LockVoucherFiles` | Upload limits, browser part sizing, session block durations, file-lock waits and parser limits live in code | See the exact values below. No upload-limit environment variable currently controls these. |

The setup scripts historically target an XAMPP-shaped native layout. Changing only `PATH` or the development CLI does not update Apache's loaded PHP module, its DLL dependencies, scheduled-task actions or their working directories. A replacement environment must review all of these locations together.

## Candidate Apache configuration

After the candidate native binaries receive approval, prepare dedicated staging log/configuration directories and a reviewed vhosts file for the intended validation. Use a separate listener and synthetic vhost for an isolated smoke test. Example paths are placeholders:

```powershell
$runtimeParameters = @{
    ApacheRoot = 'C:\NativeCandidate\Apache24'
    PhpRoot = 'C:\NativeCandidate\php'
    VhostsPath = 'C:\NativeCandidate\reviewed-vhosts.conf'
    ConfigPath = 'C:\NativeCandidate\candidate-httpd.conf'
    LogsPath = 'C:\NativeCandidate\logs'
    HttpListen = '127.0.0.1:18980'
}
& '.\Set up office runtime.ps1' @runtimeParameters
& '.\Set up office runtime.ps1' @runtimeParameters -Mode Stage
```

Preview validates path/listener syntax and returns text without writing. Stage requires the referenced Apache modules, `httpd.exe`, MIME types, PHP executable/module/libraries, `php.ini`, vhosts file and existing directories. It writes one new configuration with exclusive creation. It preserves the vhosts file unchanged; that file still owns hostname/subnet rules, document roots, HTTPS listeners and certificate paths. Review its log paths and includes too: passing different main-log paths does not relocate logs declared inside a vhost.

The main configuration loads `authn_core`, `authz_core`, `authz_host`, `dir`, `mime`, `log_config`, `env`, `headers`, `rewrite`, `setenvif`, `ssl`, `socache_shmcb`, `remoteip` and the PHP module. It loads the selected PHP root's `php8ts.dll` and `libsqlite3.dll`; it does not require PostgreSQL libraries. It sets HTTP/1.1, disables server signatures and TRACE, restricts server tokens, denies the default directory, and sets a 1 GiB request-body limit and 600-second timeout. It omits HTTP/2, managed domains, proxy, CGI, user directories and autoindex modules. A reviewed included file can still add directives, so this main-file allowlist is not a substitute for reviewing included configuration.

Run `powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File tests/office-runtime.test.ps1` for disposable configuration/backup-executable tests. These tests do not execute Apache or PHP. After native-binary approval, validate the generated configuration with the selected Apache binary's `-t -f` options and inspect its effective module/configuration output. File-presence checks do not prove binary provenance, DLL compatibility, native syntax or runtime health. No generator mode deploys or restarts the server.

## Native limits and process settings

The application enforces 500 MiB (524,288,000 bytes) per PDF, 5,000,000,000 bytes per batch, and 900 MiB (943,718,400 bytes) per multipart part. The browser currently sends at most five whole files per part; the server allows twenty. These constants live in `VoucherController` and `resources/js/app.js`; see the [developer guide](DEVELOPER_GUIDE.md) for accounting and retry rules.

These native settings require review in the **effective** Apache/PHP configuration, including the web SAPI's `php.ini`, any per-directory overrides and the worker's CLI `php.ini`. This catalog does not claim that a particular live value has been applied:

| Setting | Requirement to check |
| --- | --- |
| PHP `file_uploads`, `upload_max_filesize` | Enable uploads and permit the full 500 MiB application file limit. |
| PHP `post_max_size`; Apache `LimitRequestBody` | Permit a 900 MiB part plus multipart overhead. Align every proxy/body-size control in the request path; the 5 GB batch travels in separate requests. |
| PHP `max_file_uploads`, `max_input_vars`, `max_multipart_body_parts` | Permit the supported part's files, labels, UUIDs and CSRF fields without truncation. Review metadata serialization for large review saves too. |
| PHP `upload_tmp_dir`, `sys_temp_dir`; OS temporary directories | Writable by the actual service identity, private, with space for incoming PDFs and rendering output. |
| PHP `memory_limit`, `max_execution_time`, `max_input_time`; Apache `Timeout`, `RequestReadTimeout` | Measure large uploads on the supported office connection. Avoid confusing the worker's `--memory` limit with the web PHP memory limit. |
| Worker arguments | Existing command: `queue:work database --queue=voucher-ocr --sleep=1 --tries=1 --timeout=60 --memory=256 --max-time=3600 --no-interaction`. Confirm supervision restarts the process after exit. |
| Native reader | First page only; 5-second text extraction, 20-second rendering and 20-second OCR commands; subprocess `OMP_THREAD_LIMIT=2`; rendering scale 1700 and Tesseract English/PSM 3. Values live in `PdfAmountReader`. |
| Upload session blocking | `routes/web.php` uses `block(120, 15)` on staged/review requests: lock duration 120 seconds, acquisition wait 15 seconds. |
| Saved-file lock | `<storage>/framework/voucher-files.lock`; mutation middleware waits up to 15 seconds, then preserves form input and asks the user to retry. Backup takes the same lock without waiting. |

Windows lacks some Unix process-control facilities. Verify native command timeouts and worker supervision on the target runtime rather than assuming the worker's timeout flag proves every execution path stops on schedule. Give the process identity write access to the database parent, private storage and framework cache/locks; grant only designated identities access to credentials, backups and certificate keys.

## Network, TLS and tasks

The reviewed office topology uses Apache HTTPS 8443, with legacy HTTP 8080 redirecting GET/HEAD and rejecting state-changing methods. Tailscale Serve terminates HTTPS 443 and forwards to the HTTPS backend over loopback. Apache trusts client-IP forwarding only from loopback through `mod_remoteip`. Keep the reviewed hostname/source allowlists, firewall restrictions and Tailscale authorization. A private-overlay address range alone does not replace the tailnet access policy.

The office certificate must cover the approved names/addresses and chain to the installed private root. Keep issuer and server private keys on protected server storage; distribute only the public root/client package. Verify fingerprints, validity and trust under the identity that runs uptime checks. Browser trust under one user does not prove task or machine trust. TLS scripts use TLS 1.2/1.3; validate the effective Apache configuration with the chosen native binary before restart.

The legacy task setup names Apache, amount-reader and backup tasks, with startup/recovery triggers for Apache/OCR and a daily 18:00 backup trigger. Review the live exported task definitions: their actual actions, principals and later changes take precedence over script defaults. `MultipleInstances IgnoreNew` and restart settings do not prove the task is healthy. `Run office task.ps1` rotates its uptime log at 1 MiB; plan retention for other task/worker/native logs separately. `/up` confirms HTTP health, not successful PDF delivery, OCR, backup or restore.

## Moving or upgrading the installation

Stage the candidate native runtime separately and record source/checksum, version, architecture, PHP module compatibility and required extensions. Translate the catalog into a protected machine-specific record. Preserve application keys, data and certificate recovery material; test with disposable copies and separate ports/tasks first. A staged binary is not a live cutover.

Before switching the live services, verify a complete backup and isolated restore, review effective Apache/PHP settings and Windows ACLs, check task executable paths and parser discovery under their identity, and prepare rollback. During the maintenance window, stop writers/workers, apply the approved mapping, refresh configuration and restart the intended tasks. Verify HTTPS on both entry paths, sign-in/password change, staff/admin boundaries, upload/review/save, PDF display/download, OCR/manual fallback, backup and task logs. Record the resulting web and worker versions and test evidence. Follow [setup and recovery](SETUP_AND_DEPLOYMENT.md) for the procedure; use [the security audit](SECURITY_AUDIT.md) to track which steps actually completed.
