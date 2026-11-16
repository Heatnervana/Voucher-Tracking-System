# Office readiness candidate — 2026-11-12

This record covers the local readiness work and its limits. It is a release-candidate record, not approval for a native-runtime cutover or public deployment. Keep the existing office/Tailscale topology and administrator/staff permissions.

## Five-item status

| Item | Result |
|---|---|
| Native server upgrade | Candidate archives verified and staging configuration prepared; **execution/security approval and live cutover pending**. |
| Historical OCR failures | **Reviewed and reconciled.** Retain all 35 records; no obsolete job was replayed or deleted. |
| Backup, recovery and startup | **Fresh full backup and isolated restore verified**, including application smoke. **Supervised restart and a new OCR job verified on November 16:** services started automatically, both HTTPS sign-in pages returned 200, baseline counts matched, and the scheduled worker later read the expected amount from a disposable scan. Any future native cutover requires its own checks. |
| Real devices and full-size upload | **User confirmed staff sign-in, search and PDF page-two access on the phone and another office PC on November 16.** The November 12 upload reached about 3.42 GB before a health timeout. The approved November 16 attempt stopped during input generation after another timeout, before uploading. **No completed 5 GB transfer is claimed.** |
| Developer handoff and Git baseline | **Complete as a local candidate:** developer/configuration guides, reviewed tests and tag `v0.1.0-rc.1` on `codex/office-readiness-20261112`. Nothing was pushed or published. |

## Follow-up verification: 2026-11-16

The user authorized light checks and required advance warning before tests that could cause lag. After the warning, the user authorized the monitored 5 GB test while nobody needed the site. The user has no access to eScan policy administration, so the native upgrade still depends on review by an authorized security administrator. No runtime, firewall, certificate or task configuration changed during this follow-up.

### Office connection and physical devices

The server was connected to the office Wi-Fi with a DHCP-assigned address that had changed since November 13. Its current address matched the existing Apache allowlist. The user confirmed that the previously affected PC could access the site, then confirmed staff sign-in, search and reaching PDF page 2. The user also confirmed those steps on the phone after receiving the Tailscale test instructions. No router-isolation or firewall fault was established, and no network rule was changed. A stable address/DNS arrangement remains an operational follow-up.

The Tailscale sign-in page opened in Playwright with no console errors, failed resource statuses or horizontal overflow at 1280 x 720. Strict Windows curl validation succeeded for the Tailscale hostname. The private office certificate produced `CRYPT_E_NO_REVOCATION_CHECK`; the diagnostic succeeded with `--ssl-revoke-best-effort`, retaining certificate-chain and hostname checks while tolerating missing revocation information. This command-specific result does not establish certificate trust on every client.

### Startup and existing backup evidence

Windows reported a boot at 07:40:48 +08:00. The Tailscale service processes started at 07:40:58 and 07:41:00; Apache started at 07:41:10 under the scheduler service process. Both Apache and the OCR tasks were running when inspected. Their configured triggers include startup and one-minute recovery checks, with duplicate instances ignored. The current OCR process began at 08:43:22; its command exits after one hour for supervised renewal. Task Scheduler operational history was disabled, so this review cannot reconstruct every earlier OCR start/exit.

The backup task returned result 0, its log reported completion, and the latest protected archive contained a database, environment file and manifest dated that morning. This was a metadata/log check, not a new full hash or restore test. The previously recorded full restore remains the recovery evidence. These morning observations preceded the supervised restart below; no new backup was initiated.

### Supervised restart verification

The user restarted Windows after the operator saved a private baseline at 11:19:08 +08:00. Windows reported a new boot at 11:24:38, replacing the earlier 07:40:48 boot. No manual Apache, XAMPP or amount-reader launcher was used during verification.

Tailscale started at 11:24:45. Apache and the OCR PHP worker started at 11:25:46 under the Windows Task Scheduler service. Apache logged normal operation at 11:26:55 and started its worker threads at 11:26:56, about two minutes and eighteen seconds after boot. Both application tasks were running. Their minute-based recovery triggers reported that an existing instance was already running, consistent with their `IgnoreNew` setting; this was not evidence of a failed initial start.

