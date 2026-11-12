# Dependency review

Review date: 2026-11-12. Scope: declared dependencies, lockfile provenance, known-vulnerability reports, and the separately installed native web stack. This review added no dependency.

## Audit results

| Scanner | Before remediation | After remediation | Final verification |
| --- | --- | --- | --- |
| Composer audit, including development packages | 1 advisory, high | 0 advisories | Final isolated and active-checkout scans: 0; metadata cache warning |
| npm audit, including development packages | 0 advisories | 0 advisories | Final active-checkout scan: 0 |

Composer reported `symfony/yaml` 7.4.21 under [GHSA-hwfg-qwvh-2wfx](https://github.com/advisories/GHSA-hwfg-qwvh-2wfx), CVE-2026-123792, high severity with a reported score of 8.7. The remediation updates that transitive dependency to 7.4.23. The current Composer lockfile places it in `packages-dev`; `laravel/roster` requires it. See the [package registry](https://packagist.org/packages/symfony/yaml) and [upstream repository](https://github.com/symfony/yaml).

The advisory identifiers and score above come from the captured Composer audit evidence. A separate public-page fetch during documentation returned 404, so this document does not claim a second verification of the advisory page. The dependency affects development tooling in this installation; the audit has not demonstrated a reachable production request exploit. Development and CI machines still process that code. Production installations that include development dependencies retain that exposure.

The zero counts describe the scanners' known-advisory databases at scan time. They do not establish that dependencies contain no vulnerabilities or malicious changes. The final npm scan of the active checkout reported zero vulnerabilities. Both final Composer scans exited successfully and reported zero advisories, but warned that base package metadata fell back to the local cache after a Packagist timeout. Repeat with reliable registry access during maintenance; do not describe the cached metadata as an independently complete fresh registry review. The active installed YAML version was independently confirmed as 7.4.23.

## Direct package inventory

The reviewer checked all 16 declared direct packages against public registry metadata. The private evidence file is `storage/framework/testing/security-dependency-inventory.json` in the original audit workspace. For each Composer package, its registry repository matched the lockfile source. For each npm package, its registry integrity value matched the lockfile integrity value. The identities and upstream repositories below match the packages' stated purposes; the reviewer found no metadata evidence of an impersonating package in this direct set.

The dates below are each package's first publication date from the registry, **not the installed version's release date**. Download counts are the registry's monthly snapshot at review time. Packagist author names and npm maintainer handles describe registry metadata; they do not certify the identity of whoever published an individual release. Download volume and package age do not prove safety.

### Composer

`Runtime` means a declaration in `composer.json` `require`; `Dev` means `require-dev`. Tinker is a runtime declaration even though its purpose is interactive console work.

| Package and locked version | Scope and purpose | Registry author metadata | First published | Monthly downloads | Registry and source checks |
| --- | --- | --- | --- | ---: | --- |
| `laravel/framework` 12.70.1 | Runtime: application framework | Taylor Otwell | 2013-01-10 | 24,042,534 | [Packagist](https://packagist.org/packages/laravel/framework); [Laravel source](https://github.com/laravel/framework), lock source matches |
| `laravel/tinker` 3.0.2 | Runtime: interactive application console | Taylor Otwell | 2016-12-29 | 22,948,573 | [Packagist](https://packagist.org/packages/laravel/tinker); [Laravel source](https://github.com/laravel/tinker), lock source matches |
| `fakerphp/faker` 1.24.1 | Dev: fixture data | François Zaninotto | 2020-10-27 | 21,943,282 | [Packagist](https://packagist.org/packages/fakerphp/faker); [FakerPHP source](https://github.com/FakerPHP/Faker), lock source matches |
| `laravel/boost` 2.10.3 | Dev: Laravel development tooling | No author supplied in inspected metadata | 2025-08-13 | 9,765,500 | [Packagist](https://packagist.org/packages/laravel/boost); [Laravel source](https://github.com/laravel/boost), lock source matches |
| `laravel/pail` 1.2.7 | Dev: log viewing | Taylor Otwell, Nuno Maduro | 2023-09-25 | 13,850,375 | [Packagist](https://packagist.org/packages/laravel/pail); [Laravel source](https://github.com/laravel/pail), lock source matches |
| `laravel/pint` 1.30.4 | Dev: PHP formatting | Nuno Maduro | 2022-06-22 | 16,550,627 | [Packagist](https://packagist.org/packages/laravel/pint); [Laravel source](https://github.com/laravel/pint), lock source matches |
| `mockery/mockery` 1.6.15 | Dev: test doubles | Pádraic Brady, Dave Marshall, Nathanael Esayeas | 2012-01-21 | 21,098,832 | [Packagist](https://packagist.org/packages/mockery/mockery); [Mockery source](https://github.com/mockery/mockery), lock source matches |
| `nunomaduro/collision` 8.9.5 | Dev: console error reporting | Nuno Maduro | 2017-10-08 | 18,777,370 | [Packagist](https://packagist.org/packages/nunomaduro/collision); [Maintainer source](https://github.com/nunomaduro/collision), lock source matches |
| `phpunit/phpunit` 11.5.57 | Dev: PHP test runner | Sebastian Bergmann | 2012-09-18 | 28,675,729 | [Packagist](https://packagist.org/packages/phpunit/phpunit); [PHPUnit source](https://github.com/sebastianbergmann/phpunit), lock source matches |

### npm

Six packages appear in `devDependencies`. `@laravel/multiplex` appears in `optionalDependencies`, so a production dependency install can include it; its role is console process coordination, not application browser code.

| Package and locked version | Scope and purpose | Registry maintainer handles | First published | Monthly downloads | Registry and source checks |
| --- | --- | --- | --- | ---: | --- |
| `@playwright/test` 1.62.1 | Dev: browser testing | pavelfeldman, yurys, dgozman-ms, microsoft-oss-releases, microsoft1es | 2020-09-24 | 396,881,992 | [npm](https://www.npmjs.com/package/@playwright/test); [Microsoft source](https://github.com/microsoft/playwright), lock integrity matches |
| `@tailwindcss/vite` 4.3.3 | Dev: CSS build integration | reinink, adamwathan, malfaitrobin | 2024-02-02 | 217,525,722 | [npm](https://www.npmjs.com/package/@tailwindcss/vite); [Tailwind Labs source](https://github.com/tailwindlabs/tailwindcss), lock integrity matches |
| `concurrently` 10.0.6 | Dev: development process coordination | kimmobrunfeldt, gustavohenke | 2015-02-07 | 105,734,872 | [npm](https://www.npmjs.com/package/concurrently); [Open CLI Tools source](https://github.com/open-cli-tools/concurrently), lock integrity matches |
| `laravel-vite-plugin` 3.2.0 | Dev: Laravel asset build integration | taylorotwell | 2022-05-19 | 10,141,502 | [npm](https://www.npmjs.com/package/laravel-vite-plugin); [Laravel source](https://github.com/laravel/vite-plugin), lock integrity matches |
| `tailwindcss` 4.3.3 | Dev: CSS build tooling | malfaitrobin, adamwathan, reinink | 2017-10-06 | 632,925,604 | [npm](https://www.npmjs.com/package/tailwindcss); [Tailwind Labs source](https://github.com/tailwindlabs/tailwindcss), lock integrity matches |
| `vite` 8.3.5 | Dev: asset bundler and development server | yyx990803, vitebot | 2020-04-21 | 936,639,586 | [npm](https://www.npmjs.com/package/vite); [Vite source](https://github.com/vitejs/vite), lock integrity matches |
| `@laravel/multiplex` 0.4.5 | Optional: console process coordination | taylorotwell, olivernybroe, joetannenbaum | 2026-08-05 | 1,966,628 | [npm](https://www.npmjs.com/package/@laravel/multiplex); [Laravel source](https://github.com/laravel/multiplex), lock integrity matches |

This inventory checks declared package identity and lockfile provenance. The reviewer did not audit each dependency's source code, reconstruct its build, verify release signatures, or verify all transitive publishers. Integrity matching detects disagreement with a registry snapshot; it does not protect against a compromised publisher or registry serving the same malicious artifact.

## Repeatable checks

Run these commands from the application checkout. They inspect dependencies without updating them:

```powershell
composer show --direct
composer audit --locked --format=json
composer audit --locked --no-dev --format=json
npm ls --depth=0
npm audit --json
npm audit --omit=dev --json
```

Keep both full and production-scope results. Composer's `--locked` selects the lockfile, and `--no-dev` excludes development packages. npm's `--omit=dev` provides the production comparison; it still includes applicable optional packages. Read the [Composer audit reference](https://getcomposer.org/doc/03-cli.md#audit) and [npm audit reference](https://docs.npmjs.com/cli/v11/commands/npm-audit/) for exit codes and current options. An unreachable registry or failed command is an incomplete scan. Avoid treating empty output as a clean result. `npm audit fix` performs an installation and does not belong in this read-only check.

Composer and npm were available on the audit host. `osv-scanner` and `trivy` were absent from PATH, and this review did not install or run them. The optional commands below install additional tools and are instructions, not completed checks. With Go installed, [OSV's installation instructions](https://google.github.io/osv-scanner/installation/) and [usage](https://google.github.io/osv-scanner/usage/) support:

```powershell
go install github.com/google/osv-scanner/v2/cmd/osv-scanner@latest
osv-scanner scan source -r .
```

For a portable Windows Trivy installation, the [official installation page](https://www.trivy.dev/docs/latest/getting-started/installation/) specifies its Windows ZIP. This example pins the version shown by that page at review time; review current provenance and checksums before executing the binary:

```powershell
Invoke-WebRequest https://github.com/aquasecurity/trivy/releases/download/v0.76.2/trivy_0.76.2_windows-64bit.zip -OutFile trivy-windows.zip
Expand-Archive -LiteralPath trivy-windows.zip -DestinationPath .tools/trivy
# Verify the release checksum/provenance before executing.
.tools/trivy/trivy.exe fs --scanners vuln,secret .
```

Gitleaks was initially absent from PATH; this audit downloaded a portable Gitleaks 8.30.1, verified its release checksum and ran redacted source/history scans. The separate repository workflow also uses a pinned Gitleaks container. Dependency advisory scans and secret scans cover different risks. Python/Ruby audit tools do not apply: there are no corresponding package manifests in this application.

## Native web stack requires a separate upgrade

The deployment review observed Apache 2.4.58, PHP 8.2.12, and OpenSSL 3.1.3 in the native web stack. Composer and npm do not maintain these binaries. Their clean dependency reports do not clear the host runtime.

The operator must plan a separate upgrade of the packaged web stack, check enabled Apache modules and PHP extensions, preserve private application data/configuration, and verify HTTPS, sign-in, upload limits, PDF delivery, and background workers after the upgrade. Review the vendor's [Apache 2.4 security notices](https://httpd.apache.org/security/vulnerabilities_24.html), [PHP support schedule](https://www.php.net/supported-versions.php), and [OpenSSL release strategy](https://openssl-library.org/policies/releasestrat/). Select supported patched releases compatible with the installed application and deployment package. This audit did not replace native binaries or change the running service.
