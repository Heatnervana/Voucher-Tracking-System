# Security audit — 2026-11-12

## Executive assessment

Overall risk: **HIGH before → HIGH after**, because the native office web-server stack still needs a separately staged upgrade. Ten application/deployment-source findings were addressed; one native-runtime finding remains open. This is a source/configuration review and regression test exercise, not certification or proof against every attack.

Scope: Laravel 12.70.1, Blade/Vite, SQLite, database sessions and queue, private local PDFs, Windows Apache/PHP, native OCR, backup/setup scripts, workflows, dependency manifests/lockfiles, and nine fetched reachable Git commits. The reviewed local baseline was `77029fc` plus existing uncommitted work; fetched remote history was inspected without merging it. Production has PHP 8.2.12/Apache 2.4.58/OpenSSL 3.1.3, while the audit CLI uses PHP 8.5.11. These are different runtimes.

Staff and administrators intentionally search the same library. Existing role gates and office/Tailscale access remain in place. No device-specific administrator restriction or public deployment was added. Production accounts, environment secrets, PDFs, database contents, certificates, firewall rules, and scheduled services were not reset or migrated.

Method: inspect entry points and trust boundaries, reproduce relevant failures with disposable fixtures, make minimal fixes in an isolated worktree, rerun tests and scans, review the diff, then integrate verified source. No attack traffic was sent to another office computer. Version-based native findings are not claims of successful exploitation.

## Twenty-check scorecard

Evidence paths and line numbers refer to this audit's resulting source. PASS means the reviewed implementation and tests support the control within the stated scope. Live deployment values were inspected without reproducing credentials or internal addresses in this report.

| # | Check | Status | Severity | Evidence (file:line) | Fixed? |
|---|---|---|---|---|---|
| 1 | SQL injection | PASS | — | `app/Models/Voucher.php:41`; `app/Http/Controllers/VoucherController.php:45`; `tests/Feature/SecurityAuditTest.php` | Bound search values; static numeric sort SQL; regression |
| 2 | Prompt injection | N/A | — | `app/PdfAmountReader.php:83`; `app/VoucherAmountExtractor.php` | Local OCR, no LLM/prompts/tools |
| 3 | RLS / application authorization | PASS | — | `routes/web.php:22`; `app/Providers/AppServiceProvider.php:33` | SQLite has no RLS; shared library is intentional; management gated |
| 4 | Mass assignment | PASS | — | `app/Models/User.php:16`; `app/Http/Controllers/AccountController.php:33` | Explicit validated fields; role flags assigned server-side |
| 5 | Privilege escalation | PASS | HIGH | `database/seeders/DatabaseSeeder.php:19`; `routes/web.php:30` | SA-01 fixed; authorization regressions |
| 6 | Open redirects | PASS | — | `app/Http/Controllers/AuthController.php:23`; `app/Http/Controllers/ProfileController.php:46` | Fixed named-route destinations; no arbitrary external return URL |
| 7 | Command injection | PASS | — | `app/PdfAmountReader.php:83`; `app/render-voucher-page.ps1` | Process argument arrays, server-selected executable, bounded inputs |
| 8 | Audit logging | PARTIAL | LOW | `app/ActivityRecorder.php:33`; `app/Http/Middleware/AuditSecurityEvents.php:21` | SA-09 fixed; retention/external tamper evidence needs policy |
| 9 | Secrets / history | PASS | HIGH | `database/seeders/DatabaseSeeder.php:19`; `.gitignore`; `.env.example` | SA-01 fixed; scanner found zero in inspected source/history |
| 10 | Hashing / crypto | PASS | — | `app/Models/User.php:26`; `app/Console/Commands/BackupVouchers.php:200` | Hashed password cast, secure random credentials/tokens, constant-time integrity checks |
| 11 | Package legitimacy | PASS | — | `composer.json`; `package.json`; `docs/DEPENDENCIES.md` | All 16 direct packages verified in official registries |
| 12 | Dependency CVEs | PARTIAL | HIGH | `composer.lock:8574` (`symfony/yaml`); native version observations below | SA-02 fixed; SA-11 open |
| 13 | Third-party asset SRI | N/A | — | `resources/views/layouts/app.blade.php`; `vite.config.js` | Runtime assets are self-hosted; no external script/stylesheet to hash |
| 14 | CSP / headers | PARTIAL | LOW | `app/Http/Middleware/AddSecurityHeaders.php:21` | SA-05/06/10 fixed; same-origin PDF objects and port-specific HSTS retained |
| 15 | Clickjacking | PASS | — | `app/Http/Middleware/AddSecurityHeaders.php:38` | Same-origin frame ancestors plus SAMEORIGIN |
| 16 | Exposed storage | PASS | MEDIUM | `config/filesystems.php:33`; `app/Http/Controllers/VoucherController.php:550`; `Set up office access.ps1:9` | SA-07 fixed in setup source; private storage and authenticated delivery |
| 17 | Debug exposure | PASS | — | `.env.example:4`; `bootstrap/app.php:22`; effective live `APP_DEBUG=false` | Generic deployed errors; no diagnostic route added |
| 18 | Sessions / expiry | PASS | MEDIUM | `app/Http/Controllers/ProfileController.php:24`; `routes/web.php:22`; `config/session.php` | SA-03/04 fixed; database session revocation and expiry |
| 19 | Unsafe deserialization | PASS | — | `config/session.php:236`; `app/PdfAmountReader.php:99` | JSON sessions, validated JSON, XML NONET without entity substitution |
| 20 | Error leakage | PASS | INFO | `bootstrap/app.php:22`; `app/Http/Middleware/AddSecurityHeaders.php:23`; `tests/Feature/SecurityAuditTest.php:30` | Generic errors plus server-generated correlation ID; banner disclosure tracked in SA-11 |

