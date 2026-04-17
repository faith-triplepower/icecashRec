-- ============================================================
-- Migration: add `direction` column to receipts
-- Lets the system distinguish inflows (credits — money received
-- from customers, matched against sales) from outflows (debits —
-- fees, refunds, payouts on the float account). Proper bank
-- reconciliation requires both sides so the ending balance can
-- be verified against the statement, not just the credit side.
--
-- Run once against the target database:
--   mysql -u root icecash_recon < sql/add_direction_column.sql
-- ============================================================

ALTER TABLE receipts
  ADD COLUMN direction ENUM('credit','debit') NOT NULL DEFAULT 'credit' AFTER amount;

-- Composite index so the matching engine and the admin views
-- can filter by direction cheaply alongside match_status.
CREATE INDEX idx_receipts_direction ON receipts (direction, match_status);

