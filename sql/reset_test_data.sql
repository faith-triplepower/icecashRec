-- ============================================================
-- reset_test_data.sql
--
-- Wipes all transactional / log data so you can re-test from a
-- clean slate, while KEEPING configuration:
--   kept   : users, agents, pos_terminals, terminal_assignments,
--            banks, system_settings, user_preferences
--   wiped  : sales, receipts, reconciliation_runs, variance_results,
--            variance_by_channel, manual_match_log, statements,
--            escalations, upload_history, audit_log, login_attempts
--
-- DESTRUCTIVE — run only when you actually want a fresh test run.
-- Use TRUNCATE (resets AUTO_INCREMENT) inside a FK-disabled block
-- so order doesn't matter.
-- ============================================================

USE icecash_recon;

SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE variance_by_channel;
TRUNCATE TABLE variance_results;
TRUNCATE TABLE manual_match_log;
TRUNCATE TABLE statements;
TRUNCATE TABLE escalations;
TRUNCATE TABLE reconciliation_runs;
TRUNCATE TABLE receipts;
TRUNCATE TABLE sales;
TRUNCATE TABLE upload_history;
TRUNCATE TABLE audit_log;

-- login_attempts may not exist on every install (depends on which
-- migration was last applied) — guard with IF EXISTS.
SET @stmt = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.tables
       WHERE table_schema = DATABASE() AND table_name = 'login_attempts') > 0,
    'TRUNCATE TABLE login_attempts',
    'SELECT 1'
));
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SET FOREIGN_KEY_CHECKS = 1;

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