Summary: **15 PASS, 3 PARTIAL, 2 N/A, 0 FAIL**. PARTIAL controls have concrete remaining operational decisions; they are not silently marked complete.

## Findings and applied fixes

Counts: CRITICAL 0; HIGH 3 found / 2 fixed / 1 open; MEDIUM 3 / 3 / 0; LOW 4 / 4 / 0; INFO 1 / 1 / 0. Severity describes the finding, not every deployment's demonstrated exposure.

### SA-01 — HIGH: fixed bootstrap administrator credential and unsafe reseeding

Location: `database/seeders/DatabaseSeeder.php:19`. The original seeder used a known fixed password for a new administrator and `firstOrCreate(...)->forceFill(['is_admin' => true])`, so rerunning it could promote an existing non-administrator with the bootstrap username. It did not overwrite that existing account's password.

Masked original fragment:

```php
User::firstOrCreate(['username' => 'admin'], [
    'name' => 'Administrator',
    'password' => '[REDACTED fixed bootstrap credential]',
])->forceFill(['is_admin' => true])->save();
```

Now bootstrap only runs when no users exist, including soft-deleted users. It generates an 18-character random temporary password, displays it once to the operator, and requires a first-login change. Existing accounts are untouched. Do not capture that console output in a public CI transcript.

Verification: `DatabaseSeederSecurityTest` covers randomness, hashing, forced change, existing staff/admin/soft-deleted accounts, and no credential output on skip. A read-only check of the live default administrator found that its password **does not match** the old default. No live seeder/password reset was run. The historical fixed string remains in old commits; do not reuse it elsewhere. Automated secret scanning did not detect this low-entropy application-specific credential; manual review did.

### SA-02 — HIGH upstream: vulnerable development YAML dependency

Location: `composer.lock:8574`, `symfony/yaml`. Before: `v7.4.21`; after: `v7.4.23`. Composer's advisory identifies **CVE-2026-123792 / GHSA-hwfg-qwvh-2wfx**, severity HIGH (8.7), pathological YAML parsing denial of service. The dependency is reached through the development tooling chain (`laravel/boost` / `laravel/roster`); no unauthenticated user-controlled YAML route was found.

