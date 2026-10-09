# GRANTED — Developer Brief: Phase 4 and Phase 5

For the two developers. Phases 1–3 are done and working. This brief says what to build next, where in the code, and how to know it's finished.

**Read first (20 minutes):** `docs/DATABASE_GUIDE.md` (set up your local copy and learn the tables), `FEATURES.pdf` (the spec), and `README_PROTOTYPE.md` (the demo script — run it once so you know what "working" looks like).

**The one rule that overrides everything:** an admin never sets a scholar's PASS / FAIL / PENDING. Only the rule engine does, from data (grades, document verification, deadlines). The office's real decision is a separate record (`renewal_decisions`). Don't write to `scholars.current_status` from anywhere except `includes/rule_engine.php`.

---

## Status after the EMAIL_API cleanup

| Task | State |
|------|-------|
| 4.1 Current term | **Done.** `config/app.php` constants; rules, GPA, units, documents and checklist are scoped to the current term; stale results hidden; scholars re-evaluated when a rule is added or deactivated. `tools/reevaluate_all.php` refreshes everyone after the term changes. |
| 4.2 Deadlines on rules | **Partly done.** `rules.due_date` exists, the Rules form has a "Document deadline" field, and the list shows it. **Still to do:** show the due date on the scholar's checklist, the overdue logic (decision D1), and the rule-form usability fixes. |
| 4.3 Notification core | **Done.** Status changes are saved in `notifications` and emailed when `MAIL_ENABLED` is true. |
| 4.4 Gmail API | **Code done**, tested with stand-in Google classes. Still needs the one real send test (`docs/GMAIL_API_SETUP.md`, Part C). |
| 4.5 Deadline reminders | **Done** (`cron/send_deadline_reminders.php`, term-scoped, command-line only). Still to do: schedule it in Windows Task Scheduler. |
| 4.6 Notification history page | Not started. |
| Phase 5 | Not started. |

## 1. How we work in Git

- Repo is private. Never push straight to `main`.
- One branch per task, named like `phase4-term-config`, `phase4-notifications`, `phase5-results-csv`.
- Small commits with plain messages ("Add due_date field to rules form"). Pull `main` into your branch before you open a pull request. The project owner reviews and merges.
- **Secrets never go in Git:** no Gmail credentials, tokens, or passwords. **Never put a database password in `config/database.php`** (use the git-ignored `config/database.local.php`). The repo's `.gitignore` must contain the lines below (check yours matches). If a secret is ever committed by accident, tell the owner immediately; it must be revoked, not just deleted.
  ```
  uploads/documents/*
  !uploads/documents/.gitkeep
  config/gmail_credentials.json
  config/gmail_token.json
  config/app.local.php
  config/database.local.php
  vendor/
  Thumbs.db
  .DS_Store
  ```
- **The repo folder must be named `GRANTED`** and live at `C:\xampp\htdocs\GRANTED` (links like `/GRANTED/admin/...` are hardcoded).
- **The database is not in Git.** Every database change goes into a new numbered file in `database/` (for example `phase6_….sql`) **and** into `granted_schema.sql`, so a fresh install and "old schema + migrations" stay identical. Phase 4–5 already has its migration: `database/phase4-5_migration.sql` (run it once on your local database if it still has only 7 tables).

**Merge order:** first branch is `phase4-term-config` (task 4.1). Merge it before anyone else starts, because both phases use `config/app.php`. After that the two of you work in parallel.

**Suggested split:** Dev A takes 4.1, 4.2, 4.3, 4.5, 4.6. Dev B takes all of Phase 5, then 4.4 (Gmail API). Files both of you will touch, so expect to resolve small conflicts: `includes/rule_engine.php`, `includes/header.php` (nav links), `admin/dashboard.php`.

---

## 2. Decisions already made (confirm with the teacher; change only if told to)

