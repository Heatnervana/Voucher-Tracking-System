# Project change history

## 2026-11-12 — Office readiness candidate

- Added developer/source/workflow/database guidance, a machine-configuration catalogue, the readiness plan, full recovery evidence and a release-readiness record.
- Created and verified a fresh protected backup and full disposable restore; retained and reconciled 35 obsolete OCR failure records without retry/deletion.
- Added a staging-only native Apache configuration generator and an optional backup `-PhpPath`; documented verified candidate hashes and the endpoint-protection block. No native service cutover occurred.
- Fixed stale GET `/logout` navigation with a safe redirect while retaining CSRF-protected POST sign-out. This maps the new `AuthController` SECURITY-FIX marker; added guest/authenticated/intended-destination regressions.
- Isolated PHPUnit from the production configuration cache and guarded application creation before database-reset traits; removed Composer's preparatory production-cache clear. Added cached/inherited-configuration safety regressions.
- Normalized the full-size upload harness's Apache configuration argument. An elevated run verified folder/per-file oversize rejection, then stopped at about 3.42 GB after the live response monitor timed out; finalization/full hashes remain unverified. Removed only the owned disposable fixture. A successful 5 GB transfer has not been claimed.
- Cached the unchanged private production configuration to stabilize sign-in; verified matching key, office database and session settings. This ignored runtime file is not part of the source release.
- Kept native security approval, live cutover/restart and final physical-device/full-size acceptance open. The user deferred second-PC testing to 2026-11-13.

## 2026-11-12 — Application security audit

Audit report: [docs/SECURITY_AUDIT.md](docs/SECURITY_AUDIT.md). Existing private deployment and administrator/staff permissions are preserved. No live database migration, credential reset, certificate/network changes, native runtime replacement, commit or push is part of this patch.

### Security fixes (SECURITY-FIX marker map)

- **SA-01:** `DatabaseSeeder` bootstraps only an empty installation, generates a random one-time temporary administrator password and requires a change. Existing accounts, including soft-deleted collisions, prevent bootstrap. Added seeder regression tests.
- **SA-02:** `composer.lock` updates only `symfony/yaml` 7.4.21 to 7.4.23 for the reported development-tooling advisory. No manifest/dependency addition.
- **SA-03/04:** `ProfileController` rejects reused passwords, rotates remember tokens, revokes other configured database sessions and destroys the prior current-session ID. `routes/web.php` applies authenticated-session hash checks. Added real two-browser/file-session and database-session regressions plus temporary-password coverage.
- **SA-05/06/10:** `AddSecurityHeaders` sets private/no-store, a server-generated request ID with log context, and production same-origin CSP. Its local hot-server exception remains local-only. `resources/js/app.js` and account/voucher templates replace inline confirmation handlers with escaped data attributes and a shared external listener. Added header/cache/CSP/error/correlation and confirmation tests. Same-origin PDF objects and port-specific HSTS remain deliberate compatibility decisions.
- **SA-07:** `Set up office access.ps1` constructs a fresh allowlisted backup ACL, protects setup archives before copying secrets, and validates backup paths/reparse points. Existing descendant ACL validation prevents unsafe history from being silently accepted; it does not recursively rewrite past backups. Added disposable ACL/path fixture tests. The elevated live setup was not run.
- **SA-08:** `VoucherController` validates nested multipart file inputs on both upload paths and checks the legacy 5 GB aggregate limit before discarding a prior staged review. Added acceptance/rejection/preservation regressions; existing per-file/folder limits remain.
- **SA-09:** `AuditSecurityEvents`, `ActivityRecorder`, `ActivityLog`, and middleware registration audit successful saved/recycled PDF GET/download deliveries (including Range requests) and authenticated authorization denials. Logs exclude credentials, request bodies and PDF bytes. Added actor/IP/range/head/guest/error/denial regressions and a positive administrator access test from a different device address.

Every dated SECURITY-FIX marker maps to one of these items, including repeated CSP/development-exception markers and the backup ACL/path/descendant markers. Source markers use the actual audit date, 2026-11-12.

### Review, documentation and verification support