Applied a compatible patch-only lockfile update. No other package update was bundled. See [dependency evidence](DEPENDENCIES.md). Verify with `composer audit --locked --format=json` and `composer show symfony/yaml`. Locked PHP advisories went from **1 to 0**; npm advisories were **0 before and after**.

### SA-03 — MEDIUM: self-service password change did not revoke other sessions

Locations: `app/Http/Controllers/ProfileController.php:24`, `routes/web.php:22`. Before: save the new password and regenerate the caller's session; another signed-in browser stayed active.

```php
DB::transaction(fn () => $user->save());
$request->session()->regenerate();
```

Now rotate the remember token, revoke other database sessions using the configured connection/table, destroy the caller's previous session identifier, and apply Laravel's `auth.session` middleware. The active caller continues. Tests cover real separate file sessions, legacy database rows, custom tables, unrelated users, validation, and rollback. See `PasswordSessionSecurityTest`.

Limit: different database connections cannot share one atomic transaction. Legacy file sessions that never stored the old password hash need separate expiry/revocation planning; production currently uses database sessions.

### SA-04 — LOW: temporary password could satisfy forced change without changing

Location: `app/Http/Controllers/ProfileController.php:27`. Before: `'password' => ['required', 'confirmed', Password::min(12)->mixedCase()]`. Now require `different:current_password`; a reused temporary password cannot clear the forced-change flag. Regression: `AuthenticationTest` and `PasswordSessionSecurityTest`.

### SA-05 — MEDIUM: sensitive responses could persist in browser cache

Location: `app/Http/Middleware/AddSecurityHeaders.php:29`. Before observed `Cache-Control: no-cache, private`, which permits storage subject to revalidation. Now app responses specify `private, no-store`, including PDF routes. This does not revoke a PDF the user explicitly downloads or prevent screenshots. Header and media regression tests verify the policy.

### SA-06 — LOW: incomplete CSP and inline confirmation handlers

Locations: `app/Http/Middleware/AddSecurityHeaders.php:31`, `resources/js/app.js:4`, five affected voucher/account Blade templates. Before the CSP only constrained base/frame ancestors, and forms used inline `onsubmit="return confirm(...)"`.

Production now restricts scripts/connections/assets to the same origin and constrains base, form, frame, and object sources. Confirmation prompts use escaped `data-confirm` values and an external submit listener; accepted and cancelled actions keep their existing behavior. Only a local environment with an actual Vite hot file uses the development exception. Tests ensure a production hot file cannot weaken CSP.

`object-src 'self'` is intentional for the existing embedded PDF viewer. It remains a documented departure from the stricter requested `object-src 'none'`, pending compatibility testing. Port-443 HSTS is retained; enabling it on the same hostname's nonstandard port would break the existing HTTP-port shortcut. See decisions below.

### SA-07 — MEDIUM: backup setup could preserve explicit broad ACL grants

Location: `Set up office access.ps1:9`. Before, `Get-Acl` followed by `SetAccessRuleProtection($true, $false)` and `AddAccessRule(...)` removed inherited permissions but retained existing explicit grants. Backups can include `.env`, so any such broad grants could expose recovery secrets.

Now construct a fresh allowlisted ACL for the service identity, SYSTEM, and Administrators. Protect the setup archive before copying secrets. Reject junction/symlink ancestors and non-directory destination ancestors before provisioning. Existing contents are scanned without following reparse points; unsafe child grants/owners cause setup to stop and request review or a new empty protected destination. Protected children with safe ACLs remain usable. Tests include explicit broad file grants, protected broad directories, nested grants, safe repeat setup and reparse boundaries. No recursive ACL rewrite is performed. **The script was not rerun against live Windows/network settings.** Read-only inspection found zero broad grants on the two checked live backup root directories; historical child ACLs were not exhaustively traversed. Source hardening is preventive, not evidence of an existing leak.

### SA-08 — LOW: legacy upload aggregate limit and malformed nested input

