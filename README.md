# GRANTED

GRANTED is a PHP and MySQL scholarship compliance and renewal-support app for the University of Luzon. The current build tracks scholarship assignments by type and uses official term GWA records; it does not calculate GPA from subject grades.

## Local XAMPP setup

This setup is for a disposable local development database only. Do not use the demo seed accounts or database on a production or publicly reachable installation.

1. Use PHP 8.1 or newer with XAMPP. Place the project at `C:\xampp81\htdocs\GRANTED`, then start Apache and MySQL.
2. In phpMyAdmin, create an empty `granted_dev` database using `utf8mb4`.
3. Select `granted_dev` and import **only** `database/granted_final_schema.sql`. Do not import `database/granted_schema.sql` or the historical migration files; they describe the earlier GPA/grades design.
4. Copy the clearly marked synthetic sample document into the protected uploads directory:

   ```powershell
   Copy-Item .\assets\demo-certificate-of-grades.pdf .\uploads\documents\demo-certificate-of-grades.pdf
   ```

5. Select `granted_dev` and import `database/demo_seed.sql`. This creates local-only demo accounts, two fictional scholars, current-term rules, and one verified sample GWA record.
6. Confirm `CURRENT_SCHOOL_YEAR` and `CURRENT_SEMESTER` in `config/app.php` match the term in `database/demo_seed.sql`. Email is disabled by default; leave `MAIL_ENABLED` set to `false`. `DEMO_MODE` is also off by default; set it to `true` in the ignored `config/app.local.php` only if you want the demo banner.
7. Run the read-only local preflight:

   ```powershell
   C:\xampp81\php\php.exe .\tools\preflight.php
   ```

8. Recalculate the seeded assignment statuses from the rule engine (this CLI tool sends no emails):

   ```powershell
   C:\xampp81\php\php.exe .\tools\reevaluate_all.php
   ```

9. Open [http://localhost/GRANTED/](http://localhost/GRANTED/).

If your local MySQL root account has a password, copy `config/database.local.example.php` to the ignored `config/database.local.php` and set `DB_PASS` there. Do not put computer-specific passwords in tracked configuration.

### Local demo accounts

These fixed credentials are for the disposable local demo database only. The seed resets these demo users' passwords when re-run. Never import this seed into a shared, public, or production database.

| Role | Email | Password |
|---|---|---|
| Admin | `admin.demo@example.test` | `DemoAdmin!2026` |
| Scholar | `alex.santos@example.test` | `ScholarDemo!2026` |
| Scholar | `mia.reyes@example.test` | `ScholarDemo!2026` |

The sample PDF and all names, email addresses, and academic figures are synthetic, not official University records.

## Suggested demo flow

1. Sign in as the admin and show the PASS and PENDING assignment statuses.
2. Sign in as `mia.reyes@example.test`; upload the sample PDF for the Certificate of Grades requirement.
3. Sign back in as the admin, verify Mia's uploaded document, then open **Enter GWA** and enter `1.75` GWA and `18` units for the current term.
4. Return to the dashboard and show the rule engine recalculating Mia's assignment.
5. Open a scholar account to show its checklist and assignment status.

The sample PDF is synthetic and for demonstrating the workflow only. Do not present it as a real academic record.

## Current product and database

- The current schema is `database/granted_final_schema.sql`; it is a fresh-install schema, not a migration for a populated database.
- `database/granted_schema.sql` and the numbered migration files are historical/legacy materials. Do not apply them to the demo database.
- See [docs/DATABASE_GUIDE.md](docs/DATABASE_GUIDE.md) for rule behavior and data relationships.
- See [docs/DEPLOY_CHECKLIST.md](docs/DEPLOY_CHECKLIST.md) before any deployment.
- The historical Phase 4-5 brief is not an implementation or setup guide for the current product.

## Verification

Lint changed PHP files with the PHP executable bundled with your XAMPP installation, for example:

```powershell
C:\xampp81\php\php.exe -l .\index.php
```

Mail sending is optional and disabled by default. Follow [docs/GMAIL_API_SETUP.md](docs/GMAIL_API_SETUP.md) only if a real Gmail integration is required; do not enable it for the local demo.
