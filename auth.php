<?php
require_once __DIR__ . '/config.php';

// Harden the session cookie, then start the session. Mark it 'secure' only when
// the request is actually over HTTPS (prod: https://aip.bij-ons-aan-tafel.nl), so
// local http dev (php -S / Docker) still works — a hardcoded true would stop the
// cookie being sent over http and silently break login there.
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => $https,
]);
session_start();

// --- CSRF ------------------------------------------------------------------

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_check(?string $t): bool {
    return is_string($t) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}

// --- session helpers -------------------------------------------------------

function is_logged_in(): bool {
    return !empty($_SESSION['phone']);
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: /login.php');
        exit;
    }
}

function current_user(): ?string {
    return $_SESSION['phone'] ?? null;
}

/** Keep a leading "+" and digits only, so input formatting doesn't matter. */
function normalize_phone(string $p): string {
    $p    = trim($p);
    $plus = (strncmp($p, '+', 1) === 0) ? '+' : '';
    return $plus . preg_replace('/\D+/', '', $p);
}

function load_users(): array {
    $j = @file_get_contents(USERS_FILE);
    $u = $j ? json_decode($j, true) : [];
    return is_array($u) ? $u : [];
}

// --- simple file-based lockout ---------------------------------------------

function _lockout_load(): array {
    $j = @file_get_contents(LOCKOUT_FILE);
    $d = $j ? json_decode($j, true) : [];
    return is_array($d) ? $d : [];
}

function _lockout_save(array $d): void {
    @file_put_contents(LOCKOUT_FILE, json_encode($d), LOCK_EX);
}

function is_locked(string $phone): bool {
    $e = _lockout_load()[$phone] ?? null;
    if (!$e || ($e['count'] ?? 0) < MAX_FAILED) {
        return false;
    }
    return (time() - ($e['last'] ?? 0)) < LOCKOUT_SECS;
}

function record_fail(string $phone): void {
    $d = _lockout_load();
    $e = $d[$phone] ?? ['count' => 0, 'last' => 0];
    if (time() - ($e['last'] ?? 0) > LOCKOUT_SECS) {
        $e['count'] = 0; // window expired, start over
    }
    $e['count'] = ($e['count'] ?? 0) + 1;
    $e['last']  = time();
    $d[$phone]  = $e;
    _lockout_save($d);
}

function clear_fail(string $phone): void {
    $d = _lockout_load();
    unset($d[$phone]);
    _lockout_save($d);
}

/** Verify phone + PIN against users.json. Returns true and logs in on success. */
function attempt_login(string $phone, string $pin): bool {
    $phone = normalize_phone($phone);
    if ($phone === '' || $pin === '' || is_locked($phone)) {
        return false;
    }
    $hash = load_users()[$phone]['pin'] ?? null;
    if ($hash && password_verify($pin, $hash)) {
        clear_fail($phone);
        session_regenerate_id(true);
        $_SESSION['phone'] = $phone;
        return true;
    }
    record_fail($phone);
    return false;
}
