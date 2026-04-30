-- ============================================================
-- reset_test_data.sql
--
-- Full system wipe for moving from testing to production.
--
-- KEPT:   users, banks, system_settings
-- WIPED:  everything else — agents, pos_terminals,
--         terminal_assignments, sales, receipts,
--         reconciliation_runs, variance_results,
--         variance_by_channel, manual_match_log, statements,
--         escalations, upload_history, user_preferences,
--         audit_log, login_attempts
--
-- DESTRUCTIVE — cannot be undone. Run only when you are certain
-- you want a clean production start.
--
-- Uses DELETE FROM (not TRUNCATE) so only DELETE privilege is
-- needed — TRUNCATE requires DROP which cPanel accounts lack.
-- AUTO_INCREMENT counters keep climbing; this is harmless.
--
-- Dependents are deleted before their parents so foreign-key
-- constraints are satisfied without disabling FK checks.
-- ============================================================

-- ── 1. Variance / result tables (depend on runs + agents) ──
DELETE FROM variance_by_channel;
DELETE FROM variance_results;

-- ── 2. Statements and escalations (depend on runs + agents) ─
DELETE FROM statements;
DELETE FROM escalations;

-- ── 3. Manual match log (depends on receipts / runs) ────────
DELETE FROM manual_match_log;

-- ── 4. Reconciliation runs (referenced by above) ────────────
DELETE FROM reconciliation_runs;

-- ── 5. Transaction data ──────────────────────────────────────
DELETE FROM receipts;
DELETE FROM sales;
DELETE FROM upload_history;

-- ── 6. Login attempts (table may not exist on all installs) ──
SET @stmt = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.tables
       WHERE table_schema = DATABASE() AND table_name = 'login_attempts') > 0,
    'DELETE FROM login_attempts',
    'SELECT 1'
));
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 7. User preferences and audit log ───────────────────────
DELETE FROM user_preferences;
DELETE FROM audit_log;

-- ── 8. Terminal assignments then terminals (agents last) ─────
DELETE FROM terminal_assignments;
DELETE FROM pos_terminals;
DELETE FROM agents;

-- ── Sanity check ─────────────────────────────────────────────
SELECT
  (SELECT COUNT(*) FROM agents)              AS agents,
  (SELECT COUNT(*) FROM pos_terminals)       AS terminals,
  (SELECT COUNT(*) FROM sales)               AS sales,
  (SELECT COUNT(*) FROM receipts)            AS receipts,
  (SELECT COUNT(*) FROM reconciliation_runs) AS recon_runs,
  (SELECT COUNT(*) FROM upload_history)      AS uploads,
  (SELECT COUNT(*) FROM user_preferences)    AS user_prefs,
  (SELECT COUNT(*) FROM audit_log)           AS audit_rows,
  (SELECT COUNT(*) FROM users)               AS users_kept;
