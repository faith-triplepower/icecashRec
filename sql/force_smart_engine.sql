-- ============================================================
-- Switch any deployment that's still on the legacy matcher to
-- the smart matcher. The legacy 5-tier brute-force engine reliably
-- hits the 60s timeout on shared hosts (cPanel etc.) for any monthly
-- reconciliation; the smart engine is bucket-indexed AND now exits
-- cleanly when the host budget runs out, so it never gets killed
-- mid-write.
--
-- Safe to run multiple times.
-- ============================================================

-- Runs against whichever database the connection is already pointed at.

INSERT INTO system_settings (setting_key, setting_value)
VALUES ('matcher_engine', 'smart')
ON DUPLICATE KEY UPDATE setting_value = 'smart';