Location: `app/Http/Controllers/VoucherController.php:152,292`. Before, the legacy upload path cleared prior staged files before checking any aggregate 5 GB limit; nested multipart file arrays could reach file-size accounting as arrays and throw a server error.

Now validate each `files.*` as a file on both paths, and reject an oversized legacy batch before clearing the prior review. Per-PDF 500 MB and folder 5 GB limits remain. These are administrator-only paths; upstream body limits constrain whether a >5 GB single request can reach the legacy handler. No live denial-of-service attack was attempted. `UploadSecurityTest` covers rejection, preservation, and acceptance below the limit using fake files.

### SA-09 — LOW: PDF deliveries and authorization denials lacked activity entries

Locations: `app/Http/Middleware/AuditSecurityEvents.php:21`, `app/ActivityRecorder.php:71`, `app/Models/ActivityLog.php`. Existing activity covered login/account/record mutations but PDF route delivery and 403 denials had no equivalent event.

Now log authenticated successful GET200/206 saved/download/recycled PDF delivery, including Range GETs, and authenticated 403 responses. Supplying a Range header cannot suppress the audit. HEAD, missing files and guests do not become successful delivery events. Entries include actor, target/action, time and source IP; they exclude document bytes, request bodies, cookies, and credentials. These are delivery events, not unique human views. Database write failure can fail a delivery rather than silently lose its audit; monitor database health. Retention and external tamper evidence still need policy.

### SA-10 — INFO: generic errors lacked a client-safe correlation identifier

Location: `app/Http/Middleware/AddSecurityHeaders.php:23`. Before: no request identifier for relating a generic browser error to a private server log. Now generate a UUID on the server, attach private log context, and return `X-Request-ID`. Client-supplied IDs are ignored. Tests verify generic unexpected errors omit exception details while returning a valid identifier. Correlation does not imply every proxy/native-server error passes through Laravel.

### SA-11 — HIGH, open: old native Apache/PHP/OpenSSL stack

Evidence: read-only `httpd -v`, `php -v`, native tool checks, effective Apache/PHP configuration and live response banners during this audit. Native binaries are outside the repository. Observed Apache **2.4.58**, PHP **8.2.12**, OpenSSL **3.1.3**. The CLI PHP **8.5.11** used for most tests does not update these services.

For example, upstream Apache lists Windows **CVE-2024-38472** (UNC SSRF / possible NTLM credential disclosure) for versions 2.4.0–2.4.59, fixed in 2.4.60. This establishes an affected version range, not that this app's routes/configuration were exploited. Choose a **currently maintained** patched stack rather than targeting only that historic minimum. Native PDF/OCR parsers also need an independent update inventory.

