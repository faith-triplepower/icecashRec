-- ============================================================
-- Migration: flag/return-to-uploader on upload_history
-- Lets a Reconciler send a problem file back to the original
-- uploader without deleting it. Stores who flagged it, when, why,
-- and a free-text note. The uploader sees the FLAGGED badge in
-- their files list and gets an email notification.
--
-- Run once:
--   mysql -u root icecash_recon < sql/add_upload_flag_columns.sql
-- ============================================================

ALTER TABLE upload_history
  ADD COLUMN flag_status   ENUM('none','flagged','resolved') NOT NULL DEFAULT 'none' AFTER upload_status,
  ADD COLUMN flagged_by    INT          NULL AFTER flag_status,
  ADD COLUMN flagged_at    DATETIME     NULL AFTER flagged_by,
  ADD COLUMN flag_reason   VARCHAR(50)  NULL AFTER flagged_at,
  ADD COLUMN flag_note     VARCHAR(500) NULL AFTER flag_reason;

-- Index so "show me my flagged uploads" stays fast.
CREATE INDEX idx_upload_flag ON upload_history (flag_status, uploaded_by);
