# GRANTED deployment checklist

This checklist is for preparing a controlled deployment. The local demo setup in the root `README.md` is not a production deployment procedure.

## Local demo preflight

For the disposable `granted_dev` database only, run the read-only preflight before recalculating assignments:

```powershell
C:\xampp81\php\php.exe .\tools\preflight.php
C:\xampp81\php\php.exe .\tools\reevaluate_all.php
```

The preflight checks the configured term, upload directory, demo PDF, required tables and active rules. It writes nothing to the database. The reevaluation tool updates assignment results but suppresses notifications and sends no email. Do not use these demo instructions as a production deployment procedure.

## Before deployment

- [ ] Confirm the current schema and migration path with the project owner. `database/granted_final_schema.sql` is for a fresh, empty database only.
- [ ] Never import `database/demo_seed.sql` or expose its demo accounts outside a disposable local database.
- [ ] Back up the target database and verify the backup can be restored before applying any approved schema change.
- [ ] Use a dedicated database account with only the required privileges; do not use the XAMPP `root` account in production.
- [ ] Store database credentials in a local, ignored config file or the deployment secret manager. Never commit credentials, Gmail tokens, or OAuth client secrets.
- [ ] Keep `MAIL_ENABLED` false unless the deployment owner has completed and verified the Gmail API setup.
- [ ] Install PHP 8.1+ and required Composer dependencies from the lock file where needed.
- [ ] Review file ownership and ensure `uploads/documents/` is writable by PHP but not directly served.

## Apache and application checks

- [ ] Enable `mod_rewrite` and `AllowOverride All` for the GRANTED directory so the repository `.htaccess` protections apply.
- [ ] Confirm the application is served under `/GRANTED/`; the app's route paths use that base path.
- [ ] Confirm requests to `/GRANTED/database/`, `/GRANTED/config/`, `/GRANTED/docs/`, `/GRANTED/includes/`, `/GRANTED/vendor/`, and `/GRANTED/uploads/documents/` are denied.
- [ ] Confirm the custom 403 and 404 pages are reachable and no directory listing is exposed.
- [ ] Configure `CURRENT_SCHOOL_YEAR`, `CURRENT_SEMESTER`, timezone, and base URLs for the deployment.
- [ ] Use HTTPS and verify session cookies are marked secure.
- [ ] Test admin and scholar authorization, upload/review, GWA entry, rule evaluation, CSV export, and password reset procedures with non-sensitive test data.
- [ ] Verify outgoing email only with an owner-approved sender account and a deliberate test recipient.
- [ ] Remove all demo accounts and synthetic data before opening access to real users.

## After deployment

- [ ] Verify application and PHP error logs contain no credentials or personal data.
- [ ] Confirm backups are scheduled, access-controlled, and periodically restore-tested.
- [ ] Document the database owner, deployment contact, and recovery procedure outside the public web root.