Source: [Apache 2.4 security advisories](https://httpd.apache.org/security/vulnerabilities_24.html), [PHP supported versions](https://www.php.net/supported-versions.php), [OpenSSL release policy](https://openssl-library.org/policies/releasestrat/). Binary replacement requires compatibility, service/task path, extension, TLS, backup/rollback and office-client testing, so it was not bundled into a source patch. This is why the overall rating remains HIGH.

## Additional checks

- **XSS:** Blade escaping and text-based browser updates reviewed; hostile saved payee text is regression-tested. No runtime CDN dependency, unescaped Blade output sink, or arbitrary HTML rendering path was found in the reviewed templates.
- **CSRF:** Laravel web middleware and form tokens protect mutations. Role authorization is on the server, not just hidden buttons. Browser controls remain convenience safeguards.
- **Brute force / enumeration:** generic credential failures, route throttling and account/IP limiting are present. No public registration/reset API was found. Password policy requires at least 12 characters and mixed case.
- **SSRF / traversal:** no user-controlled outbound URL fetch was found. Native Windows server SSRF exposure remains SA-11. Filenames, private UUID staging membership and path containment are checked before file operations.
- **Uploads:** content, extension, size/count, session batch ownership and review reconciliation are validated; saved naming is constrained and private. Valid PDFs still contain untrusted native-parser input, so MIME checks do not substitute for parser updates.
- **CORS:** no wildcard credentialed API exposure was found. The application is a same-origin Blade app; adding an API later needs a fresh review.
- **CI:** workflows use locked installs, least-privilege repository access, pinned action revisions, disposable browser fixtures and a redacted history scan. Local-only endpoint uptime cannot be measured by a public hosted runner without an approved private runner/access path.

## Needs human decision — proposed changes, not applied

1. **Upgrade native server/runtime (HIGH, first priority).** Stage maintained Apache/PHP/OpenSSL builds alongside the current stack, copy only reviewed configuration/extensions, run syntax checks plus both-runtime/browser/OCR tests on a spare loopback port, back up task/config/certificate material, then approve a maintenance cutover. Exact binary/config-path diff depends on the selected distribution; no invented version or unsafe automatic replacement is proposed. Optional banner reduction after staging:

   ```diff
   # Apache main configuration
   +ServerTokens Prod
   +ServerSignature Off
   # The actual Apache PHP ini, not merely the CLI ini
   -expose_php = On
   +expose_php = Off
   ```

   Banner reduction is disclosure hardening, not a fix for the underlying CVEs.

2. **Audit retention and tamper evidence.** Decide the office retention period, allowed PII fields and off-host collector/storage ownership. Current activity contains voucher filenames/payees and account display names as operational identifiers; it never needs passwords or PDF content. Restrict reader/writer access and archive under a policy before any purge job is considered. Proposed minimum log-level change only after operational review:

   ```diff
   # Live private environment; example already has these safer defaults
   -LOG_STACK=single
   -LOG_LEVEL=debug
   +LOG_STACK=daily
   +LOG_LEVEL=info
   +LOG_DAILY_DAYS=14
   ```

   Fourteen days is an example application-file retention, not a legal/office records recommendation. SQLite activity records need a separate approved retention policy. No live logs were deleted.

3. **Tighten PDF object policy if compatibility permits.** First test desktop/mobile embedded PDFs, all pages, preview/download, and local/Tailscale routes. Proposed isolated diff in `AddSecurityHeaders`:

   ```diff
   -object-src 'self'
   +object-src 'none'
   ```

   Retain `frame-src 'self'` for the existing iframe. Do not enable same-host HSTS for the 8443/8080 combination without redesigning that shortcut. Existing trust warnings on the private office certificate are solved by trust provisioning or using the approved Tailscale hostname, not by CSP changes.

4. **Legacy session migration, if changing drivers.** Production database sessions are revoked by the fix. Before moving to a file/other store, expire all legacy sessions in that store during a scheduled sign-in reset; retain the existing database driver otherwise. Do not clear arbitrary session directories or rotate the app key to improvise a logout. Separate session DB connections require failure monitoring or a deliberately designed revocation mechanism.

5. **Recovery, credentials and permissions.** Rehearse a complete restore on disposable storage and review historical backup child ACLs under authorized Windows access. Rotate a credential only if it was reused from the historic bootstrap default or evidence shows disclosure. No live key/token leak was found in this scope. Do not rotate `APP_KEY` without the encrypted-data/session transition in [the security policy](../SECURITY.md).

## Roadmap

**Do now:** use the verified application patch; keep production debug off, private access and backups; plan the native-runtime maintenance window. Preserve the current working certificates and role behavior.

**This week:** stage the native upgrade, verify both PHP runtimes and worker paths, review historical backup ACLs, test another office/Tailscale client, and rehearse recovery.

**This month:** approve retention/off-host audit handling, review parser/OS patch cadence and restore evidence, and consider the stricter PDF object policy only after compatibility checks.

## Verification and scope limits

The pre-fix full PHP baseline was **280 tests / 1,434 assertions** with the OCR fixture enabled. Relevant regressions were observed failing before their implementation and passing afterward. Post-fix final command results are recorded in the release verification section below; test data and browser profiles are disposable and do not use production credentials or vouchers.

Secret scanning: checksum-verified portable Gitleaks **8.30.1**, redaction enabled, no application dependency added. Scanned all fetched reachable history (`--log-opts=--all`, nine commits) and a source-only working-tree export excluding ignored secrets/runtime/data/dependencies. Before: **0 scanner findings**, plus one manually discovered fixed bootstrap credential. After: rerun results below. No clean scan establishes absence of unknown secrets, unreachable remote commits, or leaks outside Git.

Example reproducible commands (use a disposable checkout and reviewed installed binaries):

```powershell
$env:VOUCHER_OCR_SAMPLE=(Resolve-Path tests/Fixtures/amount-5250-scan.pdf).Path
php vendor/bin/phpunit --colors=never
php vendor/bin/pint --dirty --format agent
node --test tests/voucher-forms.test.mjs tests/voucher-amounts.test.mjs
npm run build
composer audit --locked --format=json
npm audit --json
gitleaks git --redact=100 --log-opts=--all .
gitleaks dir --redact=100 PATH_TO_SOURCE_ONLY_EXPORT
$env:BROWSER_TEST_PHP='C:\path\to\php.exe'
node --test tests/browser-flows.mjs
powershell -NoProfile -File tests/office-https.test.ps1
powershell -NoProfile -File tests/tailscale-https.test.ps1
powershell -NoProfile -File tests/office-backup-security.test.ps1
```

Security tags are dated **2026-11-12**, the actual review date rather than the stale date in the audit template. Every `SECURITY-FIX:` marker maps to the audit change section in `CHANGELOG.md`. Comments also document trust boundaries in authorization, OCR, paths, native process execution, account changes and backups. Preexisting unrelated working-tree changes remain separate from the described audit changes.

### Release verification

| Command / check | Completed result |
|---|---|
| `php vendor/bin/phpunit --colors=never`, OCR fixture enabled, isolated PHP 8.5.11 | **314 tests, 1,680 assertions passed**, no skips |
| Same full command with XAMPP PHP 8.2.12 after source/dependency integration | **314 tests, 1,680 assertions passed**, no skips |
| `php vendor/bin/pint --dirty --format agent` | Passed in isolation and active checkout; active checkout needed elevated read access during discovery of the protected backup directory |
| `node --test tests/voucher-forms.test.mjs tests/voucher-amounts.test.mjs` | **21 passed**, no skips |
| `npm run build` | Passed in both checkouts |
| `node --test tests/browser-flows.mjs` with installed Edge and disposable data | Default mode passed: 9 subtests plus parent, 9 unselected subtests skipped |
| Same Edge harness with `BROWSER_TEST_SECURITY_ONLY`, `BROWSER_TEST_ACCOUNTS_ONLY`, `BROWSER_TEST_RECORD_EDITING_ONLY`, `BROWSER_TEST_USABILITY_ONLY` in separate runs | All four focused runs passed: 2 subtests plus parent each, 16 unselected subtests skipped per run |
| Three Windows fixture scripts listed above | All passed in active checkout; Apache temporary configuration **Syntax OK**; no live setup/restart/trust import |
| Redacted Gitleaks source-only export after fixes | **293 source files, 0 findings** |
| Redacted Gitleaks history rerun | **9 fetched reachable commits, 0 findings** |
| Live office and private Tailscale HTTPS sign-in | Both **200**, valid TLS on this server, no-store/CSP/request ID present; HSTS present on the standard-port Tailscale endpoint |
| Live `.env` and `.git/config` probes | Both **403**; guest voucher/library status routes redirect to sign-in; private HTTPS health endpoint **200** |
| Integration guards | SHA-256 comparison of all 51 copied source/test/doc files matched the isolated source; private PDF count remained **1,299** |

Browser coverage includes forced password change, large review (300 fixture PDFs), stale review protection, concurrent employee uploads, real OCR, save-without-amount, saved PDF download/deletion, recycled restore/purge/activity, account roles/resets/deletion, rename, responsive layout and keyboard interactions. These are automated Edge desktop/emulation checks; no second office PC or physical phone was tested during this audit. A real 5 GB transfer was not repeated; aggregate boundaries use fake-file tests and smaller real transfers.

Known nonfatal tool warnings: the font plugin has no optional `fontaine` fallback package, and Vite leaves the existing absolute login-image URL to runtime resolution. Both builds succeeded; no dependency was added just to silence these warnings. Composer's existing installed vendor tree also reports duplicate Flysystem/Pint-provider classes; optimized autoloading selects the application/provider and expected local adapter, and both complete PHP suites passed. Reconcile that installed-tree ambiguity through a clean staged lockfile install during maintenance rather than deleting vendor files on the live server.

Composer's final isolated and active-checkout audits both exited successfully with zero advisories, but warned that base package metadata fell back to cache after a Packagist timeout. Repeat when registry access is reliable. This qualification is also recorded in `docs/DEPENDENCIES.md`; counts refer to scanner-reported known advisories, not exhaustive native binary analysis. npm's final active-checkout audit returned zero vulnerabilities. The installed YAML package is confirmed at 7.4.23.

All **15** dated `SECURITY-FIX:` source markers were matched to the SA entries in `CHANGELOG.md`, including the local CSP exception and historical-child backup ACL checks. Independent source review found the descendant ACL issue; its additional failing regression and safe rejection fix passed before integration. No source commit, push, network change, certificate replacement or native service upgrade was performed.

## Files in the audit patch

The following paths were added or changed by this audit. Earlier unrelated workspace changes are not listed.

- `SECURITY.md`
- `app/Http/Middleware/AddSecurityHeaders.php`
- `app/Http/Middleware/AuditSecurityEvents.php`
- `docs/ARCHITECTURE.md`
- `docs/DEPENDENCIES.md`
- `docs/SECURITY_AUDIT.md`
- `docs/SETUP_AND_DEPLOYMENT.md`
- `tests/Feature/DatabaseSeederSecurityTest.php`
- `tests/Feature/PasswordSessionSecurityTest.php`
- `tests/Feature/SecurityAuditTest.php`
- `tests/Feature/SecurityHeadersTest.php`
- `tests/Feature/UploadSecurityTest.php`
- `tests/office-backup-security.test.ps1`
- `.env.example`
- `CHANGELOG.md`
- `README.md`
- `Set up office access.ps1`
- `app/ActivityRecorder.php`
- `app/Console/Commands/BackupVouchers.php`
- `app/Console/Commands/CreateStaffAccount.php`
- `app/Http/Controllers/AccountController.php`
- `app/Http/Controllers/AuthController.php`
- `app/Http/Controllers/ProfileController.php`
- `app/Http/Controllers/RecycleBinController.php`
- `app/Http/Controllers/VoucherController.php`
- `app/Http/Middleware/EnsurePasswordChanged.php`
- `app/Http/Middleware/LockVoucherFiles.php`
- `app/Jobs/ReadSavedVoucherAmount.php`
- `app/Jobs/ReadVoucherAmount.php`
- `app/Models/ActivityLog.php`
- `app/Models/User.php`
- `app/Observers/UserActivityObserver.php`
- `app/PdfAmountReader.php`
- `app/Providers/AppServiceProvider.php`
- `app/VoucherAmountExtractor.php`
- `app/VoucherFilename.php`
- `app/VoucherRecycleBin.php`
- `app/render-voucher-page.ps1`
- `bootstrap/app.php`
- `composer.lock`
- `database/seeders/DatabaseSeeder.php`
- `resources/js/app.js`
- `resources/views/accounts/edit.blade.php`
- `resources/views/vouchers/confirm.blade.php`
- `resources/views/vouchers/index.blade.php`
- `resources/views/vouchers/recycle-bin.blade.php`
- `resources/views/vouchers/show.blade.php`
- `routes/web.php`
- `tests/Feature/AuthenticationTest.php`
- `tests/office-https.test.ps1`
- `tests/voucher-forms.test.mjs`
