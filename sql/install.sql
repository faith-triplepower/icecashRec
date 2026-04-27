-- ============================================================
-- install.sql — Single-file installer for IceCashRec
--
-- Runs on a fresh MySQL/MariaDB instance and produces the full
-- schema + seed data + audit triggers ready for a first login.
-- Bundles every column and table that's been added incrementally
-- via the per-feature migration files in this folder, so a fresh
-- install never has to chase migrations.
--
-- Usage:
--   mysql -u root < sql/install.sql
--
-- Idempotent in spirit: CREATE DATABASE IF NOT EXISTS, IF NOT EXISTS
-- on tables, INSERT IGNORE on seed rows. Safe to re-run.
-- ============================================================

CREATE DATABASE IF NOT EXISTS icecash_recon
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE icecash_recon;

-- ── 1. USERS ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    full_name     VARCHAR(100) NOT NULL,
    email         VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('Manager','Reconciler','Uploader','Admin') NOT NULL DEFAULT 'Uploader',
    initials      VARCHAR(5)   NOT NULL DEFAULT '',
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    last_login    DATETIME     NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO users (username, full_name, email, password_hash, role, initials) VALUES
('farai.choto', 'Farai Choto', 'farai.choto@zimnat.co.zw',
 '$2y$10$l9wZuG2/xYBhnoT3KSINkeu/FjqC4hMl6jetzeBNSNkHiqZFHfEuy', 'Manager',    'FC'),
('tendai.moyo', 'Tendai Moyo', 'tendai.moyo@zimnat.co.zw',
 '$2y$10$pHppO7j3PXG9ia/LFD7qRu5z83/i9ns4i5.i/mLb2XW4ToTOn0lM2', 'Reconciler', 'TM'),
('upload.user', 'Upload User', 'uploader@zimnat.co.zw',
 '$2y$10$.cL1CzNV7O1m7bY5/4vpSeQZmu/FRRWBujU4TL.yO4ValXvMjA2jC', 'Uploader',   'UU'),
('sys.admin',   'System Administrator', 'admin@zimnat.co.zw',
 '$2y$10$Kxuqmf/cHkzOfAcebg.C.u2kdALWKTDn061NiOaVib5MmbO0U1MRW', 'Admin',      'SA');
-- Default passwords (change on first login):
--   farai.choto = manager2025
--   tendai.moyo = recon2025
--   upload.user = upload2025
--   sys.admin   = admin2025

