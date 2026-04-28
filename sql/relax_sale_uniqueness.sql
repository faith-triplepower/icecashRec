-- ============================================================
-- relax_sale_uniqueness.sql
--
-- The original schema declared sales.policy_number UNIQUE, which
-- blocks legitimate repeat business: every renewal, top-up, or monthly
-- debit on the same policy fails to insert with "Duplicate entry".
--
-- This migration drops the global UNIQUE and replaces it with a
-- composite UNIQUE on (policy_number, txn_date, source_system) so a
-- renewal or top-up creates a fresh row, but truly duplicate uploads
-- (same policy / same date / same source) are still rejected.
--
-- Safe to run multiple times.
--
-- Runs against whichever database the connection is already pointed at
-- (no USE statement) — cPanel-hosted MySQL users typically can't switch
-- databases, so the migration must be connection-agnostic.
-- ============================================================

-- Drop the unique constraint if it exists (named after the column).
SET @drop_sql = (
    SELECT IF(
        (SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name   = 'sales'
              AND index_name   = 'policy_number'
              AND non_unique   = 0) > 0,
        'ALTER TABLE sales DROP INDEX policy_number',
        'SELECT 1'
    )
);
PREPARE s1 FROM @drop_sql; EXECUTE s1; DEALLOCATE PREPARE s1;

-- Add the composite unique key if it isn't already there.
SET @add_sql = (
    SELECT IF(
        (SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name   = 'sales'
              AND index_name   = 'uk_sale_txn') = 0,
        'ALTER TABLE sales ADD UNIQUE KEY uk_sale_txn (policy_number, txn_date, source_system)',
        'SELECT 1'
    )
);
PREPARE s2 FROM @add_sql; EXECUTE s2; DEALLOCATE PREPARE s2;