- Added SECURITY/TRUST-BOUNDARY rationale to account/auth controllers, user/gate/observer code, upload/file/recycle paths, OCR extraction/process/jobs, file locks, backup checksums and Windows rendering. These annotations explain existing controls rather than add permission restrictions.
- Updated `.env.example` for SQLite, private session-cookie defaults and bounded daily informational file logging; the live private environment was not overwritten.
- Added `SECURITY.md`, architecture, dependency inventory, setup/recovery guidance, and the complete audit report; replaced the framework-template README with application instructions.
- Made `tests/office-https.test.ps1` use synthetic HTTP input and a standalone temporary Apache configuration, removing dependence on the running HTTPS virtual host. No service restart or certificate import occurs.
- Retained preexisting unrelated UI, CI/readiness, Tailscale and cleanup work. Native Apache/PHP/OpenSSL replacement and audit retention/restore decisions remain documented manual work; the overall runtime risk is not declared resolved.

The following release notes are inherited from the original Laravel application skeleton.

# Release Notes

## [Unreleased](https://github.com/laravel/laravel/compare/v13.11.0...13.x)

## [v13.11.0](https://github.com/laravel/laravel/compare/v13.10.1...v13.11.0) - 2026-09-30

* Use a different default lock directory for the file cache driver by [@CasEbb](https://github.com/CasEbb) in https://github.com/laravel/laravel/pull/6871

## [v13.10.1](https://github.com/laravel/laravel/compare/v13.10.0...v13.10.1) - 2026-08-25

**Full Changelog**: https://github.com/laravel/laravel/compare/v13.10.0...v13.10.1

## [v13.10.0](https://github.com/laravel/laravel/compare/v13.9.0...v13.10.0) - 2026-08-17

* Add "monthly" to the available log drivers comment by [@csemazharul](https://github.com/csemazharul) in https://github.com/laravel/laravel/pull/6855
* Ignore temporary LSP script files in `storage/framework` by [@imKenjo18](https://github.com/imKenjo18) in https://github.com/laravel/laravel/pull/6856

## [v13.9.0](https://github.com/laravel/laravel/compare/v13.8.0...v13.9.0) - 2026-08-12

* GitHub Actions hardening by [@nunomaduro](https://github.com/nunomaduro) in https://github.com/laravel/laravel/pull/6829
* Bump actions/checkout from 6.0.2 to 6.0.3 in the github-actions group by [@dependabot](https://github.com/dependabot)[bot] in https://github.com/laravel/laravel/pull/6830
* Pin pull requests and issues workflows to least-privilege reusable workflows by [@nunomaduro](https://github.com/nunomaduro) in https://github.com/laravel/laravel/pull/6831
* Pin pull requests and issues workflows to latest laravel/.github by [@nunomaduro](https://github.com/nunomaduro) in https://github.com/laravel/laravel/pull/6832
* Add Dependabot cooldown of 5 days by [@nunomaduro](https://github.com/nunomaduro) in https://github.com/laravel/laravel/pull/6833
* Enable Dependabot auto-merge by [@nunomaduro](https://github.com/nunomaduro) in https://github.com/laravel/laravel/pull/6834
* Restore expectsJson fallback when rendering JSON exceptions by [@jasonvarga](https://github.com/jasonvarga) in https://github.com/laravel/laravel/pull/6837
* Bump shivammathur/setup-php from 2.37.1 to 2.37.2 in the github-actions group by [@dependabot](https://github.com/dependabot)[bot] in https://github.com/laravel/laravel/pull/6838
* Add 'array' as a supported maintenance mode driver doc block by [@jackbayliss](https://github.com/jackbayliss) in https://github.com/laravel/laravel/pull/6840
* Bump actions/checkout from 6.0.3 to 7.0.0 in the github-actions group by [@dependabot](https://github.com/dependabot)[bot] in https://github.com/laravel/laravel/pull/6841
* Bump Concurrently to v10 by [@u01jmg3](https://github.com/u01jmg3) in https://github.com/laravel/laravel/pull/6845
* Update APP_URL to use port 8000 by [@dipesh79](https://github.com/dipesh79) in https://github.com/laravel/laravel/pull/6846
* Add monthly log driver to `config/logging.php` by [@SjorsO](https://github.com/SjorsO) in https://github.com/laravel/laravel/pull/6847
* Bump actions/checkout from 7.0.0 to 7.0.1 in the github-actions group by [@dependabot](https://github.com/dependabot)[bot] in https://github.com/laravel/laravel/pull/6848
* Use new `artisan dev` command in `composer dev` script by [@WendellAdriel](https://github.com/WendellAdriel) in https://github.com/laravel/laravel/pull/6849
* Add `@laravel/multiplex` by [@joetannenbaum](https://github.com/joetannenbaum) in https://github.com/laravel/laravel/pull/6854

## [v13.8.0](https://github.com/laravel/laravel/compare/v13.7.0...v13.8.0) - 2026-05-25

* [13.x] remove Tailwind `@source` calls that are already covered by default by [@browner12](https://github.com/browner12) in https://github.com/laravel/laravel/pull/6823
* [13.x] Render JSON exceptions for API routes by default by [@LucasCavalheri](https://github.com/LucasCavalheri) in https://github.com/laravel/laravel/pull/6824

## [v13.7.0](https://github.com/laravel/laravel/compare/v13.6.0...v13.7.0) - 2026-05-14

**Full Changelog**: https://github.com/laravel/laravel/compare/v13.6.0...v13.7.0

## [v13.6.0](https://github.com/laravel/laravel/compare/v13.5.0...v13.6.0) - 2026-05-11

* Remove Pdo/Mysql const workaround by [@jnoordsij](https://github.com/jnoordsij) in https://github.com/laravel/laravel/pull/6810

## [v13.5.0](https://github.com/laravel/laravel/compare/v13.4.0...v13.5.0) - 2026-04-30

* Use the Vite font plugin for application fonts by [@WendellAdriel](https://github.com/WendellAdriel) in https://github.com/laravel/laravel/pull/6806

## [v13.4.0](https://github.com/laravel/laravel/compare/v13.3.0...v13.4.0) - 2026-04-28

* Add @no_additional_args to composer test script config clear by [@jnoordsij](https://github.com/jnoordsij) in https://github.com/laravel/laravel/pull/6799
* [13.x] Adds pao by default by [@nunomaduro](https://github.com/nunomaduro) in https://github.com/laravel/laravel/pull/6802

## [v13.3.0](https://github.com/laravel/laravel/compare/v13.2.0...v13.3.0) - 2026-04-16

* [13.x] enable npm audit by default by [@leo95batista](https://github.com/leo95batista) in https://github.com/laravel/laravel/pull/6788
* Update changelog link to Laravel framework repo by [@Rattone](https://github.com/Rattone) in https://github.com/laravel/laravel/pull/6790
* [13x] Add .codex to .gitignore by [@amdad121](https://github.com/amdad121) in https://github.com/laravel/laravel/pull/6793

## [v13.2.0](https://github.com/laravel/laravel/compare/v13.1.2...v13.2.0) - 2026-04-09

* Remove axios and enable ignore-scripts by [@WendellAdriel](https://github.com/WendellAdriel) in https://github.com/laravel/laravel/pull/6778
* Add /.cursor/ to .gitignore by [@workwithbinu](https://github.com/workwithbinu) in https://github.com/laravel/laravel/pull/6782
* Remove '.fleet' from .gitignore by [@dominiq007](https://github.com/dominiq007) in https://github.com/laravel/laravel/pull/6783
* Support all compose file naming conventions in editorconfig by [@mmachatschek](https://github.com/mmachatschek) in https://github.com/laravel/laravel/pull/6786

## [v13.1.2](https://github.com/laravel/laravel/compare/v13.1.1...v13.1.2) - 2026-03-31

* Prevents installed package from executing malicious code via `postinstall` by [@crynobone](https://github.com/crynobone) in https://github.com/laravel/laravel/pull/6777
* Add missing comma in axios by [@aziyan99](https://github.com/aziyan99) in https://github.com/laravel/laravel/pull/6779

## [v13.1.1](https://github.com/laravel/laravel/compare/v13.1.0...v13.1.1) - 2026-03-31

* Update .gitignore by [@Cegem-360](https://github.com/Cegem-360) in https://github.com/laravel/laravel/pull/6774
* [security] pin axios version by [@NickSdot](https://github.com/NickSdot) in https://github.com/laravel/laravel/pull/6776

## [v13.1.0](https://github.com/laravel/laravel/compare/v12.12.2...v13.1.0) - 2026-03-18

* Change back minimum-stability to stable by [@jnoordsij](https://github.com/jnoordsij) in https://github.com/laravel/laravel/pull/6766
* Vite 8 support

## [v12.12.2](https://github.com/laravel/laravel/compare/v12.12.1...v12.12.2) - 2026-03-14

* [12.x] Add `APP_NAME` fallback in Slack log channel username by [@hamedelasma](https://github.com/hamedelasma) in https://github.com/laravel/laravel/pull/6762

## [v12.12.1](https://github.com/laravel/laravel/compare/v12.12.0...v12.12.1) - 2026-03-10

* [12.x] Makes imports consistent by [@nunomaduro](https://github.com/nunomaduro) in https://github.com/laravel/laravel/pull/6760

## [v12.12.0](https://github.com/laravel/laravel/compare/v12.11.2...v12.12.0) - 2026-03-09

* Update phpunit version to ^11.5.50 to address CVE by [@PerryvanderMeer](https://github.com/PerryvanderMeer) in https://github.com/laravel/laravel/pull/6746
* [12.x] Add `APP_NAME` fallback in mail config by [@apoorvdarshan](https://github.com/apoorvdarshan) in https://github.com/laravel/laravel/pull/6755
* [12.x] Neutralize DB_URL in default phpunit.xml by [@Husseinadq](https://github.com/Husseinadq) in https://github.com/laravel/laravel/pull/6761

## [v12.11.2](https://github.com/laravel/laravel/compare/v12.11.1...v12.11.2) - 2026-01-19

* [12.x] Update composer dev script to ensure no timeout by [@jackbayliss](https://github.com/jackbayliss) in https://github.com/laravel/laravel/pull/6735
* [12.x] Update jobs/cache migrations by [@jackbayliss](https://github.com/jackbayliss) in https://github.com/laravel/laravel/pull/6736
* [12.x] Remove failed jobs indexes by [@jackbayliss](https://github.com/jackbayliss) in https://github.com/laravel/laravel/pull/6739
* [12.x] Add `APP_URL` fallback in filesystems config by [@KentarouTakeda](https://github.com/KentarouTakeda) in https://github.com/laravel/laravel/pull/6742
* chore: Update outdated GitHub Actions version by [@pgoslatara](https://github.com/pgoslatara) in https://github.com/laravel/laravel/pull/6743

## [v12.11.1](https://github.com/laravel/laravel/compare/v12.11.0...v12.11.1) - 2025-12-23

* Use environment variable for `DB_SSLMODE` - Postgres by [@robsontenorio](https://github.com/robsontenorio) in https://github.com/laravel/laravel/pull/6727
* fix: ensure APP_URL does not have trailing slash in filesystem by [@msamgan](https://github.com/msamgan) in https://github.com/laravel/laravel/pull/6728

## [v12.11.0](https://github.com/laravel/laravel/compare/v12.10.1...v12.11.0) - 2025-11-25

* fix: cookies are not available for subdomains by default by [@joostdebruijn](https://github.com/joostdebruijn) in https://github.com/laravel/laravel/pull/6705
* Fix PHP 8.5 PDO Driver Specific Constant Deprecation by [@RyanSchaefer](https://github.com/RyanSchaefer) in https://github.com/laravel/laravel/pull/6710
* Ignore Laravel compiled views for Vite  by [@QistiAmal1212](https://github.com/QistiAmal1212) in https://github.com/laravel/laravel/pull/6714

## [v12.10.1](https://github.com/laravel/laravel/compare/v12.10.0...v12.10.1) - 2025-11-06

* Update schema URL in package.json by [@robinmiau](https://github.com/robinmiau) in https://github.com/laravel/laravel/pull/6701

## [v12.10.0](https://github.com/laravel/laravel/compare/v12.9.1...v12.10.0) - 2025-11-04

* Add background driver by [@barryvdh](https://github.com/barryvdh) in https://github.com/laravel/laravel/pull/6699

## [v12.9.1](https://github.com/laravel/laravel/compare/v12.9.0...v12.9.1) - 2025-10-23

* [12.x] Replace Bootcamp with Laravel Learn by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6692
* [12.x] Comment out CLI workers for fresh applications by [@timacdonald](https://github.com/timacdonald) in https://github.com/laravel/laravel/pull/6693

## [v12.9.0](https://github.com/laravel/laravel/compare/v12.8.0...v12.9.0) - 2025-10-21

**Full Changelog**: https://github.com/laravel/laravel/compare/v12.8.0...v12.9.0

## [v12.8.0](https://github.com/laravel/laravel/compare/v12.7.1...v12.8.0) - 2025-10-20

* [12.x] Makes test suite using broadcast's `null` driver by [@nunomaduro](https://github.com/nunomaduro) in https://github.com/laravel/laravel/pull/6691

## [v12.7.1](https://github.com/laravel/laravel/compare/v12.7.0...v12.7.1) - 2025-10-15

* Added `failover` driver to the `queue` config comment.  by [@sajjadhossainshohag](https://github.com/sajjadhossainshohag) in https://github.com/laravel/laravel/pull/6688

## [v12.7.0](https://github.com/laravel/laravel/compare/v12.6.0...v12.7.0) - 2025-10-14

**Full Changelog**: https://github.com/laravel/laravel/compare/v12.6.0...v12.7.0

## [v12.6.0](https://github.com/laravel/laravel/compare/v12.5.0...v12.6.0) - 2025-10-02

* Fix setup script by [@goldmont](https://github.com/goldmont) in https://github.com/laravel/laravel/pull/6682

## [v12.5.0](https://github.com/laravel/laravel/compare/v12.4.0...v12.5.0) - 2025-09-30

* [12.x] Fix type casting for environment variables in config files by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6670
* Fix CVEs affecting vite by [@faissaloux](https://github.com/faissaloux) in https://github.com/laravel/laravel/pull/6672
* Update .editorconfig to target compose.yaml by [@fredikaputra](https://github.com/fredikaputra) in https://github.com/laravel/laravel/pull/6679
* Add pre-package-uninstall script to composer.json by [@cosmastech](https://github.com/cosmastech) in https://github.com/laravel/laravel/pull/6681

## [v12.4.0](https://github.com/laravel/laravel/compare/v12.3.1...v12.4.0) - 2025-08-29

* [12.x] Add default Redis retry configuration by [@mateusjatenee](https://github.com/mateusjatenee) in https://github.com/laravel/laravel/pull/6666

## [v12.3.1](https://github.com/laravel/laravel/compare/v12.3.0...v12.3.1) - 2025-08-21

* [12.x] Bump Pint version by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6653
* [12.x] Making sure all related processed are closed when terminating the currently command by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6654
* [12.x] Use application name from configuration by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6655
* Bring back postAutoloadDump script by [@jasonvarga](https://github.com/jasonvarga) in https://github.com/laravel/laravel/pull/6662

## [v12.3.0](https://github.com/laravel/laravel/compare/v12.2.0...v12.3.0) - 2025-08-03

* Fix Critical Security Vulnerability in form-data Dependency by [@izzygld](https://github.com/izzygld) in https://github.com/laravel/laravel/pull/6645
* Revert "fix" by [@RobertBoes](https://github.com/RobertBoes) in https://github.com/laravel/laravel/pull/6646
* Change composer post-autoload-dump script to Artisan command by [@lmjhs](https://github.com/lmjhs) in https://github.com/laravel/laravel/pull/6647

## [v12.2.0](https://github.com/laravel/laravel/compare/v12.1.0...v12.2.0) - 2025-07-11

* Add Vite 7 support by [@timacdonald](https://github.com/timacdonald) in https://github.com/laravel/laravel/pull/6639

## [v12.1.0](https://github.com/laravel/laravel/compare/v12.0.11...v12.1.0) - 2025-07-03

* [12.x] Disable nightwatch in testing by [@laserhybiz](https://github.com/laserhybiz) in https://github.com/laravel/laravel/pull/6632
* [12.x] Reorder environment variables in phpunit.xml for logical grouping by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6634
* Change to hyphenate prefixes and cookie names by [@u01jmg3](https://github.com/u01jmg3) in https://github.com/laravel/laravel/pull/6636
* [12.x] Fix type casting for environment variables in config files by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6637

## [v12.0.11](https://github.com/laravel/laravel/compare/v12.0.10...v12.0.11) - 2025-06-10

**Full Changelog**: https://github.com/laravel/laravel/compare/v12.0.10...v12.0.11

## [v12.0.10](https://github.com/laravel/laravel/compare/v12.0.9...v12.0.10) - 2025-06-09

* fix alphabetical order by [@Khuthaily](https://github.com/Khuthaily) in https://github.com/laravel/laravel/pull/6627
* [12.x] Reduce redundancy and keeps the .gitignore file cleaner by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6629
* [12.x] Fix: Add void return type to satisfy Rector analysis by [@Aluisio-Pires](https://github.com/Aluisio-Pires) in https://github.com/laravel/laravel/pull/6628

## [v12.0.9](https://github.com/laravel/laravel/compare/v12.0.8...v12.0.9) - 2025-05-26

* [12.x] Remove apc by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6611
* [12.x] Add JSON Schema to package.json by [@martinbean](https://github.com/martinbean) in https://github.com/laravel/laravel/pull/6613
* Minor language update by [@woganmay](https://github.com/woganmay) in https://github.com/laravel/laravel/pull/6615
* Enhance .gitignore to exclude common OS and log files by [@mohammadRezaei1380](https://github.com/mohammadRezaei1380) in https://github.com/laravel/laravel/pull/6619

## [v12.0.8](https://github.com/laravel/laravel/compare/v12.0.7...v12.0.8) - 2025-05-12

* [12.x] Clean up URL formatting in README by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6601

## [v12.0.7](https://github.com/laravel/laravel/compare/v12.0.6...v12.0.7) - 2025-04-15

* Add `composer run test` command by [@crynobone](https://github.com/crynobone) in https://github.com/laravel/laravel/pull/6598
* Partner Directory Changes in ReadME by [@joshcirre](https://github.com/joshcirre) in https://github.com/laravel/laravel/pull/6599

## [v12.0.6](https://github.com/laravel/laravel/compare/v12.0.5...v12.0.6) - 2025-04-08

**Full Changelog**: https://github.com/laravel/laravel/compare/v12.0.5...v12.0.6

## [v12.0.5](https://github.com/laravel/laravel/compare/v12.0.4...v12.0.5) - 2025-04-02

* [12.x] Update `config/mail.php` to match the latest core configuration by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6594

## [v12.0.4](https://github.com/laravel/laravel/compare/v12.0.3...v12.0.4) - 2025-03-31

* Bump vite from 6.0.11 to 6.2.3 - Vulnerability patch by [@abdel-aouby](https://github.com/abdel-aouby) in https://github.com/laravel/laravel/pull/6586
* Bump vite from 6.2.3 to 6.2.4 by [@thinkverse](https://github.com/thinkverse) in https://github.com/laravel/laravel/pull/6590

## [v12.0.3](https://github.com/laravel/laravel/compare/v12.0.2...v12.0.3) - 2025-03-17

* Remove reverted change from CHANGELOG.md by [@AJenbo](https://github.com/AJenbo) in https://github.com/laravel/laravel/pull/6565
* Improves clarity in app.css file by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6569
* [12.x] Refactor: Structural improvement for clarity by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6574
* Bump axios from 1.7.9 to 1.8.2 - Vulnerability patch by [@abdel-aouby](https://github.com/abdel-aouby) in https://github.com/laravel/laravel/pull/6572
* [12.x] Remove Unnecessarily [@source](https://github.com/source) by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6584

## [v12.0.2](https://github.com/laravel/laravel/compare/v12.0.1...v12.0.2) - 2025-03-04

* Make the github test action run out of the box independent of the choice of testing framework by [@ndeblauw](https://github.com/ndeblauw) in https://github.com/laravel/laravel/pull/6555

## [v12.0.1](https://github.com/laravel/laravel/compare/v12.0.0...v12.0.1) - 2025-02-24

* [12.x] prefer stable stability by [@pataar](https://github.com/pataar) in https://github.com/laravel/laravel/pull/6548

## [v12.0.0 (2025-??-??)](https://github.com/laravel/laravel/compare/v11.0.2...v12.0.0)

Laravel 12 includes a variety of changes to the application skeleton. Please consult the diff to see what's new.