Wi-Fi reconnected automatically to the previous office network and retained the baseline IPv4 address. Apache listened on the expected office ports; Tailscale Serve retained its private HTTPS proxy. The office sign-in page returned HTTP 200 in 2.25 seconds with the previously described revocation-check tolerance. The Tailscale sign-in page returned HTTP 200 in 1.36 seconds with normal certificate validation.

Read-only SQLite counts matched the baseline: 1,014 active vouchers, zero recycled vouchers, zero queued or reserved jobs, and the same 35 historical failed jobs. The production configuration cache retained its original size and modification time. The backup task remained enabled and ready, with its previous successful result and next run at 18:00; no backup was forced during this check.

The bounded log review found no new voucher application request or queue exception after boot. A console integration attempted an unavailable `boost` namespace at 11:33:31. Apache also retained its existing default `www.example.com` certificate-name warning and reported a stale PID file after the previous process ended during Windows shutdown; it then resumed normal operation. These warnings were recorded, not silently repaired or treated as proof that every client trusts the office certificate.

The initial check verified automatic service recovery and sign-in-page availability; its queue was empty. The later functional check below verifies a newly queued OCR job. No large upload, native upgrade, network change or endpoint-security change was performed. The baseline and post-restart evidence remain in ignored local testing storage.

### Scheduled OCR worker and PDF loading follow-up

After the user approved a small OCR test and received a CPU-impact warning, the operator placed the existing 74,998-byte synthetic scan in a new UUID staging path and queued one normal `ReadVoucherAmount` job. No extra worker was launched. The scheduled PHP worker consumed the job and returned `ready` with the expected amount `5250.00`. The first result check was approximately 20 seconds after dispatch; this polling interval is not an OCR execution-time measurement.

HTTPS sign-in returned 200 in 0.78 seconds before dispatch and 0.81 seconds at the result check. There were 1,072 active saved vouchers both before and after this probe, reflecting user uploads after the earlier restart baseline. The historical failed-job count stayed at 35. The operator verified the fixture hash and staging boundary, removed only that disposable PDF and cache result, and confirmed the job was consumed. No saved voucher or account was created or edited. The private probe evidence is retained in ignored testing storage.

The saved-PDF viewing endpoint now uses the installed framework's binary file response, allowing authenticated browsers to request byte ranges. Admin and staff use the same endpoint and retain their existing permissions; staged previews, recycle-bin previews and the separate admin download endpoint are unchanged. The response remains inline, private and `no-store`, with existing PDF-access auditing. No new package, web-server setting or public storage link was added.

The first regression run failed eight checks against the old whole-file streaming response. The candidate was tested through an isolated test-only controller mapping before promotion into the running source tree. Review also identified a Symfony 7.4 response-framing edge: an out-of-bounds request returned 416 with the full file length but no body. A new assertion reproduced that failure. The controller prepares the response and sets an empty 416 response's length to zero, leaving parsing and valid-range delivery to the framework.

The final focused suite passed **55 tests / 299 assertions**, covering exact partial bytes for both roles, middle/open-ended/suffix ranges, full-file requests, HEAD, out-of-bounds ranges, authentication, missing/recycled records, staff restrictions, security headers and auditing. PHP syntax and Pint checks passed. After the change, the user reopened a PDF on the affected staff PC and reported that loading was better. This is user-observed improvement without a timed before/after comparison; it is not a completed concurrent-upload capacity test. Final live checks returned office sign-in 200 in 2.11 seconds, Tailscale sign-in 200 in 0.75 seconds, and an unauthenticated PDF range request redirected to sign-in with 302.

The earlier browser-tab logo fix is included in this local source follow-up: the empty `favicon.ico` was replaced with a valid icon containing 16, 32, 48 and 64 pixel frames, and both page layouts reference its versioned URL. The existing PPA artwork is unchanged. The icon was decoded successfully in the browser and served successfully through office and Tailscale access.

### Monitored capacity attempt and recovery

