# IceCashRec — Zimnat General Insurance Reconciliation System

A finance reconciliation platform that automates matching Icecash sales against bank receipts, produces formal per-agent statements, and gives managers full oversight of variances and escalations.

Built with **PHP 7.2**, **MySQL/MariaDB**, and **Bootstrap 3**. Runs on **XAMPP** with zero Composer dependencies.

---

## What It Does


Every month, Zimnat collects insurance premiums through Icecash terminals, bank POS devices, EcoCash, and brokers. This system:

1. **Ingests** sales reports (Icecash, Bordeaux, Zinara) and receipt files (bank statements, EcoCash exports)
2. **Matches** them using a 5-tier engine (exact reference, amount+date, fuzzy, split payments, batch settlements)
3. **Reports** variances per agent — who collected what, what's missing, what's over
4. **Issues** formal reconciliation statements with maker-checker approval
5. **Escalates** unresolved items to managers with full audit trail

---

## Quick Start

### Prerequisites

- **XAMPP** with PHP 7.2+ and MySQL/MariaDB (Apache + MySQL both running)
- A browser (Chrome, Edge, Firefox)

### Installation

```bash
# 1. Place the project folder
cp -r icecashRec/ C:/xampp/htdocs/icecashRec/

# 2. Create the database (single bundled installer — schema + seed + triggers)
mysql -u root < sql/install.sql

# 3. Start Apache + MySQL in XAMPP Control Panel

# 4. Open in browser
http://localhost/icecashRec/pages/login.php
```

> Existing installs upgrading from older revisions: run the per-feature
> migrations in `sql/` (`add_direction_column.sql`,
> `add_split_payments.sql`, `add_upload_flag_columns.sql`,
> `extend_audit_action_types.sql`, `relax_sale_uniqueness.sql`,
> `force_smart_engine.sql`) instead of re-running `install.sql`.
> Admins can run them from the in-app Database page (`/admin/db_admin.php`).

### Demo Accounts

| Username | Password | Role | What they can do |
|---|---|---|---|
| `farai.choto` | `manager2025` | Manager | Full oversight, escalations, statements, users |
| `tendai.moyo` | `recon2025` | Reconciler | Upload, reconcile, match, escalate |
| `upload.user` | `upload2025` | Uploader | Upload files only |
| `sys.admin` | `admin2025` | Admin | System config, user management, DB admin |

---

## User Roles

### Uploader
- Uploads sales and receipt files (CSV, XLS, XLSX, PDF)
- Sees only their own uploads
- Gets notified when a reconciler flags a file for correction

### Reconciler
- Runs reconciliation against uploaded data
- Reviews variances and unmatched transactions
- Manually matches receipts to sales or excludes noise
- Escalates issues to the Manager
- Issues draft reconciliation statements

### Manager
- Full oversight of all data, variances, and agents
- Reviews and finalizes statements (cannot finalize own drafts — maker-checker)
- Handles escalation queue (assign, review, resolve, dismiss)
- Manages users and reconciliation settings
- Sees dashboard charts and trends

### Admin
- Everything Manager can do (including escalations — Admin can break a
  deadlock when no Manager is available)
- Organization settings, session timeout, password policies
- System admin panel, database admin page (`/admin/db_admin.php`)
- Create/delete admin accounts

---

## System Architecture

```
Browser
  |
  v
[Apache + PHP 7.2]
  |
  +-- pages/          Login, logout, 2FA, password change
  +-- modules/        Dashboard, sales, receipts, variance, reconciliation, POS terminals
  +-- admin/          Users, agents, statements, escalations, unmatched, audit, settings
  +-- utilities/      Upload form, file library, file detail
  +-- process/        POST handlers (upload, reconcile, match, export, search, notifications)
  +-- core/           auth.php, db.php, config.php, ingestion.php, totp.php, notifications.php
  +-- layouts/        Shared header (navbar + sidebar) and footer (scripts + clock)
  +-- assets/css/     app.css (single stylesheet)
  +-- scripts/        Backup script, daily digest cron
  |
  v
[MySQL/MariaDB — icecash_recon database]
  20 tables: users, sales, receipts, agents, pos_terminals, reconciliation_runs,
  variance_results, statements, escalations, upload_history, audit_log, etc.
```

