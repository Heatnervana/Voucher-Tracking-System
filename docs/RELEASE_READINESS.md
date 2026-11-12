# Office readiness candidate — 2026-11-12

This record covers the local readiness work and its limits. It is a release-candidate record, not approval for a native-runtime cutover or public deployment. Keep the existing office/Tailscale topology and administrator/staff permissions.

## Five-item status

| Item | Result |
|---|---|
| Native server upgrade | Candidate archives verified and staging configuration prepared; **execution/security approval and live cutover pending**. |
| Historical OCR failures | **Reviewed and reconciled.** Retain all 35 records; no obsolete job was replayed or deleted. |
| Backup, recovery and startup | **Fresh full backup and isolated restore verified**, including application smoke. Existing startup tasks inspected; a real reboot/cutover verification remains pending. |
| Real devices and full-size upload | User reported recovered responsiveness after the login fixes. Full phone/PDF acceptance and second-PC testing remain separate checks; the user deferred the PC test to **2026-11-13**. An elevated full-size test passed both oversize rejection cases and transferred about 3.42 GB before the operator stopped it after the live response monitor timed out. **No completed 5 GB transfer is claimed.** |
| Developer handoff and Git baseline | **Complete as a local candidate:** developer/configuration guides, reviewed tests and tag `v0.1.0-rc.1` on `codex/office-readiness-20261112`. Nothing was pushed or published. |

## Native runtime preparation

The live installation remains Apache 2.4.58 / PHP 8.2.12 / OpenSSL 3.1.3. No replacement service, certificate, firewall rule, Windows runtime prerequisite or task action was applied.

Downloaded candidates:

| Artifact | Version and provenance | SHA-256 |
|---|---|---|
| Apache Windows x64 VS18 | Apache Lounge `httpd-2.4.70-261106-Win64-VS18.zip`; server reports 2.4.70, OpenSSL 3.5.9 | `b96a40b66a99428b2e4aea48ebe98bab826c768df4ace81f78dd7e26a98ad311` |
| PHP Windows x64 thread-safe VS17 | Official `php-8.5.12-1-Win32-vs17-x64.zip`; CLI reports PHP 8.5.12, OpenSSL 3.5.9 | `0dacd6484b092b5e9ae9fa3d7f96a937caf9d874eee2d980b68ed3129741144a` |

The PHP executable's SHA-256 inside the verified archive is `0ac893499799256db6d3a54b0da73692e02311493922e6e4f86a062674a77d66`. Endpoint protection terminated its PHPUnit process with a `RANSOM-PID` event and removed the staged executable. The interrupted run is **not a passing test result**. Leave it blocked pending the operator's normal security review; do not rename/re-extract an executable to evade the control, disable protection or create a broad exclusion.