The operator ran the existing `tests/large-folder-upload.mjs` with its own disposable directory and a live sign-in monitor. The baseline returned HTTP 200 in 2.28 seconds. The test generated one 500,000,000-byte PDF in 8.4 seconds and began the next file. The monitor then timed out after 12.01 seconds with no response bytes and stopped only the identified test process tree. Upload, isolated Apache startup, fixture database preparation and finalization had not begun.

The operator verified the disposable directory boundary, checked for reparse points and remaining test child processes, then removed 550,331,923 bytes of test input. The directory no longer existed afterward. The monitor and test output remain in ignored local testing storage. Live Apache kept its original process ID.

After cleanup, office sign-in returned HTTP 200 in 1.94 seconds. A later comparison returned 200 in 1.84 seconds through the office hostname, 0.70 seconds with hostname lookup bypassed for that one request, and 0.97 seconds through Tailscale. The office hostname lookup alone took 1.37 seconds in that sample. These samples do not identify the cause of the earlier 12-second timeout. No new application exception appeared in the bounded log review; the latest observed exception was the earlier console namespace lookup.

The full-size check remains incomplete. The next approved diagnostic, described below, added simultaneous hostname/loopback timings and resource measurements. Do not attribute the earlier timeout to the upload handler, SQLite, security software or hardware without evidence.

### Smaller diagnostic after the timeout investigation

The operator first ran 12 small requests comparing hostname sign-in, loopback sign-in, Laravel health and a static logo. All returned HTTP 200. Hostname lookup took 1.39-1.43 seconds; loopback sign-in took 0.74-0.80 seconds. Apache's access log recorded two successful login responses around the original timeout, but its request timestamps do not show when those responses finished. The available logs do not establish the cause of the 12-second timeout.

After a separate lag warning and explicit approval, the operator generated one disposable 500,000,000-byte PDF using the unchanged generator function from the existing capacity test. No upload, OCR worker or fixture database ran in this diagnostic. Generation took 2.171 seconds. Across five rounds, all 15 monitored requests returned HTTP 200: direct sign-in took 0.71-1.24 seconds, hostname sign-in 1.82-2.15 seconds and the static logo 0.36-0.40 seconds.

The resource samples captured a brief disk activity spike during generation (96% disk time and queue length 2), with 1,643 MB available memory at that sample. The largest sampled total CPU usage was 67%, after the generator had exited. These measurements show the cost of generating test input but do not reproduce or explain the earlier timeout. The operator removed the 500 MB file and retained the timings and resource records in ignored testing storage.

The user chose to defer the next full 5 GB test until off-hours. No further full-size run or automatic schedule was started. The 500 MB generation result is not a successful upload or 5 GB capacity result. Future testing must retain the live-response stop condition and distinguish hostname resolution from Apache/PHP response time.

### Native approval and stable office address preparation

The user requested these two follow-ups on November 16 but confirmed that they have neither eScan policy access nor router administrator access. Both changes need the respective administrator's participation. This review did not alter either policy or the network configuration.

