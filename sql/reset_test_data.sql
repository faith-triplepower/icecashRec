-- ============================================================
-- reset_test_data.sql
--
-- Wipes all transactional / log data so you can re-test from a
-- clean slate, while KEEPING configuration:
--   kept   : users, agents, pos_terminals, terminal_assignments,
--            banks, system_settings, user_preferences, audit_log
--   wiped  : sales, receipts, reconciliation_runs, variance_results,
--            variance_by_channel, manual_match_log, statements,
--            escalations, upload_history, login_attempts
--
-- DESTRUCTIVE — run only when you actually want a fresh test run.
--
-- Uses DELETE FROM rather than TRUNCATE because cPanel-hosted MySQL
-- accounts typically lack the DROP privilege that TRUNCATE requires.
-- DELETE only needs DELETE privilege, which app users always have.
-- The trade-off: AUTO_INCREMENT counters keep climbing instead of
-- resetting to 1 — harmless for test data.
--
-- Order is dependents-first so foreign-key constraints are satisfied
-- without needing SUPER to disable FK checks.
--
-- audit_log is INTENTIONALLY kept. It's the immutable record of
-- "who did what" — wiping it on every test reset defeats its
-- purpose, and on installs that have run install.sql there are
-- triggers blocking the DELETE anyway.
-- ============================================================

-- Runs against whichever database the connection is already pointed at.

DELETE FROM variance_by_channel;
DELETE FROM variance_results;
DELETE FROM manual_match_log;
DELETE FROM statements;
DELETE FROM escalations;
DELETE FROM reconciliation_runs;
DELETE FROM receipts;
DELETE FROM sales;
DELETE FROM upload_history;

-- login_attempts is created by some migrations and not others — guard.
SET @stmt = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.tables
       WHERE table_schema = DATABASE() AND table_name = 'login_attempts') > 0,
    'DELETE FROM login_attempts',
    'SELECT 1'
));
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- Sanity-check counts after the wipe.
SELECT
  (SELECT COUNT(*) FROM sales)               AS sales,
  (SELECT COUNT(*) FROM receipts)            AS receipts,
  (SELECT COUNT(*) FROM reconciliation_runs) AS recon_runs,
  (SELECT COUNT(*) FROM upload_history)      AS uploads,
  (SELECT COUNT(*) FROM audit_log)           AS audit_rows,
  (SELECT COUNT(*) FROM users)               AS users_kept,
  (SELECT COUNT(*) FROM agents)              AS agents_kept,
  (SELECT COUNT(*) FROM pos_terminals)       AS terminals_kept;
