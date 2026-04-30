<?php
// ============================================================
// core/totp.php — Two-Factor Authentication (TOTP)
// Native RFC 6238 implementation — no Composer dependencies.
// Compatible with Google Authenticator, Authy, and Microsoft
// Authenticator. Required for Manager/Admin roles.
// Secrets stored in user_preferences table.
// Part of IceCashRec — Zimnat General Insurance
// ============================================================

function totp_generate_secret() {
    // RFC 6238 recommends a 20-byte (160-bit) secret, which yields a
    // 32-character base32 representation. Bound the loop on the actual
    // byte string length so the function can't read past the buffer if
    // the byte source ever changes.
    $chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bytes  = random_bytes(20);
    $secret = '';
    for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
        $secret .= $chars[ord($bytes[$i]) % 32];
    }
    return $secret;
}

function totp_get_code($secret, $time_slice = null) {
    if ($time_slice === null) $time_slice = floor(time() / 30);
    $secret_bytes = _base32_decode($secret);
    $time_bytes = pack('N*', 0) . pack('N*', $time_slice);
    $hmac = hash_hmac('sha1', $time_bytes, $secret_bytes, true);
    $offset = ord($hmac[19]) & 0x0F;
    $code = (
        ((ord($hmac[$offset]) & 0x7F) << 24) |
        ((ord($hmac[$offset+1]) & 0xFF) << 16) |
        ((ord($hmac[$offset+2]) & 0xFF) << 8) |
        (ord($hmac[$offset+3]) & 0xFF)
    ) % 1000000;
    return str_pad($code, 6, '0', STR_PAD_LEFT);
}

function totp_verify($secret, $code, $window = 1) {
    $code = trim($code);
    if (strlen($code) !== 6 || !ctype_digit($code)) return false;
    $time_slice = floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_get_code($secret, $time_slice + $i), $code)) {
            return true;
        }
    }
    return false;
}

function totp_provisioning_uri($secret, $username, $issuer = 'IcecashRec') {
    return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($username)
         . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';
}

function totp_qr_url($uri) {
    return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($uri);
}

function _base32_decode($input) {
    $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $input = strtoupper(rtrim($input, '='));
    $buffer = 0; $bits = 0; $output = '';
    for ($i = 0; $i < strlen($input); $i++) {
        $val = strpos($map, $input[$i]);
        if ($val === false) continue;
        $buffer = ($buffer << 5) | $val;
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $output .= chr(($buffer >> $bits) & 0xFF);
        }
    }
    return $output;
}

// DB helpers — stores totp_secret in user_preferences
function totp_is_enabled($db, $user_id) {
    $stmt = $db->prepare("SELECT pref_val FROM user_preferences WHERE user_id=? AND pref_key='totp_secret' LIMIT 1");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ($row && !empty($row['pref_val']));
}

function totp_get_secret($db, $user_id) {
    $stmt = $db->prepare("SELECT pref_val FROM user_preferences WHERE user_id=? AND pref_key='totp_secret'");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? $row['pref_val'] : null;
}

function totp_save_secret($db, $user_id, $secret) {
    $del = $db->prepare("DELETE FROM user_preferences WHERE user_id=? AND pref_key='totp_secret'");
    $del->bind_param('i', $user_id);
    $del->execute();
    $del->close();
    $stmt = $db->prepare("INSERT INTO user_preferences (user_id, pref_key, pref_val) VALUES (?, 'totp_secret', ?)");
    $stmt->bind_param('is', $user_id, $secret);
    $stmt->execute();
    $stmt->close();
}

function totp_disable($db, $user_id) {
    $stmt = $db->prepare("DELETE FROM user_preferences WHERE user_id=? AND pref_key='totp_secret'");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->close();
}

function totp_is_required_for_role($role) {
    return in_array($role, array('Manager', 'Admin'));
}