For the endpoint-security administrator: review the PHP 8.5.12-1 VS17 x64 thread-safe package from the [official PHP Windows downloads page](https://www.php.net/downloads.php?os=windows). The operator rehashed the retained archive and confirmed SHA-256 `0dacd6484b092b5e9ae9fa3d7f96a937caf9d874eee2d980b68ed3129741144a`, matching the publisher. The previous incident involved `<AppRoot>/storage/framework/testing/native-upgrade/php/php.exe` during PHPUnit, with a recorded `RANSOM-PID` termination. That staged executable was still absent on November 16. The earlier recorded executable hash is `0ac893499799256db6d3a54b0da73692e02311493922e6e4f86a062674a77d66`; it was not re-extracted or rerun. A matching archive hash establishes package integrity, not a false-positive verdict. Request a security decision or an approved replacement package before further execution.

The Windows distributor now offers [Apache 2.4.71-261112](https://www.apachelounge.com/download/), with [published archive SHA-256](https://www.apachelounge.com/download/VS18/binaries/httpd-2.4.71-261112-Win64-VS18.zip.txt) `44961a929dff16b767b3adbcea55760a942a00239b2891dc55806be34029e1fc`. This supersedes the earlier 2.4.70 candidate for further upgrade preparation. The publisher lists Visual C++ 14.52.37029; this PC reports 14.50.35719.00 installed. No new Apache archive or C++ installer was downloaded or applied in this follow-up. Review these prerequisites before native testing and cutover.

For the network administrator: the server uses a DHCP lease from the office gateway on its USB Wi-Fi adapter. Reserve the current, working server address for that adapter's observed MAC address, checking existing leases and reservations first. The operator provided the concrete address/MAC pair to the user separately; retain those machine-specific details in the private office record. The current address already matches Apache's allowed hosts and the office certificate configuration.

The gateway's public HTTPS login page identified a ZTE F670L. Its HTTP management endpoint timed out, and HTTPS used an untrusted router certificate. An unauthenticated page read identified the model; no credential was submitted or trust installed. An explicit DNS query to the gateway for the office hostname returned NXDOMAIN. After authorized sign-in, inspect whether the firmware supports a local DNS record and a suitable DNS service for clients. A DHCP reservation alone does not establish name resolution. Do not point office clients at the router for DNS before verifying local and ordinary internet resolution, or guess a static address within an unknown lease pool.

Existing authorized Tailscale clients can continue using the already configured private HTTPS hostname while these approvals are pending. No router reservation, local DNS record, adapter address, certificate or firewall rule was changed. This follow-up does not make the site public or change administrator/staff permissions.

## Native runtime preparation

The live installation remains Apache 2.4.58 / PHP 8.2.12 / OpenSSL 3.1.3. No replacement service, certificate, firewall rule, Windows runtime prerequisite or task action was applied.

Downloaded candidates:

| Artifact | Version and provenance | SHA-256 |
|---|---|---|
| Apache Windows x64 VS18 | Apache Lounge `httpd-2.4.70-261106-Win64-VS18.zip`; server reports 2.4.70, OpenSSL 3.5.9 | `b96a40b66a99428b2e4aea48ebe98bab826c768df4ace81f78dd7e26a98ad311` |
| PHP Windows x64 thread-safe VS17 | Official `php-8.5.12-1-Win32-vs17-x64.zip`; CLI reports PHP 8.5.12, OpenSSL 3.5.9 | `0dacd6484b092b5e9ae9fa3d7f96a937caf9d874eee2d980b68ed3129741144a` |

The PHP executable's SHA-256 inside the verified archive is `0ac893499799256db6d3a54b0da73692e02311493922e6e4f86a062674a77d66`. Endpoint protection terminated its PHPUnit process with a `RANSOM-PID` event and removed the staged executable. The interrupted run is **not a passing test result**. Leave it blocked pending the operator's normal security review; do not rename/re-extract an executable to evade the control, disable protection or create a broad exclusion.

During the initial November 12 staging review, upstream Apache had released 2.4.71 but the checked Windows distributor listed 2.4.70. The November 16 follow-up above found a 2.4.71 Windows build, which supersedes that candidate choice. The configuration generator excludes `mod_http2`, `mod_md` and `mod_vhost_alias`, the modules associated with the three 2.4.71 advisories. This is a conditional mitigation, not a claim that 2.4.70 contains those patches. Recheck vendor availability and prerequisites before any cutover. Sources: [Apache downloads](https://httpd.apache.org/download.cgi), [security advisories](https://httpd.apache.org/security/vulnerabilities_24.html), [Apache Lounge Windows builds](https://www.apachelounge.com/download/), [official PHP Windows downloads](https://www.php.net/downloads.php?os=windows).

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
5. Retain the November 16 user-confirmed phone and second-PC acceptance. Complete an actual full-size transfer under approved conditions after investigating the monitored timeout, and repeat device checks after any native cutover. No automatic reminder notification was created for the earlier deferred PC test.

If a cutover fails, stop only the replacement services, restore the previous task executable/configuration paths, restart the previously working services and verify private HTTPS. Preserve data and the original keys. Do not reset accounts or rebuild the database as rollback.
