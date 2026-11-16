# Security policy

Reviewed: 2026-11-12.

This application stores office voucher records and PDFs. Staff share one searchable library. Administrators manage uploads, records, accounts, and recovery. The existing office network and Tailscale deployment provide private access; this policy does not define a public deployment or a device-specific administrator restriction.

## Reporting a vulnerability

Use the repository's **Security → Report a vulnerability** action if the repository owner has enabled private vulnerability reporting. Otherwise, contact the repository owner or office administrator through an established private channel and request a secure way to transfer evidence. Do not publish credentials, PDFs, personal data, host addresses, or a working exploit in a public issue.

Include the affected revision, request or action, required account role, expected result, actual result, and a minimal reproduction using disposable data. Redact session cookies, CSRF tokens, passwords, keys, and document contents. The maintainers have not published response deadlines or a paid support commitment.

## Maintenance scope

Maintainers assess reports against the application's current maintained branch and its installed Laravel major version on a best-effort basis. This is not a promise to support historical releases or every runtime version allowed by Composer. Confirm versions with `composer show --direct`, `composer show laravel/framework`, `php --version`, and the web server's own runtime configuration before applying package guidance.

The 2026-11-12 review found Laravel 12.70.1 and a PHP 8.5.11 CLI. The office Apache and queue worker use a separate XAMPP PHP 8.2.12 runtime; the server reports Apache 2.4.58 and OpenSSL 3.1.3. An administrator must review supported vendor releases, compatibility, and rollback before replacing these native components. Updating Composer packages does not update Apache, PHP, OpenSSL, Tesseract, or Poppler.

## Security boundaries

- Authentication, CSRF checks, role gates, and forced temporary-password changes protect application routes. Staff access to the shared library is intentional; the application does not provide per-staff document ownership or tenant isolation.
- Saved and staged PDFs use the private local disk. Authenticated controller actions serve PDFs with the PDF content type. Do not expose private storage through a web alias or symbolic link.
- Browser responses use `private, no-store`, same-origin framing, MIME sniffing protection, and a CSP that restricts production assets and scripts to the same origin. Local Vite hot reload uses a development exception. Downloads and screenshots that a user saves remain outside browser cache controls.
- Password changes rotate the remember token, regenerate the current session, and revoke other database sessions. Role/account changes also revoke access. Legacy non-database sessions lack the same direct row deletion; authenticated-session hash checks apply once those sessions have recorded the prior password hash. Operators must assess existing sessions when changing session drivers.
- Application activity records include authentication and record-management events, PDF reads/downloads, and authorization denials. Read auditing does not record document content; successful GET deliveries include range requests, while HEAD probes are excluded. Logs do not prove that a person read or understood a document.
- The application uses local PDF text extraction and OCR. It does not send vouchers to an LLM or an external OCR service. Uploaded PDFs still reach native parsers and browser PDF viewers; those components need their own maintenance.

## Operational requirements

Keep `.env`, the application key, certificate private keys, database files, logs, and backups outside publicly served paths. Use Windows permissions to restrict them to the service identity and designated administrators. A backup includes the recovery environment and may therefore contain credentials and the application key. Store backups on a separate protected destination and verify recovery with disposable data.

Keep `APP_DEBUG=false` on deployed installations. Allow database/file writes only where the application and worker need them. Use HTTPS for sign-in and document access. Trust forwarded client-IP headers only from the configured loopback proxy; do not broaden proxy trust to arbitrary clients. Review certificates, failed jobs, failed backups, authorization denials, and uptime task failures through the office's existing administrative process.

## Dependency and secret checks

Run `composer audit` and `npm audit` against the committed lockfiles, review findings, and apply compatible fixes with the application tests. Review native runtime advisories separately. Run an approved secret scanner against both the working tree and available Git history. A clean scan covers the checked files and fetched commits, not unobserved remotes, deleted backups, or credentials distributed outside Git.

## Incident handling and rotation

1. Preserve relevant application and server logs in protected storage. Record the affected revision and time window. Avoid copying voucher contents into an incident ticket.
2. An administrator should contain affected accounts or network access through the established operational controls, revoke sessions, and rotate compromised account or service credentials. Replace credentials at the issuing service before removing the old values from configuration.
3. If a certificate private key leaked, obtain a replacement certificate/key and coordinate trust changes with the issuing authority. An office CA key leak needs a separate CA replacement and client trust review. Require administrator approval before changing installed certificates or trust stores.
4. Treat `APP_KEY` rotation as a planned recovery operation. First create a verified backup and inventory encrypted data, cookies, and stored secrets. Preserve the prior key in protected recovery material; assess `APP_PREVIOUS_KEYS` only if continued decryption is required. Retaining a compromised previous key also retains its risk. Invalidate sessions and remember tokens, test the transition in isolation, then approve the live change. Do not run `key:generate` as a routine update or restore step.
5. Rehearse recovery and confirm access controls before returning the installation to service. Database and session updates on different connections cannot provide one cross-database transaction; an operator must investigate partial failure rather than assume complete revocation.

See [setup and recovery procedures](docs/SETUP_AND_DEPLOYMENT.md) and the [architecture](docs/ARCHITECTURE.md). The security audit report records revision-specific findings, evidence, fixes, and remaining manual decisions.
