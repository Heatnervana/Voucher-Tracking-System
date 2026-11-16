# Recovery rehearsal and historical OCR reconciliation

Date: 2026-11-12. Scope: the approved office application working tree based on `77029fc`, with the current reviewed changes. Operators must keep backup locations, document names, account identities, credentials, environment contents, and raw job payloads in restricted operational records.

## Historical OCR failures

A read-only SQLite connection inspected the existing database and failed-job payloads. It opened the database with `PDO::SQLITE_ATTR_OPEN_FLAGS => 1` and enabled `PRAGMA query_only = ON`. It did not use cache APIs that could remove expired entries.

| Observation | Result |
| --- | --- |
| Saved vouchers / existing accounts | 1,014 / 2 |
| Pending queued jobs | 0 |
| Historical failed jobs | 35 |
| Failure period, UTC | 2026-10-28 through 2026-11-05 |
| Upload OCR jobs with SQLite lock errors | 26 |
| Saved-voucher OCR jobs with SQLite lock errors | 1 |
| Upload OCR jobs rejected for exhausted attempts | 8 |
| Failed upload jobs whose staging PDF and cache entry are absent | 33 |
| Failed upload jobs with a staging PDF and an expired cache entry | 1 |
| Failed saved-voucher job whose voucher already has a ready amount | 1 |

All 27 lock traces fail while Laravel updates the `jobs` table to reserve work. The eight `MaxAttemptsExceededException` traces originate in the worker's attempt check before `handle()`. These traces establish queue coordination failures; they do not establish a PDF parser defect. The historical records do not identify the competing writer or explain why each reservation exhausted its attempts.