---

## Key Features

### File Ingestion
- Multi-file upload with drag-and-drop
- Supports CSV, XLS (binary BIFF8), XLSX (OOXML), and PDF
- Handles mislabeled files (HTML/XML disguised as .xlsx — common from banks)
- Smart header detection skips bank statement preamble rows
- Flexible column alias maps handle messy real-world headers
- SHA-256 deduplication prevents re-importing the same file
- Files go straight to database — no disk storage

### Reconciliation Engine (5 tiers)
1. **Exact match** — reference number + date proximity
2. **Amount + date + channel** — same amount, same day, same payment method
3. **Fuzzy** — amount within 0.5% tolerance, date +/-1 day
4. **Split payments** — multiple receipts that sum to one sale
5. **Batch settlements** — one bank receipt that covers many IceCash sales (reverse batch)

### Variance Triage
- **Ready to issue** — agents within tolerance, zero issues
- **Needs attention** — variance over threshold, unmatched items, FX flags
- **All agents** — full view with search and pagination

### Smart Amount Detection
- Separate Credit/Debit columns (Stanbic, NBS, NMB, First Capital)
- Cr/Dr direction flag (CBZ, EcoCash)
- Single Amount column with negative = debit
- All three patterns handled automatically

### Statements
- Draft → Final → Reviewed lifecycle
- Maker-checker: the person who generates cannot finalize
- Bulk issue from a reconciliation run
- Manager gets notified when drafts are ready

### Security
- CSRF tokens on every form
- Login rate limiting (5 failures → 15-min lock)
- Session hardening (HttpOnly, SameSite=Strict)
- Session regeneration on login
- Password expiry (90 days) + history (last 5 reuse blocked)
- Two-factor authentication (TOTP — Google Authenticator compatible)
- MIME validation on uploads
- Audit log immutability (DB triggers block UPDATE/DELETE)
- DB credentials externalized to config.php
- `.htaccess` files in `sql/`, `scripts/`, `backups/`, `exports/` deny
  direct web access (defense-in-depth — backups should also live
  outside the web root; see `scripts/backup_db.bat`)

### UX
- Global search across sales, receipts, agents, statements, escalations
- Export CSV on every table (respects current filters)
- Bulk exclude on unmatched transactions
- Per-item notification read tracking
- Dashboard charts (match rate trend, top agents by variance, sales by payment method)
- Responsive mobile layout
- Font Awesome 6 icons

---

## Database Tables

| Table | Purpose |
|---|---|
| `users` | Accounts, roles, login tracking |
| `agents` | Insurance agents/brokers/channels |
| `banks` | Bank registry |
| `pos_terminals` | POS devices linked to agents |
| `terminal_assignments` | Historical agent-terminal ownership |
| `sales` | Icecash/Zinara/PPA sales transactions |
| `receipts` | Bank/EcoCash payment receipts |
| `reconciliation_runs` | Run metadata and match statistics |
| `variance_results` | Per-agent variance from a run |
| `variance_by_channel` | Per-agent per-channel breakdown |
| `manual_match_log` | Audit trail for manual matches |
| `upload_history` | File upload records |
| `audit_log` | Immutable system audit trail |
| `escalations` | Variance/unmatched escalation queue |
| `statements` | Formal reconciliation statements |
| `system_settings` | Key-value system configuration |
| `user_preferences` | Per-user settings, 2FA secrets, notification prefs |
| `notification_queue` | Email queue with retry support |
| `password_history` | Password reuse prevention |
| `login_attempts` | Rate limiting by IP + username |

---

## Configuration

Key settings in Admin → Settings:

| Setting | Default | Purpose |
|---|---|---|
| `amount_tolerance_zwg` | 5 | ZWG rounding tolerance for matching |
| `amount_tolerance_usd` | 1 | USD rounding tolerance |
| `date_tolerance_days` | 1 | Date proximity for matching |
| `auto_escalate_threshold_zwg` | 10,000 | Auto-escalate variances above this |
| `session_timeout_hours` | 8 | Idle session timeout |
| `password_min_length` | 8 | Minimum password characters |

