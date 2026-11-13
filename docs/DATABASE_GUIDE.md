# GRANTED — Database Guide

This guide describes the GWA-based schema used by the current application. It is not a migration plan for an existing database.

## Database setup warning

The application configuration currently points to `granted_dev`. Keep the demo database separate, and take a fresh backup before any schema change. Do not run a schema or migration against the demo.

`database/granted_final_schema.sql` creates the tables and seeds the currently confirmed scholarship types and document requirements. It does not create or select a database; select the disposable `granted_dev` database separately before using it. This is a fresh-install schema, not a migration for a populated database.
The current scholarship-type seeds are CHED, DOST, Mayor's Scholar, and TES. The requirement seeds are PSA and Proof of Residency (once-only), and Certificate of Grades (recurring). Add other approved types and requirements through the admin catalogues.

The older `database/granted_schema.sql` describes the previous GPA/grades design and is not the schema for the current application.

## The 13 tables

| Table | Purpose |
|---|---|
| `users` | Sign-in accounts and roles |
| `programs` | Admin-managed program catalogue |
| `scholarship_types` | Scholarship type catalogue |
| `requirements` | Reusable document requirement catalogue; `is_recurring` controls whether a verified document can count in later terms |
| `scholars` | Structured name and profile, program reference, cached current GWA and units |
| `scholarship_assignments` | One reusable scholar/type assignment with its own active lifecycle and calculated status |
| `rules` | GWA, unit-load, document, or deadline rules by scholarship type and term |
| `documents` | Scholar uploads, linked to a requirement where available, plus review state |
| `gwa_records` | One official GWA and unit total per scholar and term, linked to its source document and encoder |
| `evaluations` | Latest result for each scholar/rule pair |
| `renewal_decisions` | Office decision and remarks per assignment and term |
| `notifications` | Assignment status and deadline messages, including email delivery state |
| `login_attempts` | Login rate-limit tracking |

### Main relationships

- A scholar has one account and may have multiple scholarship assignments.
- Each assignment points to one scholarship type. Rules belong to scholarship types and apply to their active assignments.
- Requirements are shared by document rules and uploaded documents.
- A GWA record points to one scholar, term, verified Certificate of Grades document, and encoding admin.
- Renewal decisions and status notifications are assignment-specific.

## GWA and rule evaluation

Admins enter the official GWA and total units from a scholar's Certificate of Grades using **Enter GWA** or the GWA CSV importer. GRANTED does not calculate GWA from subject grades and does not backfill GWA from an old GPA.

A GWA record is usable only while its source document is **Verified**, belongs to the same scholar and term, and is identifiable as the exact `Certificate of Grades` requirement/document. The engine repeats this check when evaluating GWA rules. If the document is rejected later, the GWA is no longer usable and affected assignments are reevaluated.

The CSV requires these columns:

`email,school_year,semester,gwa,total_units`

The importer links the most recent verified Certificate of Grades for that scholar and term. Each scholar/term may have one GWA record; importing that same term again updates it. Use only values copied from the official document.

`scholars.current_gwa` and `current_units` are caches for the current configured term. The evaluator refreshes them from a usable GWA record. They are not independent source records.

Current term is configured by `CURRENT_SCHOOL_YEAR` and `CURRENT_SEMESTER` in `config/app.php`. After changing terms, run `C:\xampp\php\php.exe tools\reevaluate_all.php` to recalculate active assignments.

### Rule status behavior

- `GWA` compares the verified current-term GWA with the rule threshold.
- `UNIT_LOAD` compares `total_units` from that same currently verified, matching Certificate of Grades with the rule threshold.
- `DOCUMENT` passes when a matching document is Verified. Recurring requirements are term-scoped; non-recurring requirements can be reused across terms. Overdue missing documents fail; pending review remains pending.
- `DEADLINE` stays PENDING; document due dates drive the supported reminder behavior.
- If any current rule is pending, the assignment remains PENDING. Otherwise it passes only when every current rule passes; a failed rule yields FAIL. No active current-term rules means PENDING.

Only the rule engine writes `scholarship_assignments.status`. Office renewal decisions are separate and do not alter calculated status. `evaluations` stores the latest result per scholar/rule, not a term-by-term history.

## Data-entry and file notes

- Scholar names use `fname`, optional `mname`, `lname`, and optional `suffix`. Programs are selected from `programs`.
- Add only office-approved requirement names and scholarship rules. Document matching uses the requirement reference where present; legacy rows without one use exact document text.
- Uploaded file contents are stored under `uploads/documents/`; the database stores a randomized filename and review metadata.
- Admin results remain exportable as CSV. The old subject-grade import/edit workflow is retired; its links redirect to the GWA workflow.
- Do not write calculated statuses or GWA caches manually, and do not delete catalogue rows that are referenced by other records.
