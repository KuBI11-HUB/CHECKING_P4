# GRANTED — Gmail API Setup (Phase 4 Notifications)

How to make GRANTED send real emails through the **Gmail API**: status-change emails and deadline reminders. Written for Windows + XAMPP.

**Who does this:** one person only, the **mail owner**. Everyone else on the team leaves email switched off (`MAIL_ENABLED = false`) and develops normally; their notifications are still saved to the `notifications` table, just not emailed.

**Time:** about 45 minutes the first time.

**How it works, in one minute**
1. A Gmail account owned by the team is the **sender**.
2. You register a small "app" in Google Cloud and download its `gmail_credentials.json` (the app's ID card).
3. You open one page in GRANTED, sign in as the sender account, and approve "send email on your behalf". GRANTED saves a `gmail_token.json` (a long-lived pass).
4. From then on, GRANTED builds each email, hands it to the Gmail API with that pass, and records `SENT` or `FAILED` in the `notifications` table.

**What was checked:** the console steps follow Google's current documentation (the Google Auth Platform layout). The reference code uses method names confirmed in the Google PHP library's source. The code was tested here for message encoding, database recording, token refresh, and the authorization page's error handling, using stand-in Google classes. **It has not been run against the real Google servers**, so the first real email is your final test (Part C).

---

## Part 0 — Before you start

- [ ] **A team-owned Gmail account** to act as the sender (for example `granted.notifications@gmail.com`). Do **not** use anyone's personal account.
- [ ] That account has **2-Step Verification** and a **recovery email and phone** set up, and **at least two people** can get into it. Write the details somewhere the team can reach.
- [ ] XAMPP with **PHP 8.1 or newer** running (Apache and MySQL) and the project at `C:\xampp\htdocs\GRANTED`.
- [ ] The cleaned EMAIL_API work is merged into `master` and you have pulled it. It already contains the Gmail code, `config/app.php`, and `composer.json`.
- [ ] You can log in to GRANTED as admin.

---

## Part A — Google Cloud setup (in the browser)

Sign in at **https://console.cloud.google.com** with the **sender account**.

### A1. Create a project
Top bar → project picker → **New project** → name it `granted-notifications` → **Create**. Make sure it is the selected project in the top bar.

### A2. Turn on the Gmail API
Menu (☰) → **APIs & Services** → **Library** → search **Gmail API** → **Enable**.

### A3. Configure the consent screen
Menu (☰) → **Google Auth Platform** → click **Get started** (if you see "not configured yet").
1. **App name:** `GRANTED Notifications`. **User support email:** the sender account. Continue.
2. **Audience:** choose **External**. (*Internal* only works for Google Workspace organizations; a normal `@gmail.com` account can't use it.) Continue.
3. **Contact information:** the sender account's email. Continue.
4. Tick the agreement to the Google API Services User Data Policy → **Continue** → **Create**.

### A4. Add the one permission GRANTED needs
Google Auth Platform → **Data Access** → **Add or remove scopes**. Find **`.../auth/gmail.send`** ("Send email on your behalf"). If you can't find it in the list, paste `https://www.googleapis.com/auth/gmail.send` into **Manually add scopes** and click **Add to table**. Click **Update**, then **Save**.

Add **only** this scope. It lets the app send mail; it cannot read the inbox.

### A5. Publish the app. Do not skip this.
Google Auth Platform → **Audience** → **Publishing status** → **Publish app** → confirm. The status must say **In production**.

> **Why this matters:** while an External app is in **Testing**, Google expires every authorization **and its refresh token after 7 days**. Your emails would work for a week and then stop silently, possibly right before your defense. "In production" removes that limit.
>
> You will see an **"unverified app"** warning when you sign in. That's expected and fine for one sender account. **Don't** submit the app for verification.

### A6. Create the credentials
Google Auth Platform → **Clients** → **Create client**.
1. **Application type:** **Web application**.
2. **Name:** `GRANTED local`.
3. **Authorized redirect URIs** → **Add URI** → paste exactly:
   ```
   http://localhost/GRANTED/tools/gmail_authorize.php
   ```
4. **Create**, then click **Download JSON** in the dialog **right away**. (Newer consoles may not show the client secret again later; if you lose it, you can create a new secret.)
5. Rename the file to **`gmail_credentials.json`** and put it in `C:\xampp\htdocs\GRANTED\config\`.

> The redirect URI must match **character for character** what the code uses, including `http://localhost` (not `127.0.0.1`).

---

## Part B — Project setup (on the mail owner's computer)

### B1. Install Composer
Download the Windows installer from **https://getcomposer.org**. When it asks for the PHP location, choose `C:\xampp\php\php.exe`. Open a **new** Command Prompt and check:
```
composer --version
```

### B2. Install the Google library
`composer.json` and `composer.lock` are already in the repo (the Google library `^2.20`, which needs PHP 8.1 or newer). From the project folder run:
```
cd C:\xampp\htdocs\GRANTED
composer install
```
This creates the `vendor` folder. It is git-ignored and never committed. Every teammate who wants real emails runs the same command; everyone else can skip it.

### B3. The project files (already in the repo)
You don't copy anything: these are part of the project once the cleaned EMAIL_API work is merged.

| File | What it does |
|------|--------------|
| `includes/gmail_mailer.php` | Builds the email, loads and refreshes the token, sends through the Gmail API |
| `includes/notifications.php` | Saves each notification, emails it, records `SENT` or `FAILED`; never crashes the page |
| `tools/gmail_authorize.php` | One-time admin page that connects the sender account |
| `tools/gmail_test.php` | Command-line test: sends one real email |
| `cron/send_deadline_reminders.php` | The daily deadline-reminder script (Part C6) |
| `config/app.php` | Term settings and the mail settings (defaults to email **off**) |
| `config/gmail_credentials.example.json` | A safe template showing the file's shape |
| `config/app.local.example.php` | Template for your per-computer settings |
| `config/.htaccess` | Stops browsers from downloading anything in `config/` |

### B4. The mail settings
`config/app.php` already contains every mail setting with safe defaults (`MAIL_ENABLED` is `false`). You never edit that file for your own computer. Use `config/app.local.php` (next step) instead.

### B5. Turn email on, on your computer only
Copy `config/app.local.example.php` to **`config/app.local.php`** and set your real sender address:
```php
<?php
define('MAIL_ENABLED', true);
define('MAIL_FROM_EMAIL', 'granted.notifications@gmail.com');   // the account you authorize
```
`MAIL_FROM_EMAIL` must be the **same** Gmail account you sign in with in Part C; Gmail sends from the authenticated account.

Notification emails include a local sign-in button (`http://localhost:8081/GRANTED`) and an optional Cloudflare button. Add the current quick-tunnel base URL to `config/app.local.php`:
```php
define('GRANTED_PUBLIC_BASE_URL', 'https://YOUR-CURRENT-TUNNEL.trycloudflare.com/GRANTED');
```
Replace it whenever Cloudflare gives you a new quick-tunnel URL. The local button only works on the computer running XAMPP; the Cloudflare button works while that tunnel is running.

### B6. Keep secrets out of Git
The project's `.gitignore` already lists `config/gmail_credentials.json`, `config/gmail_token.json`, `config/app.local.php`, `config/database.local.php` and `vendor/`. Check it once: in GitHub Desktop, look at the **Changes** tab. You must **not** see `gmail_credentials.json`, `gmail_token.json`, `app.local.php`, or `vendor/` listed.

### B7. How the app finds the library
`includes/init.php` already loads the Google library when the `vendor` folder exists, and skips it when it doesn't. So teammates who never ran Composer can still run the whole app. Google's code is only needed when `MAIL_ENABLED` is true.

---

## Part C — Connect the sender account and test

### C1. Authorize (one time)
1. Start Apache and MySQL. Open **http://localhost/GRANTED/auth/login.php** and log in as **admin**.
2. In the same browser, open **http://localhost/GRANTED/tools/gmail_authorize.php**.
3. Always use `localhost` here, not `127.0.0.1`. The security check depends on the browser session cookie, which is different for the two addresses.
4. Sign in with the **sender account**.
5. Google shows **"Google hasn't verified this app"**. Click **Advanced** → **Go to GRANTED Notifications (unsafe)**. Then click **Continue** / **Allow** for "Send email on your behalf".
6. You land on **"Gmail is connected"**, and `config/gmail_token.json` now exists.

### C2. Send a real test email
```
cd C:\xampp\htdocs\GRANTED
C:\xampp\php\php.exe tools\gmail_test.php your.personal@example.com
```
Expected: `Sent. Gmail message id: …`. Check the inbox (and Spam). The message also shows in the sender account's **Sent** folder.

### C3. Check that credentials can't be downloaded
In the browser, open **http://localhost/GRANTED/config/gmail_token.json**. You must see **403 Forbidden**. If you see JSON text, `.htaccess` isn't active on your Apache. Fix that before this ever runs anywhere other than your own laptop. (XAMPP normally honors it.)

### C4. How it is wired in (nothing for you to do)
`evaluateScholar()` in `includes/rule_engine.php` remembers the scholar's old status, saves the new one, then calls `notifyStatusChange()`. That function does nothing if the status didn't change. Otherwise it saves a `STATUS_CHANGE` row in `notifications` and emails it. Any email failure is recorded as `FAILED` and **never** interrupts saving a grade or reviewing a document.

A status also changes when an admin adds or deactivates a rule, because every scholar on that scholarship type is re-evaluated. With `MAIL_ENABLED` on, those scholars are emailed too.

### C5. Test it end to end
1. Enroll a scholar whose login email is an address you can read. Add a GPA rule they will pass and enter a passing grade.
2. Their status changes `PENDING → PASS`, so you should receive the email within a few seconds.
3. In phpMyAdmin run `SELECT id, type, send_status, error_message FROM notifications ORDER BY id DESC;`. The newest row should say `SENT`.
4. Failure test: rename `config/gmail_token.json` to `gmail_token.json.bak`, trigger another status change. The grade must still save, and the row should say `FAILED` with an explanation. Rename the file back.

### C6. Schedule the daily reminders (Windows Task Scheduler)
1. Start menu → **Task Scheduler** → **Create Basic Task**. Name: `GRANTED deadline reminders`.
2. Trigger: **Daily**, at 8:00 AM.
3. Action: **Start a program**.
   - **Program/script:** `C:\xampp\php\php.exe`
   - **Add arguments:** `C:\xampp\htdocs\GRANTED\cron\send_deadline_reminders.php`
   - **Start in:** `C:\xampp\htdocs\GRANTED`
4. Finish. Then right-click the task → **Run** to test it.

**Who gets a reminder:** a scholar gets **one** reminder per document rule when the rule is active for the current term, its **Document deadline** is today or within the next `DEADLINE_REMINDER_DAYS` days (default 7), and the scholar's latest upload of that document is missing or Rejected. A document that is Verified, or uploaded and waiting for review, needs no reminder. A reminder that failed to send is retried on the next run.

**Testing with email off:** when `MAIL_ENABLED` is false the reminders are saved as `QUEUED` and never sent, and the script will not create them again. Before you go live, clear those test rows: `DELETE FROM notifications WHERE send_status = 'QUEUED';`

**Limits to know about:** the task only works if the laptop is on and XAMPP's **MySQL is running** at that time. For a defense or demo, run the script by hand beforehand (the same command in Command Prompt).

---

## Part D — Keeping it working

**Demo-day checklist (do it the day before)**
- [ ] XAMPP Apache + MySQL start without errors.
- [ ] `tools\gmail_test.php` sends a real email.
- [ ] `config/app.local.php` exists with `MAIL_ENABLED = true`.
- [ ] The Google Cloud app still says **In production**.
- [ ] Internet works on the demo computer (the Gmail API needs it).
- [ ] The Task Scheduler reminder task runs when you right-click → Run.

**Moving to another computer.** Copy `config/gmail_credentials.json`, `config/gmail_token.json` and `config/app.local.php` over a **private** channel (USB drive, or a password-protected file). Never GitHub, never a group chat. The token isn't tied to one machine.

**If sending suddenly stops.** Re-run Part C1. That creates a fresh token. Don't re-run it repeatedly or have every teammate authorize: Google limits how many tokens one account can hold for an app, and creating a new one eventually invalidates the oldest.

**Things that revoke the token.** Changing the sender account's password (Gmail permissions are revoked when it changes), removing the app at `https://myaccount.google.com/permissions`, or not using it for about six months. The daily reminder keeps it active.

**If a secret leaks** (committed to GitHub, posted in chat): in Google Cloud → Clients → open the client → add a new secret and disable the old one; remove the app at `https://myaccount.google.com/permissions`; run Part C1 again. Deleting the file from Git is not enough, because it stays in the history.

---

## Part E — Troubleshooting

| What you see | Cause | Fix |
|--------------|-------|-----|
| `Error 400: redirect_uri_mismatch` | The redirect URI in Google Cloud isn't identical to `GMAIL_REDIRECT_URI` | Fix A6 step 3 and `config/app.php`; they must match exactly, `http://localhost`, no trailing slash |
| "Security check failed" on the authorize page | You started on `127.0.0.1` or the session expired | Open `http://localhost/GRANTED/...`, log in again, restart from `tools/gmail_authorize.php` |
| `Error 403: access_denied` / "app not verified" with no way forward | App still in **Testing** and the account isn't a test user | Do A5 (Publish app) |
| `invalid_grant`, "Token has been expired or revoked" | Token expired (Testing mode: 7 days), password changed, or access removed | Do A5 if needed, then Part C1 again |
| "Google did not return a refresh token" | Google only hands one out on a first consent | Remove the app at `https://myaccount.google.com/permissions`, then run C1 again |
| `Request had insufficient authentication scopes` | Token was created without the `gmail.send` scope | Check A4, then redo C1 |
| `Gmail API has not been used in project … or it is disabled` | A2 skipped, or just enabled | Enable the API; wait a few minutes |
| `cURL error 60: SSL certificate problem` | Common on Windows XAMPP: PHP has no certificate list | Download `cacert.pem` from https://curl.se/docs/caextract.html, save it in `C:\xampp\php\extras\ssl\`, set `curl.cainfo` and `openssl.cafile` to that path in `C:\xampp\php\php.ini`, restart Apache |
| `Class "Google\Client" not found` | Composer wasn't run in the project folder, or `vendor/autoload.php` isn't loaded | B2 and B7; the folder must be `C:\xampp\htdocs\GRANTED` |
| `composer` is not recognized | The terminal was open before Composer installed | Open a new Command Prompt |
| Notification rows stay `QUEUED` | `MAIL_ENABLED` is false on that computer | Normal for teammates. Mail owner: check `config/app.local.php` |
| Rows say `FAILED` | The email is rejected, or the token is bad | Read `error_message` in the `notifications` table; match it to this table |
| Email lands in Spam | Brand-new sender, plain content | Mark "Not spam" once, and avoid ALL CAPS or links in subjects |
| Sending limit errors (`rateLimitExceeded`, daily limit) | Gmail limits how much one account can send per day | Wait a day. GRANTED only sends a few emails per status change or reminder |
| Browser can download `config/gmail_token.json` | `.htaccess` isn't active | See C3 |

---

## "Done when" checklist

- [ ] Google Cloud app is **In production** with only the `gmail.send` scope.
- [ ] `tools\gmail_test.php` delivers a real email.
- [ ] A status change in GRANTED produces a `SENT` row in `notifications` and a real email.
- [ ] With the token file removed, a status change still saves normally and produces a `FAILED` row.
- [ ] `http://localhost/GRANTED/config/gmail_token.json` returns **403**.
- [ ] GitHub Desktop never lists `gmail_credentials.json`, `gmail_token.json`, `app.local.php` or `vendor/`.
- [ ] The sender account can be recovered by at least two team members.