The current database uses the rollback journal (`DELETE`), and the inspected PDO connection reports a 60,000 ms busy timeout. Queue, cache, and sessions share SQLite. Laravel's installed queue implementation selects and reserves work in a transaction, while the application configures deferred transactions. Concurrent writes remain a risk: SQLite can return `SQLITE_BUSY` during a read-to-write upgrade and may bypass its busy handler to avoid deadlock. See SQLite's [locking documentation](https://www.sqlite.org/lockingv3.html) and [busy-handler documentation](https://www.sqlite.org/c3ref/busy_handler.html). This explains a possible mechanism, not the identity of the historical blocking process.

The failed work has no outstanding business action: the upload state has expired or disappeared, and the saved voucher already has an amount. Retain the 35 records as evidence. Do not bulk retry, forget, prune, or delete them. Reprocess an amount only through the application's current workflow if an administrator identifies a present need. Do not replay historical staging identifiers.

No OCR source change or live queue/database cleanup formed part of this reconciliation. The existing generation checks, cancellation checks, and manual fallback passed the focused tests below. A journal-mode change, dedicated queue database, or worker-concurrency change needs a separate workload test and maintenance review.

## Fresh backup and disposable restore

The rehearsal uses the existing `vouchers:backup` command and an existing access-restricted backup destination. On Windows, directory mode `0700` alone does not establish privacy. The operator verified the destination ACL before running the command and created a separate recovery area with inheritance disabled and three allowlisted identities: the authorized operator, SYSTEM, and Administrators.

A read-only descendant audit inspected **7,283 daily-backup entries**, finding **zero unexpected allow rules and zero reparse points**. The root disables permission inheritance. Eleven historical entries (seven directories and four files) have the known sandbox account as owner; their access grants still allow only the operator, SYSTEM, and Administrators. An owner can change a Windows DACL, so an administrator should review that ownership when retiring the sandbox account. This rehearsal did not change historical owners or permissions.

The command acquires the voucher-file lock, creates a SQLite `VACUUM INTO` snapshot, checks integrity and foreign keys, copies saved and recycled voucher PDFs, verifies SHA-256 values, includes the recovery environment, and publishes a completion manifest. Reject `.incomplete-*` directories as restore sources.

Example commands use placeholders rather than office paths:

```powershell
php artisan vouchers:backup '<protected-backup-root>' --no-interaction

$env:VOUCHER_OCR_SAMPLE=(Resolve-Path tests/Fixtures/amount-5250-scan.pdf).Path
php artisan test --compact tests/Feature/BackupVouchersTest.php tests/Feature/VoucherAmountQueueTest.php tests/Feature/SavedVoucherAmountTest.php tests/Feature/PdfAmountReaderTest.php
```

The focused command passed **49 tests / 216 assertions** under PHP 8.5.11. It covers database/environment/PDF backup hashes, recovery from a snapshot, incomplete-backup rejection, queue failures and manual fallback, stale OCR generations, and parser fallback. Test databases and PDF fixtures are disposable.

The fresh backup completed at **07:13:34 UTC**. The operator copied it to the separate protected recovery area and checked each source file and restored file against the manifest. Results:

| Full-data recovery check | Result |
| --- | --- |
| Manifest entries verified and restored | 1,016 / 1,016 |
| Saved PDF files verified and restored | 1,014 / 1,014 |
| Restored file bytes, including database and environment | 20,917,828,514 |
| Source completion manifest unchanged | Yes |
| SQLite `integrity_check` | `ok` |
| SQLite foreign-key violations | 0 |
| Existing accounts preserved before application checks | 2 |
| Saved vouchers / recycled vouchers | 1,014 / 0 |
| Blank voucher amounts | 0 |
| Queued jobs / retained historical failures | 0 / 35 |
| Unexpected allow rules on checked restored private files | 0 |

The restored environment file retains its original bytes and SHA-256. A separate application environment preserves the recovery key while pointing at the disposable database and isolating cache, sessions, logs, and outbound mail. The original archive remains unchanged. Restricted operational evidence retains the exact archive identifier, manifest hash, and paths; this document omits those office details.

The isolated application smoke passed **68 assertions** under the existing approved PHP 8.5.11 runtime. Before bootstrap, the harness set copy-specific configuration/route cache paths and `LARAVEL_STORAGE_PATH`. Before its first request, it checked the effective base directory, copied vendor and application-class locations, cache directories, storage directory, database path, and private PDF root. It used the copied application's dependencies and Laravel HTTP testing helpers; it did not run the root test suite or read the production configuration cache.

The checks covered:

- Health, sign-in, guest redirection, and administrator library/account pages.
- Staff library and PDF access, with forbidden management/download actions.
- Administrator PDF display/download and SHA-256 checks of the response bytes against the backup manifest.
- Manual amount entry, soft deletion with PDF retention, recycled PDF preview, and restoration.
- Both original account password hashes matching the read-only backup snapshot after the checks.
- Final counts of 1,014 active vouchers, zero recycled vouchers, zero queued jobs, and all 35 historical failed jobs retained.

The operator paused recovery I/O during the live login investigation, then completed only the light isolated smoke after receiving clearance. The retained rehearsal copy contains **two disposable accounts and workflow test edits**. Keep it marked for rehearsal; recover production from the immutable complete backup rather than promoting this modified copy.

These HTTP-kernel checks ran in the testing environment. They do not establish browser rendering, production CSRF behavior, office-client TLS trust, or a successful live cutover. The full archive/database/PDF verification and application smoke are complete within those limits.

## Boundaries

The rehearsal must preserve the live database, existing accounts and password hashes, environment/key, PDFs, workers, services, scheduled tasks, certificates, and firewall configuration. Only the authorized fresh backup may create production recovery artifacts. Rehearsal sign-in accounts and workflow changes belong only to the restored copy.

This application backup excludes unsaved staging uploads, the operating system, Apache configuration, Windows task definitions, certificate private keys and trust stores, and native OCR executables. Their recovery requires the office's separate protected configuration backups and maintenance procedure. A disposable application rehearsal does not establish office-client TLS trust, off-host disaster recovery, or production cutover readiness.