| # | Question | Default we build |
|---|----------|------------------|
| D1 | A required document passes its deadline | **No upload at all after `due_date` → that rule FAILS.** Uploaded on time but still *Pending Review* → stays PENDING (the delay is the office's). Rejected → FAIL (as today). A late upload is reviewed like any other. |
| D2 | One rule failed, another still pending | Overall stays **PENDING** (current behavior). With D1, a missed deadline becomes a FAIL on its own. |
| D3 | Can scholars see the renewal decision? | **No.** Admin-only. FEATURES.pdf doesn't list it for scholars. |
| D4 | What does "GPA" and "unit load" cover? | **Current term only** (rules are per school year/semester). Today it averages every grade ever entered. |
| D5 | SMS | **Not built.** The `channel` column exists for later. |

---

## 3. Phase 4 — Deadlines and notifications

### 4.1 Current term setting (do this first)
Today the term is hardcoded in places and the engine ignores it.

- Create `config/app.php`:
  ```php
  define('CURRENT_SCHOOL_YEAR', '2026-2027');
  define('CURRENT_SEMESTER', '1st');          // '1st' | '2nd' | 'Summer'
  define('DEADLINE_REMINDER_DAYS', 7);
  define('MAIL_ENABLED', false);              // true only on a machine that has Gmail credentials
  ```
  Load it from `includes/init.php`.
- `scholar/upload_document.php` (around line 46) saves `'2026-2027', '1st'` literally. Use the constants.
- `includes/rule_engine.php`:
  - `evaluateScholar()` selects rules with only `scholarship_type_id` and `is_active`. Add `school_year` and `semester` = the current term.
  - `recalculateGpaAndUnits()` — only sum `grades` rows of the current term.
  - The DOCUMENT case of `evaluateRule()` and `getDocumentChecklist()` — match documents of the current term.
- Pre-fill school year and semester on the admin forms (`admin/rules.php`, `admin/enter_grades.php`) with the constants.
- **Stale results bug (verified):** the engine never deletes old rows from `evaluations`. If a rule is deactivated, or the term changes, its old result stays in the table. The query in `scholar/dashboard.php` that joins `evaluations` to `rules` has no `is_active` or term filter, so a deactivated rule's old FAIL still shows in the scholar's breakdown while the overall status ignores it. Fix it in the query: add `AND r.is_active = 1 AND r.school_year = ? AND r.semester = ?` (current term). The same filter is needed in the admin detail page (5.2). Don't fix it by deleting evaluation rows.
- **Deactivating a rule doesn't re-run the engine (verified).** `admin/rules.php` only runs `UPDATE rules SET is_active = 0`. Scholars on that scholarship type keep their old overall status (for example FAIL because of the rule that was just switched off) until the next grade save or document review happens to call `evaluateScholar()`. After the `UPDATE`, loop over the scholars with that rule's `scholarship_type_id` and call `evaluateScholar()` for each. The same applies later to creating and editing rules (Phase 6).

**Done when:** a scholar with only 1st-semester grades and `CURRENT_SEMESTER` changed to `'2nd'` shows no GPA and status PENDING. If no active rule exists for the current term, status is PENDING (never PASS). After deactivating a rule, it no longer appears in that scholar's breakdown, and that scholar's overall status updates immediately without anyone saving a grade.

### 4.2 Deadlines on rules
- `admin/rules.php`: add an optional **Due date** field (`rules.due_date`, already in the database). Save it, and show it in the rules list.
- Stop offering `DEADLINE` in the rule-type dropdown. Leave the database ENUM alone. Deadlines now live on DOCUMENT rules.
- **Make the rule form self-explanatory** (the team found it confusing). Today a DOCUMENT rule still shows an Operator dropdown defaulting to ">= (at least)", but the engine ignores the operator for documents, and the threshold placeholder only talks about GPA and units. When the rule type is DOCUMENT: hide the operator (save `==`), relabel the threshold "Document name (exact)", and show the Due date. When it is GPA or UNIT_LOAD: relabel the threshold "Minimum / maximum value" and **require a number**. Right now the server only checks that the field isn't empty, and the engine casts the text to a number, so a typo like `abc` silently becomes 0 and a GPA rule would always fail.
- `getDocumentChecklist()`: return `due_date` with each item. Scholar dashboard checklist: show the due date, plus "due in N days" or "OVERDUE".
- Engine, DOCUMENT case: implement D1.

**Done when:** a DOCUMENT rule due yesterday with no upload shows FAIL; due tomorrow with no upload shows PENDING; due yesterday with a *Pending Review* upload shows PENDING.

### 4.3 Notification core (status-change emails)
- New file `includes/notifications.php` with two functions:
  - `logNotification(PDO $pdo, array $data): int` — inserts into `notifications`, returns the id.
  - `sendNotificationEmail(PDO $pdo, int $notificationId): void` — if `MAIL_ENABLED` is false, leave the row `QUEUED` and return. Otherwise send through the Gmail API (4.4) and set `SENT` + `sent_at`, or `FAILED` + `error_message`.
- In `evaluateScholar()`: the function already loads the scholar row at the top, so `$scholar['current_status']` is the **old** status. Just before the `UPDATE scholars SET current_status`, compare it with `$overallStatus`. If they differ, log a `STATUS_CHANGE` notification (`old_status`, `new_status`, scholar's `users.email`) and send it.
- **Never let an email failure break the page.** Wrap sending in try/catch. Saving a grade or reviewing a document must still succeed if Gmail is down.
- No notification when old and new status are the same.

**Done when:** with `MAIL_ENABLED` false, saving a grade that flips PENDING→PASS creates exactly one `QUEUED` row with the right old/new status, and saving again creates none.

### 4.4 Gmail API sending
Spec: reCAPTCHA and **Gmail API** for notifications. Do not swap in SMTP/PHPMailer unless the teacher approves.

**Full step-by-step instructions are in `docs/GMAIL_API_SETUP.md`. Follow that file.** The code is already in the repo (`includes/gmail_mailer.php`, `includes/notifications.php`, `tools/gmail_authorize.php`, `tools/gmail_test.php`). Summary of the decisions already made there:

- The sender is a **team-owned Gmail account** that at least two people can recover, not someone's personal account.
- Google Cloud: enable the Gmail API, **External** audience, only the `gmail.send` scope, and a **Web application** OAuth client with the redirect URI `http://localhost/GRANTED/tools/gmail_authorize.php`.
- **Publish the app ("In production") before creating the token.** In "Testing" status Google expires the token after 7 days.
- Authorization is a one-time **admin-only web page** (`tools/gmail_authorize.php`), not a command-line script.
- `composer require google/apiclient`; commit `composer.json` and `composer.lock`; `vendor/` stays out of Git.
- `MAIL_ENABLED` is read from `config/app.php`, with per-computer overrides in the git-ignored `config/app.local.php`. Teammates who never set up Gmail keep it false and the app still runs (the Google library is only loaded if `vendor/` exists).
- Credentials, the token and `app.local.php` are never committed. `config/.htaccess` blocks browser access to them.
- The password of the sender account must not be changed casually: it revokes the Gmail token (re-run the authorize page if it happens).

Only one machine needs real credentials. The other dev keeps `MAIL_ENABLED` false and still develops everything else.

**Done when:** every box in the "Done when" checklist at the end of `docs/GMAIL_API_SETUP.md` is ticked: one real email arrives for a status change, and a deliberately broken token produces a `FAILED` row with an `error_message` and no crash.

### 4.5 Deadline reminders (scheduled script)
- New CLI script `cron/send_deadline_reminders.php`. Guard it: `if (php_sapi_name() !== 'cli') { exit; }` so nobody can trigger it from a browser.
- Use this query (tested against the schema; replace the three literals with the constants):
  ```sql
  SELECT s.id AS scholar_id, s.full_name, u.email,
         r.id AS rule_id, r.threshold_value AS document_type, r.due_date
  FROM rules r
  JOIN scholars s ON s.scholarship_type_id = r.scholarship_type_id
  JOIN users u ON u.id = s.user_id
  WHERE r.rule_type = 'DOCUMENT' AND r.is_active = 1
    AND r.school_year = '2026-2027' AND r.semester = '1st'
    AND r.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    AND NOT EXISTS (SELECT 1 FROM documents d
                    WHERE d.scholar_id = s.id AND d.document_type = r.threshold_value
                      AND d.school_year = r.school_year AND d.semester = r.semester
                      AND d.status IN ('Verified', 'Pending Review'))
    AND NOT EXISTS (SELECT 1 FROM notifications n
                    WHERE n.scholar_id = s.id AND n.rule_id = r.id
                      AND n.type = 'DEADLINE_APPROACHING');
  ```
  It returns a scholar only when the document is still outstanding (missing or rejected), the due date is within the window, and no reminder was sent for that rule yet. For each row, `logNotification()` with `type = 'DEADLINE_APPROACHING'` and the `rule_id`, then send.
- Schedule it daily with **Windows Task Scheduler**, action: `C:\xampp\php\php.exe C:\xampp\htdocs\GRANTED\cron\send_deadline_reminders.php`.

**Done when:** running the script twice in a row sends one reminder, not two; a scholar whose document is Verified or Pending Review gets none; a rule due in 10 days or already past gets none.

### 4.6 Notification history page (scholar)
- New `scholar/notifications.php`, plus a nav link in `includes/header.php` (the scholar links are next to "My Status" and "Upload Documents").
- Guard it exactly like `scholar/dashboard.php` (copy its first lines; `includes/auth_check.php` reads `$requiredRole`).
- Query by the **logged-in scholar's own id from the session**. No `?id=` in the URL, or one scholar could read another's messages.
- Show date, subject, type, and whether it was sent. Do not show `error_message` to scholars. Newest first.

**Done when:** scholar A sees only A's notifications, and an admin who opens the URL gets the 403 page.

### Phase 4 test checklist
- [ ] Fresh database + `config/app.php` → app still logs in and runs the README demo flow.
- [ ] 4.1, 4.2, 4.3, 4.5, 4.6 "Done when" lines all pass.
- [ ] Gmail send works once for real (4.4).
- [ ] Saving a grade with Gmail broken still saves the grade.
- [ ] No credential file appears in `git status`.

---

## 4. Phase 5 — Admin results and renewal decisions

### 5.1 Filters on the results table
- Add filters for **program**, **status** (PASS/FAIL/PENDING), and **scholarship type** to the admin results table on `admin/dashboard.php`, using GET parameters so a filtered view can be bookmarked. Add a "Clear filters" link.
- Fill the program dropdown from `SELECT DISTINCT program FROM scholars`.
- Build the query with prepared statements and only add a `WHERE` condition for filters that were chosen.
- Put the query in one shared function (for example `includes/results.php` → `getResults(PDO $pdo, array $filters)`) — 5.4 must reuse it so the export always matches what's on screen.

**Done when:** each filter works alone and combined; an empty result shows a friendly "No scholars match" row.

### 5.2 Scholar detail page (rule-by-rule)
- New `admin/scholar_detail.php?id=…` (admin-only). Link each name in the results table to it.
- Show: profile, calculated status, GPA and units, the **rule-by-rule breakdown** (`evaluations` joined to `rules`: rule type, operator, threshold, result), current-term grades, documents with status, and the renewal decision form (5.3).
- Handle an id that doesn't exist (show "Scholar not found", don't crash).
- Only list rules that are active and belong to the current term (see the stale-results note in 4.1).

**Done when:** the breakdown on this page matches what that scholar sees on their own dashboard, including after a rule has been deactivated.

### 5.3 Renewal decision record
- Form on the detail page: **Decision** (`RENEWED`, `NOT_RENEWED`, `ON_HOLD`), **Remarks**, for the current term (from `config/app.php`).
- Save with an upsert (tested). `calculated_status_at_decision` is a snapshot of `scholars.current_status` at that moment; `decided_by` is the admin's `users.id` from the session:
  ```sql
  INSERT INTO renewal_decisions
    (scholar_id, school_year, semester, decision, remarks, calculated_status_at_decision, decided_by)
  VALUES (?, ?, ?, ?, ?, ?, ?)
  ON DUPLICATE KEY UPDATE decision = VALUES(decision), remarks = VALUES(remarks),
    calculated_status_at_decision = VALUES(calculated_status_at_decision),
    decided_by = VALUES(decided_by);
  ```
- Show the **calculated status and the office decision side by side** on the detail page and as a column in the results table (`LEFT JOIN renewal_decisions` for the current term; a scholar with no decision yet shows "—").
- This code must **not** touch `scholars.current_status`. Scholars have no page that shows this record (D3).

**Done when:** saving twice for the same term leaves one row, updated; `scholars.current_status` is unchanged before and after; a decision of `RENEWED` on a FAIL scholar is allowed and both values display.

### 5.4 CSV export
- New `admin/export_results.php` (admin-only). It takes the same filter parameters as 5.1 and calls the same `getResults()` function.
- Columns: Name, Email, Program, Year level, Scholarship type, GPA, Units, Calculated status, Office decision, Decision remarks.
- Use `header('Content-Type: text/csv; charset=utf-8')`, `Content-Disposition: attachment; filename="granted_results_YYYY-MM-DD.csv"`, `fputcsv()`, and write a UTF-8 BOM first so Excel opens accents correctly.
- **Spreadsheet-formula safety:** if a cell's text starts with `=`, `+`, `-` or `@`, prefix it with a single quote. Names and remarks are user-typed.
- **Known bug to work around:** the app currently stores text already HTML-escaped (`O'Brien` is saved as `O&#039;Brien`). Run `html_entity_decode()` on text cells in the export for now. The real fix is planned for Phase 7.
- Add an "Export CSV" button next to the filters that carries the current filter values.

**Done when:** exporting with "status = PASS" produces only PASS rows; the file opens in Excel with correct columns; a scholar named `=1+1` appears as text, not as a formula.

### Phase 5 test checklist
- [ ] Filters, detail page, decision form and CSV "Done when" lines pass.
- [ ] A scholar logged in cannot open `admin/scholar_detail.php` or `admin/export_results.php` (403).
- [ ] Recording a decision never changes the calculated status.

---

## 5. Out of scope for now (don't build, don't "just fix")

Edit scholarship types and rules, grade CSV import and grade edit/delete (Phase 6); reCAPTCHA, CSRF, upload hardening, the text-escaping fix, removing `generate_password_hash.php` (Phase 7); final test pass and documentation (Phase 8); SMS. If you spot a bug outside your task, write it down for the owner instead of fixing it in your branch.

## 6. What to demo when both phases are merged

1. Admin sets a DOCUMENT rule with a due date a few days out.
2. Scholar sees the due date on the checklist; the reminder script sends one email; running it again sends none.
3. Admin enters a grade → scholar's status changes → email arrives and appears in the scholar's notification history.
4. Admin filters the results by status, opens a scholar's rule-by-rule breakdown, records an office decision that differs from the calculated status, and exports the filtered table to CSV.