Upstream Apache released 2.4.71 on 2026-11-11; the checked Windows distributor still lists 2.4.70. The configuration generator excludes `mod_http2`, `mod_md` and `mod_vhost_alias`, the modules associated with the three 2.4.71 advisories. This is a conditional mitigation, not a claim that 2.4.70 contains those patches. Recheck vendor availability and prerequisites before any cutover. Sources: [Apache downloads](https://httpd.apache.org/download.cgi), [security advisories](https://httpd.apache.org/security/vulnerabilities_24.html), [Apache Lounge Windows builds](https://www.apachelounge.com/download/), [official PHP Windows downloads](https://www.php.net/downloads.php?os=windows).

`Set up office runtime.ps1` supports Preview and Stage only. It preserves a separately reviewed virtual-host include, renders explicit binary/INI/log paths, validates input and refuses to overwrite an existing configuration. It does not start/stop services, register tasks, change trust or enable internet access. See [office configuration](OFFICE_CONFIGURATION.md).

## Recovery and historical work

The fresh backup and restore verified **1,016 SHA-256 entries, all 1,014 saved PDFs, and 20,917,828,514 restored bytes**. SQLite integrity returned `ok`, foreign-key violations were zero, and the two original account password hashes matched the archive. The isolated application passed **68 HTTP-kernel assertions** covering authentication, roles, PDFs, amount editing, recycle/restore and byte preservation. The tagged rehearsal copy contains additional disposable accounts; recover from the immutable archive rather than promoting that edited copy.

Historical OCR review found 27 queue-reservation database-lock failures and eight exhausted-attempt failures. Their upload state expired/disappeared or the saved voucher already has an amount. The live queue had no pending work at inspection. Retain the 35 records as evidence. Review SQLite contention under workload before any queue/storage redesign. Full procedures and ACL results: [recovery rehearsal](RECOVERY_REHEARSAL.md).

## Live sign-in incident and changes

During device testing, production requests intermittently logged `MissingAppKeyException` despite the unchanged, populated environment file. Slow overlapping requests also produced a session lock timeout. The operator cached the verified existing production configuration with the approved PHP 8.2 runtime. Checks confirmed that its key matches the environment, the database still targets the office file, sessions remain database-backed and cookie settings remain HTTPS/HttpOnly/SameSite Lax. The cached file contains secrets, stays ignored/private and must not enter Git or a shared ticket.

The 405 reports matched GET requests to `/logout`, including stale sign-out navigation. A GET fallback now directs guests to sign-in and authenticated users to the library without ending a session. Actual sign-out remains a CSRF-protected POST. Three regressions failed with 405 before the change and passed afterward.

The user later reported that responsiveness had recovered. A fresh-cookie Tailscale diagnostic returned GET sign-in **200 in 0.92 s** and an invalid-account POST **302 in 1.53 s**, with no 419. Later endpoint checks returned office sign-in **200 in 2.32 s** and Tailscale sign-in **200 in 0.88 s**. These are server-side samples, not a guarantee for every client. Preserve the distinction between user-reported performance recovery and explicit staff/PDF/page-two acceptance. The physical device certificate warning while using an IP is separate from application errors; use the approved trusted hostname and access path.

The dotenv global-environment adapter can reproduce a synthetic cross-request value-loss sequence, and upstream warns that `getenv`/`putenv` are not thread-safe. This remains supporting evidence, not proof of the exact live interleaving. The applied production configuration cache avoids that uncached configuration-loading path. No live key rotation occurred.

## Test and release safety

PHPUnit now forces its own configuration-cache path. The base application factory rejects production/cached configuration, a non-SQLite default, file-backed SQLite or a database URL before `RefreshDatabase` can mutate a database. Child-process regression probes use disposable sentinel files and cannot reach a real database. The Composer test script no longer clears the office configuration cache before launching the tests.

The pre-readiness full baseline passed 314 PHP tests / 1,680 assertions. The final readiness suite passed **324 tests / 1,718 assertions** under the existing PHP 8.2.12 runtime. Targeted login/session/safety checks passed **18 tests / 129 assertions**; safety/backup/replacement checks passed **22 tests / 93 assertions**. Pint passed for dirty and newly added PHP files, with private backup/recovery trees excluded from discovery.

The native configuration review caught an inherited-options error: the main default `Options None` prevented Laravel's rewrite rules under the existing virtual hosts. An isolated static Apache regression reproduced **HTTP403/AH00670**, then passed **HTTP200** after enabling `FollowSymLinks` while retaining default access denial. The test loaded no PHP and did not touch live services.

Two sandbox full-size attempts generated the synthetic input but failed before Apache startup. A later approved isolated test reached native Apache **Syntax OK**, rejected a **5,000,000,001-byte folder** and a **524,288,001-byte PDF**, and began transferring ten 500,000,000-byte PDFs, including five nested entries. After about **3.42 GB / 68%**, the live sign-in monitor timed out at 12 seconds. The operator terminated only the identified test process tree, checked its unique fixture boundary/reparse points and removed its disposable data. Live sign-in then returned **200 in 1.38 seconds**. Finalization and complete byte-hash acceptance were not reached. This identifies an operational load constraint, not a completed capacity test or a proven defect in one particular component. Repeat outside office use or on a separate test host with resource monitoring.

Neither native approval nor a real restart is implied by fixture success. Do not run a multi-gigabyte stress check on the working server during office use merely to obtain a passing checkbox.

## Local source checkpoint

The candidate includes the earlier approved security, UI, CI/readiness and cleanup work, plus the developer handoff and incident fixes. Upstream `origin/main` was fetched and reconciled; the merge's source tree was identical to the tested candidate. Three overlapping PHP conflicts were resolved with the tested versions. This reconciliation should have run in an isolated checkout; no further live-tree merge is part of the operating procedure. Final live sign-in returned **200 in 1.26 seconds** after resolution.

The final source-only export contained **302 nonignored files** and the redacted secret scan reported **zero findings**. Private environment/cache files, databases, PDFs, backup/recovery copies, native binaries and runtime logs remain outside Git. JavaScript checks passed **21 tests** and Vite built successfully; the existing optional-font-fallback and runtime login-image warnings remain nonfatal.

Use `git show v0.1.0-rc.1 --stat` to inspect the local checkpoint. Treat it as a candidate with the open acceptance items below. Do not switch branches or merge source in a running office checkout; prepare a separate checkout and promote reviewed files during maintenance. Local Git history does not constitute a GitHub publication or hosted-CI run.

## Remaining approval and acceptance

1. Review the quarantined official PHP binary through the established endpoint-security process, then rerun native compatibility and application tests.
2. Recheck a fully patched Windows Apache build or document and approve the exact module mitigation; verify required C++ runtime support.
3. Verify a production-shaped candidate configuration, export protected service/task/certificate recovery material and review the executable/working-directory changes plus rollback.
4. Approve a maintenance cutover only after the above evidence exists. Recheck office/Tailscale HTTPS, account permissions, OCR, backups and restart recovery afterward.
5. Complete physical phone/PDF acceptance, the deferred second-PC test and an actual full-size transfer under approved conditions. The current session has no callable reminder action, so no automatic reminder notification was created for the deferred PC test.

If a cutover fails, stop only the replacement services, restore the previous task executable/configuration paths, restart the previously working services and verify private HTTPS. Preserve data and the original keys. Do not reset accounts or rebuild the database as rollback.
