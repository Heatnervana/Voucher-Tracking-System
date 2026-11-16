# Office readiness work — 2026-11-12

## Approved scope

Complete the five readiness items: update the native server, investigate historical OCR failures, rehearse recovery, verify real-device and 5 GB uploads, and finish developer handoff with a reviewed local Git release. Preserve the shared office library and existing administrator/staff permissions. The administrator can use other authorized devices.

## Execution and acceptance

- [x] Inventory the current Apache/PHP/OpenSSL installation, extensions, configuration, scheduled tasks and private HTTPS paths without publishing secrets.
- [x] Obtain candidate Windows binaries from upstream-listed sources; verify published checksums and record versions/provenance. Stage separately from the current installation. The distributor's version gap and endpoint-protection block remain explicit release limits.
- [ ] Check syntax, PHP extensions and both command-line/web runtimes on loopback. Exercise sign-in, PDF/media paths, roles, uploads, OCR, backups and startup/rollback configuration on disposable data.
- [x] Investigate the 35 historical failed OCR jobs against current code and live read-only state. Reconcile only proven stale work; preserve useful failure evidence and avoid blindly retrying old payloads.
- [x] Create a fresh protected backup and restore a complete copy into an isolated environment. Verify every manifest checksum, SQLite integrity/relationships, accounts, saved PDFs and restored-app behavior. Preserve the original archive and live data.
- [ ] Run the existing actual 5,000,000,000-byte folder upload test, including nested entries, exact-limit behavior, finalization and PDF hash verification.
- [x] Test a physical phone and another office PC with the user, covering sign-in and multiple PDF pages. The user confirmed staff sign-in, search and PDF page-two access on both devices on November 16; see `RELEASE_READINESS.md` for the separate automated and restart evidence.
- [x] Write the developer guide and machine-specific settings catalogue, with source/workflow maps, business rules, database relationships, setup/tests and integration limits.
- [x] Review all pending changes, inspect upstream Git differences, run relevant regression/build/secret checks, and create a local release-candidate commit/tag with known limitations. Publishing is a separate action; see `RELEASE_READINESS.md` and tag `v0.1.0-rc.1`.
- [ ] Present the tested service-path changes and rollback instructions before a live native-server cutover. Apply only after approval; recheck private HTTPS, startup, OCR, backups and client access after switching.

## Isolation and rollback

Use separate testing directories, database/storage paths, browser profiles and loopback ports. The running installation stays in place during staging. Keep configuration/certificate/task backups private. Do not expose private PDFs, rotate the live application key, overwrite accounts, disable endpoint protection or change public access to make a test pass. Request Windows elevation only for the operation that needs it.

If staging fails, leave live services alone and report the concrete failure. If an approved cutover fails, restore the previous service/task paths and configuration, confirm the old HTTPS endpoints, and preserve the failed staged installation for diagnosis. A native upgrade does not imply public internet deployment.

## Evidence ownership

The recovery record covers backup/restore and failed-job analysis. The developer guide and office configuration catalogue cover handoff. Release notes will identify exact versions, commands/results, user device observations, remaining limitations, and the release commit/tag. Mark a check complete only after its evidence exists.
