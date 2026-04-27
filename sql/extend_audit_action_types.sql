-- ============================================================
-- extend_audit_action_types.sql
--
-- Two endpoints (process/flag_upload.php and process/delete_upload.php)
-- write FLAG_UPLOAD and DELETE_UPLOAD action types respectively, but
-- the original audit_log ENUM doesn't include them. Depending on
-- sql_mode, those rows are either silently dropped or throw — leaving
-- the most security-relevant edits (deletions, flagging) without an
-- audit trail.
--
-- Safe to run multiple times.
-- ============================================================

USE icecash_recon;

ALTER TABLE audit_log
  MODIFY action_type ENUM(
    'LOGIN','LOGOUT','FILE_UPLOAD','FLAG_UPLOAD','DELETE_UPLOAD',
    'RECON_RUN','DATA_EDIT','REPORT_EXPORT','USER_MGMT'
  ) NOT NULL;
