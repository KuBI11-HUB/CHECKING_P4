# GRANTED — Setup & Demo Script (Phases 1–3 + Phase 4 in progress)

## What's in this build
- Phase 1: login, roles, session security
- Phase 2: scholarship types, rules, scholar enrollment, grade entry,
  **document upload (scholar) + document review (admin)**
- Phase 3: the rule engine — evaluates GPA, UNIT_LOAD, and now DOCUMENT
  rules automatically, every time a grade is saved or a document is
  reviewed

- Phase 4 (in progress): everything is scoped to the **current term** set in
  `config/app.php`; notifications are saved in the `notifications` table and
  emailed through the Gmail API when `MAIL_ENABLED` is true; a daily script
  sends deadline reminders (`cron/send_deadline_reminders.php`).

DEADLINE rules still return PENDING always. Deadlines now live on DOCUMENT
rules (the optional "Document deadline" field) and are used by the reminder
script. Making a missed deadline FAIL a rule is task 4.2.

## Setup

The full step-by-step guide, with every table explained, is `docs/DATABASE_GUIDE.md`.
Short version:

1. Install XAMPP (PHP **8.1 or newer**) and start Apache + MySQL.
2. The project folder **must be named exactly `GRANTED`** and live in
   `C:\xampp\htdocs\` (links like `/GRANTED/admin/...` are written into the code).
3. In phpMyAdmin, **Import** `database/granted_schema.sql` (creates `granted_db` with all 9 tables).
   Already have the Phase 1-3 database? Import `database/phase4-5_migration.sql` instead.
4. Give the admin a password hash. In Command Prompt:
   ```
   C:\xampp\php\php.exe -r "echo password_hash('admin123', PASSWORD_DEFAULT);"
   ```
   then in phpMyAdmin's SQL tab:
   ```sql
   UPDATE users SET password_hash = 'PASTE_YOUR_HASH_HERE' WHERE email = 'admin@granted.local';
   ```
5. If your MySQL `root` user has a password, copy `config/database.local.example.php`
   to `config/database.local.php` and put the password there. **Never put a password in
   `config/database.php`**; that file is committed to GitHub.
6. Make sure `uploads/documents/` is writable (XAMPP usually is, by default).
7. Open http://localhost/GRANTED/auth/login.php

Optional (only for the person who sends real emails): `docs/GMAIL_API_SETUP.md`.
Everyone else leaves email off (the default) and the whole app still works.

## The demo script — follow this exact order

**1. Log in as admin** (`admin@granted.local` / `admin123`).

**2. Add two rules** for "Academic Scholarship": one GPA rule
(`<= 2.00`), and one DOCUMENT rule — for the DOCUMENT rule, set the
**threshold value to the exact document name**, e.g. `Certificate of Registration`.
This exact string is what the scholar will see on their checklist.

**3. Enroll a scholar**, enter a passing grade for them (e.g. `1.75`, 3 units).
Status should calculate to **PENDING** — not PASS — because the document
requirement hasn't been met yet. This is worth narrating out loud: it
shows the engine correctly waits on *every* rule, not just the numeric one.

**4. Log out, log in as that scholar.** Show the dashboard: GPA rule
shows PASS, but overall status is PENDING, with the Pending Checklist
card naming exactly which document is missing.

**5. Go to Upload Documents**, pick that document from the dropdown,
upload any PDF/JPG/PNG. Status shows "Pending Review" — not verified yet,
on purpose.

**6. Log out, log in as admin, go to Document Review.** Click **Verify**
on that document (add a remark first if you want to show that field
working). The moment you click it, the rule engine re-runs.

**7. Log back in as the scholar.** Status has flipped to **PASS** —
both rules now satisfied, checklist shows nothing outstanding.

**That full loop — grade entered, document uploaded, document verified,
status recalculating live at every step — is the strongest version of
the demo you can give right now.** It proves the engine really does wait
on every rule type, not just the easy numeric one.

## What to say if asked "what's next"
- Phase 4 remaining: deadline logic in the status calculation (4.2), scholar notification history page (4.6)
- Phase 5: results filters, per-scholar rule-by-rule page, renewal decisions, CSV export
- Phase 6-8: edit rules/types, grade import, security hardening, final testing

See `docs/PHASE4-5_DEV_BRIEF.md` for the plan.