-- ── 2. AGENTS ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS agents (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    agent_code  VARCHAR(20)  NOT NULL UNIQUE,
    agent_name  VARCHAR(100) NOT NULL,
    agent_type  ENUM('iPOS','POS Terminal','Broker','EcoCash') NOT NULL,
    region      VARCHAR(50)  NOT NULL,
    currency    ENUM('ZWG','USD','ZWG/USD') NOT NULL DEFAULT 'ZWG',
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO agents (agent_code, agent_name, agent_type, region, currency) VALUES
('AGT-001', 'Harare Central',  'iPOS',         'Harare',      'ZWG/USD'),
('AGT-002', 'Bulawayo Zone A', 'POS Terminal', 'Bulawayo',    'ZWG'),
('AGT-003', 'Mutare Agency',   'Broker',       'Manicaland',  'ZWG'),
('AGT-004', 'Gweru Branch',    'iPOS',         'Midlands',    'ZWG'),
('AGT-005', 'Chiredzi Motors', 'POS Terminal', 'Masvingo',    'ZWG'),
('AGT-006', 'Kwekwe PPA',      'Broker',       'Midlands',    'ZWG'),
('AGT-007', 'Bindura iPOS',    'iPOS',         'Mashonaland', 'ZWG/USD'),
('AGT-008', 'Masvingo Broker', 'Broker',       'Masvingo',    'ZWG');

-- ── 3a. BANKS ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS banks (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    bank_name  VARCHAR(100) NOT NULL UNIQUE,
    bank_code  VARCHAR(20)  NULL,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO banks (bank_name, bank_code) VALUES
  ('CBZ Bank', 'CBZ'),
  ('Stanbic',  'STAN'),
  ('FBC Bank', 'FBC');

-- ── 3. POS TERMINALS ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS pos_terminals (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    terminal_id   VARCHAR(30)  NOT NULL UNIQUE,
    merchant_name VARCHAR(100) NOT NULL,
    agent_id      INT          NOT NULL,
    bank_id       INT          NULL,
    bank_name     VARCHAR(50)  NOT NULL,
    location      VARCHAR(100) NOT NULL,
    currency      ENUM('ZWG','USD','ZWG/USD') NOT NULL DEFAULT 'ZWG',
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    last_txn_at   DATETIME     NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bank (bank_id),
    FOREIGN KEY (agent_id) REFERENCES agents(id),
    FOREIGN KEY (bank_id)  REFERENCES banks(id)
);

-- ── 3b. TERMINAL ASSIGNMENT HISTORY ────────────────────────
CREATE TABLE IF NOT EXISTS terminal_assignments (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    terminal_id INT          NOT NULL,
    agent_id    INT          NOT NULL,
    valid_from  DATE         NOT NULL,
    valid_to    DATE         NULL,
    changed_by  INT          NULL,
    reason      VARCHAR(255) NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_terminal (terminal_id, valid_from),
    INDEX idx_agent (agent_id),
    FOREIGN KEY (terminal_id) REFERENCES pos_terminals(id) ON DELETE CASCADE,
    FOREIGN KEY (agent_id)    REFERENCES agents(id),
    FOREIGN KEY (changed_by)  REFERENCES users(id)
);

INSERT IGNORE INTO pos_terminals (terminal_id, merchant_name, agent_id, bank_name, location, currency, last_txn_at) VALUES
('CBZ-POS-0042', 'Harare Central Branch',   1, 'CBZ Bank', 'Harare CBD',       'ZWG',     '2025-06-17 14:32:00'),
('CBZ-POS-0019', 'Harare Central Branch 2', 1, 'CBZ Bank', 'Harare Avondale',  'ZWG',     '2025-06-17 11:20:00'),
('STAN-POS-019', 'Stanbic Bulawayo',        2, 'Stanbic',  'Bulawayo CBD',     'ZWG',     '2025-06-17 10:45:00'),
('STAN-POS-014', 'Stanbic Gweru',           4, 'Stanbic',  'Gweru CBD',        'ZWG',     '2025-06-17 09:12:00'),
('CBZ-POS-0055', 'CBZ Mutare',              3, 'CBZ Bank', 'Mutare CBD',       'ZWG',     '2025-06-16 15:00:00'),
('FBC-POS-001',  'FBC Chiredzi',            5, 'FBC Bank', 'Chiredzi',         'ZWG',     '2025-06-17 13:00:00'),
('FBC-POS-002',  'FBC Chiredzi 2',          5, 'FBC Bank', 'Chiredzi Market',  'ZWG',     '2025-06-17 12:30:00'),
('STAN-POS-022', 'Stanbic Bindura',         7, 'Stanbic',  'Bindura',          'ZWG/USD', '2025-06-17 08:55:00'),
('CBZ-POS-0081', 'CBZ Masvingo',            8, 'CBZ Bank', 'Masvingo CBD',     'ZWG',     '2025-06-10 17:00:00'),
('CBZ-POS-0044', 'CBZ Harare 3',            1, 'CBZ Bank', 'Harare Glen View', 'ZWG',     '2025-06-15 12:00:00');

-- ── 4. SALES ────────────────────────────────────────────────
-- policy_number is NOT globally unique (renewals/top-ups create fresh
-- rows with the same policy). Real uniqueness is per (policy, txn_date,
-- source_system) — see uk_sale_txn below.
CREATE TABLE IF NOT EXISTS sales (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    policy_number  VARCHAR(30)   NOT NULL,
    reference_no   VARCHAR(100)  NULL,
    txn_date       DATE          NOT NULL,
    agent_id       INT           NOT NULL,
    terminal_id    VARCHAR(30)   NULL,
    product        ENUM('Zinara','PPA') NOT NULL,
    payment_method ENUM('iPOS','Bank POS','EcoCash','Zimswitch','Broker') NOT NULL,
    amount         DECIMAL(15,2) NOT NULL,
    currency       ENUM('ZWG','USD') NOT NULL DEFAULT 'ZWG',
    source_system  ENUM('Icecash','Bordeaux','Zinara') NOT NULL DEFAULT 'Icecash',
    currency_flag  TINYINT(1)    NOT NULL DEFAULT 0,
    -- Split-payment status: rolled up from receipts allocations.
    paid_status    ENUM('unpaid','partial','paid','overpaid','currency_review') NOT NULL DEFAULT 'unpaid',
    upload_id      INT           NULL,
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_policy (policy_number),
    INDEX idx_txn_date (txn_date),
    INDEX idx_paid_status (paid_status),
    UNIQUE KEY uk_sale_txn (policy_number, txn_date, source_system),
    FOREIGN KEY (agent_id) REFERENCES agents(id)
);

-- ── 5. RECEIPTS ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS receipts (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    reference_no     VARCHAR(50)   NOT NULL UNIQUE,
    txn_date         DATE          NOT NULL,
    terminal_id      VARCHAR(30)   NULL,
    channel          ENUM('Bank POS','iPOS','EcoCash','Zimswitch','Broker') NOT NULL,
    source_name      VARCHAR(100)  NOT NULL,
    amount           DECIMAL(15,2) NOT NULL,
    direction        ENUM('credit','debit') NOT NULL DEFAULT 'credit',
    currency         ENUM('ZWG','USD') NOT NULL DEFAULT 'ZWG',
    matched_policy   VARCHAR(30)   NULL,
    matched_sale_id  INT           NULL,
    match_status     ENUM('matched','pending','variance','excluded','partial','currency_review') NOT NULL DEFAULT 'pending',
    match_confidence ENUM('high','medium','low','manual') NULL,
    exclude_reason   VARCHAR(50)   NULL,
    exclude_note     VARCHAR(500)  NULL,
    excluded_by      INT           NULL,
    excluded_at      DATETIME      NULL,
    upload_id        INT           NULL,
    created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_match_status (match_status),
    INDEX idx_matched_sale (matched_sale_id),
    INDEX idx_matched_sale_status (matched_sale_id, match_status),
    INDEX idx_receipts_direction (direction, match_status),
    FOREIGN KEY (excluded_by) REFERENCES users(id)
);

-- ── 6. RECONCILIATION RUNS ──────────────────────────────────
CREATE TABLE IF NOT EXISTS reconciliation_runs (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    period_label        VARCHAR(30)   NOT NULL,
    product             VARCHAR(30)   NOT NULL DEFAULT 'All Products',
    agent_id            INT           NULL,
    date_from           DATE          NOT NULL,
    date_to             DATE          NOT NULL,
    period_type         ENUM('Daily','Monthly') NOT NULL DEFAULT 'Monthly',
    opt_terminal        TINYINT(1)    NOT NULL DEFAULT 1,
    opt_ecocash         TINYINT(1)    NOT NULL DEFAULT 1,
    opt_flag_fx         TINYINT(1)    NOT NULL DEFAULT 1,
    opt_bordeaux        TINYINT(1)    NOT NULL DEFAULT 1,
    opt_date_tol        TINYINT(1)    NOT NULL DEFAULT 0,
    run_status          ENUM('running','complete','failed','superseded') NOT NULL DEFAULT 'running',
    progress_pct        TINYINT       NULL DEFAULT 0,
    progress_msg        VARCHAR(200)  NULL,
    run_by              INT           NOT NULL,
    started_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at        DATETIME      NULL,
    total_sales         INT           NULL,
    total_receipts      INT           NULL,
    matched_count       INT           NULL,
    match_rate          DECIMAL(5,2)  NULL,
    fx_flagged          INT           NULL,
    unmatched_sales     INT           NULL,
    unmatched_receipts  INT           NULL,
    total_variance_zwg  DECIMAL(15,2) NULL,
    total_variance_usd  DECIMAL(15,2) NULL,
    FOREIGN KEY (run_by) REFERENCES users(id)
);

-- ── 7. VARIANCE RESULTS ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS variance_results (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    run_id         INT           NOT NULL,
    agent_id       INT           NOT NULL,
    sales_zwg      DECIMAL(15,2) NOT NULL DEFAULT 0,
    sales_usd      DECIMAL(15,2) NOT NULL DEFAULT 0,
    receipts_zwg   DECIMAL(15,2) NOT NULL DEFAULT 0,
    receipts_usd   DECIMAL(15,2) NOT NULL DEFAULT 0,
    variance_zwg   DECIMAL(15,2) NOT NULL DEFAULT 0,
    variance_usd   DECIMAL(15,2) NOT NULL DEFAULT 0,
    variance_cat   VARCHAR(50)   NULL,
    recon_status   ENUM('reconciled','variance','pending') NOT NULL DEFAULT 'pending',
    FOREIGN KEY (run_id)   REFERENCES reconciliation_runs(id),
    FOREIGN KEY (agent_id) REFERENCES agents(id)
);

-- ── 7b. VARIANCE BY CHANNEL ─────────────────────────────────
CREATE TABLE IF NOT EXISTS variance_by_channel (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    run_id       INT NOT NULL,
    agent_id     INT NOT NULL,
    channel      ENUM('Bank POS','iPOS','EcoCash','Zimswitch','Broker') NOT NULL,
    sales_zwg    DECIMAL(15,2) NOT NULL DEFAULT 0,
    sales_usd    DECIMAL(15,2) NOT NULL DEFAULT 0,
    receipts_zwg DECIMAL(15,2) NOT NULL DEFAULT 0,
    receipts_usd DECIMAL(15,2) NOT NULL DEFAULT 0,
    variance_zwg DECIMAL(15,2) NOT NULL DEFAULT 0,
    variance_usd DECIMAL(15,2) NOT NULL DEFAULT 0,
    INDEX idx_run_agent (run_id, agent_id),
    FOREIGN KEY (run_id) REFERENCES reconciliation_runs(id) ON DELETE CASCADE,
    FOREIGN KEY (agent_id) REFERENCES agents(id)
);

-- ── 7c. MANUAL MATCH LOG ────────────────────────────────────
CREATE TABLE IF NOT EXISTS manual_match_log (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    run_id     INT          NULL,
    receipt_id INT          NOT NULL,
    sale_id    INT          NULL,
    action     ENUM('match','unmatch') NOT NULL,
    reason     VARCHAR(255) NULL,
    user_id    INT          NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_receipt (receipt_id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ── 8. UPLOAD HISTORY ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS upload_history (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    filename       VARCHAR(255)  NOT NULL,
    file_type      ENUM('Sales','Receipts') NOT NULL,
    report_type    VARCHAR(100)  NOT NULL,
    source_name    VARCHAR(100)  NOT NULL,
    record_count   INT           NULL,
    period_from    DATE          NULL,
    period_to      DATE          NULL,
    uploaded_by    INT           NOT NULL,
    validation_msg VARCHAR(255)  NOT NULL DEFAULT 'Pending validation',
    upload_status  ENUM('processing','ok','warning','failed') NOT NULL DEFAULT 'processing',
    flag_status    ENUM('none','flagged','resolved') NOT NULL DEFAULT 'none',
    flagged_by     INT           NULL,
    flagged_at     DATETIME      NULL,
    flag_reason    VARCHAR(50)   NULL,
    flag_note      VARCHAR(500)  NULL,
    file_path      VARCHAR(255)  NULL,
    file_hash      CHAR(64)      NULL,
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id),
    INDEX idx_file_hash (file_hash),
    INDEX idx_upload_flag (flag_status, uploaded_by)
);

-- ── 9. AUDIT LOG ────────────────────────────────────────────
-- ENUM extended to include FLAG_UPLOAD and DELETE_UPLOAD so flag_upload.php
-- and delete_upload.php audit rows actually persist instead of being
-- silently dropped by strict sql_mode.
CREATE TABLE IF NOT EXISTS audit_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT          NOT NULL,
    action_type ENUM('LOGIN','LOGOUT','FILE_UPLOAD','FLAG_UPLOAD','DELETE_UPLOAD','RECON_RUN','DATA_EDIT','REPORT_EXPORT','USER_MGMT') NOT NULL,
    detail      TEXT         NOT NULL,
    ip_address  VARCHAR(45)  NOT NULL DEFAULT '',
    result      ENUM('success','failed') NOT NULL DEFAULT 'success',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ── 10. ESCALATIONS ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS escalations (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    run_id          INT          NULL,
    agent_id        INT          NULL,
    user_id         INT          NOT NULL,
    assigned_to     INT          NULL,
    action_type     ENUM('variance','unmatched','currency_mismatch','manual') NOT NULL DEFAULT 'variance',
    action_detail   VARCHAR(500) NOT NULL,
    affected_entity VARCHAR(50)  NULL,
    entity_id       INT          NULL,
    variance_zwg    DECIMAL(15,2) NULL,
    variance_usd    DECIMAL(15,2) NULL,
    priority        ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    status          ENUM('pending','reviewed','resolved','dismissed') NOT NULL DEFAULT 'pending',
    review_note     VARCHAR(1000) NULL,
    reviewed_by     INT          NULL,
    reviewed_at     DATETIME     NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_assigned (assigned_to),
    INDEX idx_run (run_id),
    FOREIGN KEY (user_id)      REFERENCES users(id),
    FOREIGN KEY (assigned_to)  REFERENCES users(id),
    FOREIGN KEY (reviewed_by)  REFERENCES users(id),
    FOREIGN KEY (run_id)       REFERENCES reconciliation_runs(id) ON DELETE SET NULL,
    FOREIGN KEY (agent_id)     REFERENCES agents(id) ON DELETE SET NULL
);

-- ── 11. SYSTEM SETTINGS ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key   VARCHAR(60)  PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_by    INT          NULL,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
('default_period_type',          'Monthly'),
('date_tolerance_days',          '1'),
('amount_tolerance_zwg',         '0'),
('auto_flag_fx_mismatch',        'yes'),
('org_name',                     'Zimnat Life Assurance'),
('system_version',               'v1.0'),
('session_timeout_hours',        '8'),
('auto_escalate_threshold_zwg',  '10000'),
('auto_escalate_threshold_usd',  '500'),
('password_min_length',          '8'),
('audit_retention_days',         '3650'),
-- Default to the smart matcher engine. Legacy is reachable for testing
-- only — it times out on monthly runs on shared hosts.
('matcher_engine',               'smart');

-- ── 12. USER PREFERENCES ───────────────────────────────────
CREATE TABLE IF NOT EXISTS user_preferences (
    user_id    INT          NOT NULL,
    pref_key   VARCHAR(60)  NOT NULL,
    pref_val   VARCHAR(255) NOT NULL,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, pref_key),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ── 13. STATEMENTS (per-agent recon snapshots) ─────────────
CREATE TABLE IF NOT EXISTS statements (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    statement_no  VARCHAR(30)  NOT NULL UNIQUE,
    run_id        INT          NULL,
    agent_id      INT          NOT NULL,
    period_from   DATE         NOT NULL,
    period_to     DATE         NOT NULL,
    status        ENUM('draft','final','reviewed','cancelled') NOT NULL DEFAULT 'draft',
    sales_zwg     DECIMAL(15,2) NOT NULL DEFAULT 0,
    sales_usd     DECIMAL(15,2) NOT NULL DEFAULT 0,
    receipts_zwg  DECIMAL(15,2) NOT NULL DEFAULT 0,
    receipts_usd  DECIMAL(15,2) NOT NULL DEFAULT 0,
    variance_zwg  DECIMAL(15,2) NOT NULL DEFAULT 0,
    variance_usd  DECIMAL(15,2) NOT NULL DEFAULT 0,
    variance_cat  VARCHAR(50)   NULL,
    notes         VARCHAR(1000) NULL,
    generated_by  INT           NOT NULL,
    generated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by   INT           NULL,
    reviewed_at   DATETIME      NULL,
    INDEX idx_agent_period (agent_id, period_from, period_to),
    INDEX idx_run (run_id),
    INDEX idx_status (status),
    FOREIGN KEY (agent_id)     REFERENCES agents(id),
    FOREIGN KEY (run_id)       REFERENCES reconciliation_runs(id) ON DELETE SET NULL,
    FOREIGN KEY (generated_by) REFERENCES users(id),
    FOREIGN KEY (reviewed_by)  REFERENCES users(id)
);

-- ── 14. LOGIN ATTEMPTS (rate limiting) ─────────────────────
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    ip           VARCHAR(45) NOT NULL,
    username     VARCHAR(50) NOT NULL,
    attempted_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_lookup (ip, username, attempted_at)
);

-- ── 15. PASSWORD HISTORY (reuse prevention) ────────────────
CREATE TABLE IF NOT EXISTS password_history (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT          NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    changed_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id, changed_at),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ── 16. NOTIFICATION QUEUE (outbound email) ────────────────
CREATE TABLE IF NOT EXISTS notification_queue (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT          NULL,
    recipient     VARCHAR(150) NOT NULL,
    subject       VARCHAR(200) NOT NULL,
    body          TEXT         NOT NULL,
    category      VARCHAR(50)  NOT NULL,
    status        ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
    attempt_count INT          NOT NULL DEFAULT 0,
    error         VARCHAR(500) NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at       DATETIME     NULL,
    INDEX idx_status (status),
    INDEX idx_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ============================================================
-- AUDIT-LOG IMMUTABILITY TRIGGERS
--
-- The audit log is the system's source of truth for "who did what".
-- These triggers make it physically impossible to UPDATE or DELETE
-- a row once written, even via direct SQL — including by the DBA.
-- The only way to remove an audit row is to DROP TABLE.
-- ============================================================

DROP TRIGGER IF EXISTS prevent_audit_update;
DROP TRIGGER IF EXISTS prevent_audit_delete;

DELIMITER ;;
CREATE TRIGGER prevent_audit_update BEFORE UPDATE ON audit_log
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit log rows are immutable';
END ;;

CREATE TRIGGER prevent_audit_delete BEFORE DELETE ON audit_log
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit log rows are immutable';
END ;;
DELIMITER ;
