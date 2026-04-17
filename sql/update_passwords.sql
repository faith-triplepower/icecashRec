-- Run this against icecash_recon if the DB already exists
-- Fixes passwords so login credentials match the login page

USE icecash_recon;

UPDATE users SET
    email         = 'farai.choto@zimnat.co.zw',
    password_hash = '$2y$10$l9wZuG2/xYBhnoT3KSINkeu/FjqC4hMl6jetzeBNSNkHiqZFHfEuy'
WHERE username = 'farai.choto';

UPDATE users SET
    email         = 'tendai.moyo@zimnat.co.zw',
    password_hash = '$2y$10$pHppO7j3PXG9ia/LFD7qRu5z83/i9ns4i5.i/mLb2XW4ToTOn0lM2'
WHERE username = 'tendai.moyo';

UPDATE users SET
    email         = 'uploader@zimnat.co.zw',
    password_hash = '$2y$10$.cL1CzNV7O1m7bY5/4vpSeQZmu/FRRWBujU4TL.yO4ValXvMjA2jC'
WHERE username = 'upload.user';

UPDATE system_settings SET setting_value = 'Zimnat Life Assurance' WHERE setting_key = 'org_name';

-- Verify
SELECT username, email, role FROM users;