---

## Backup & Maintenance

### Database Backup
```bash
# Run manually
scripts/backup_db.bat

# Or schedule via Windows Task Scheduler (daily at 2 AM)
# Action: Start a program → c:\xampp\htdocs\icecashRec\scripts\backup_db.bat
```
Keeps daily backups for 30 days + monthly archives indefinitely.

### Restore
```bash
# 1. Stop Apache
# 2. Run:
mysql -u root icecash_recon < backups/icecash_YYYY-MM-DD.sql
# 3. Start Apache
```

### Daily Digest Email
```bash
# Schedule at 8 AM daily
php c:\xampp\htdocs\icecashRec\scripts\daily_digest.php
```
Sends one summary email per Manager with yesterday's uploads, runs, escalations.

### Email Queue
The notification outbox queues emails. To actually send them:
- **Manual**: Admin → Outbox → "Run Queue Now"
- **Cron**: `php process/email_queue_runner.php` (every 2 minutes)

---

## File Structure

```
icecashRec/
├── assets/
│   ├── css/app.css          # Single stylesheet (all styles)
│   └── img/zimnat logo.png  # Zimnat General Insurance logo
├── core/
│   ├── auth.php             # Authentication, sessions, CSRF, passwords
│   ├── config.php           # Database credentials (gitignored)
│   ├── db.php               # MySQL connection singleton
│   ├── ingestion.php        # File parsing + row insertion engine
│   ├── notifications.php    # Email queue helper
│   └── totp.php             # Two-factor authentication (TOTP)
├── layouts/
│   ├── layout_header.php    # Navbar, sidebar, search, notifications
│   └── layout_footer.php    # Footer, scripts, global search JS
├── modules/
│   ├── dashboard.php        # Role-adaptive dashboard with charts
│   ├── reconciliation.php   # Run launcher + results + history
│   ├── reconciliation_results.php  # Detailed run results
│   ├── sales.php            # Sales data browser
│   ├── receipts.php         # Receipts data browser
│   ├── variance.php         # Variance report with triage tabs
│   └── pos_terminals.php    # POS terminal registry
├── admin/
│   ├── agents.php           # Agent master data
│   ├── agent_detail.php     # Single agent detail
│   ├── audit.php            # Immutable audit log viewer
│   ├── escalations.php      # Manager escalation queue
│   ├── outbox.php           # Email notification queue
│   ├── settings.php         # System + user settings
│   ├── statements.php       # Statement index + bulk issue
│   ├── statement_detail.php # Single statement (printable)
│   ├── unmatched.php        # Unmatched transactions workbench
│   ├── users.php            # User management
│   └── admin_panel.php      # System admin dashboard
├── utilities/
│   ├── upload.php           # Multi-file upload form
│   ├── uploaded_files_list.php  # Upload library
│   └── uploaded_file_detail.php # Single upload detail
├── pages/
│   ├── login.php            # Login with demo account cards
│   ├── logout.php           # Session destruction
│   ├── change_password.php  # Forced password change
│   ├── setup_2fa.php        # TOTP enrollment
│   └── verify_2fa.php       # Post-login 2FA verification
├── process/                 # All POST/AJAX handlers
├── scripts/                 # Cron scripts (backup, digest)
├── sql/                     # Database schema + migrations
├── .htaccess                # Upload limits (50M per file)
├── .gitignore               # Excludes core/config.php
└── README.md                # This file
```

---

## Tech Stack

| Component | Version | Purpose |
|---|---|---|
| PHP | 7.2+ | Server-side logic |
| MySQL / MariaDB | 5.7+ / 10.1+ | Database |
| Bootstrap | 3.4.1 | Base CSS framework |
| Font Awesome | 6.5.1 | Icons |
| Chart.js | 3.9.1 | Dashboard charts |
| jQuery | 1.12.4 | Bootstrap dependency only |
| Apache | 2.4+ | Web server (via XAMPP) |

No Composer. No Node.js. No build step. Just PHP files and a database.

---

## License

Internal use only — Zimnat General Insurance.
