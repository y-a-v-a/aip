<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/users.php';

// Harden the session cookie, then start the session. Mark it 'secure' only when
// the request is actually over HTTPS (prod: https://aip.bij-ons-aan-tafel.nl), so
// local http dev (php -S / Docker) still works — a hardcoded true would stop the
// cookie being sent over http and silently break login there.
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
define('REQUEST_IS_HTTPS', $https);
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => $https,
]);
session_start();

// "Keep me signed in" cookie: a random device token whose sha256 is stored on
// the user in users.json. It restores the session after the PHP session expires.
const DEVICE_COOKIE = 'aip_device';

// The admin page asks for the admin's PIN again; that confirmation lasts this long
// (sliding — each admin page view extends it).
const ADMIN_CONFIRM_SECS = 1800;

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

function set_device_cookie(string $token): void {
    setcookie(DEVICE_COOKIE, $token, [
        'expires'  => time() + REMEMBER_TTL_SECS,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => REQUEST_IS_HTTPS,
    ]);
}

function clear_device_cookie(): void {
    setcookie(DEVICE_COOKIE, '', [
        'expires'  => time() - 42000,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => REQUEST_IS_HTTPS,
    ]);
}

/** Start a fresh authenticated session for $id. */
function start_user_session(string $id): void {
    session_regenerate_id(true);
    $_SESSION['user'] = $id;
    $_SESSION['at']   = microtime(true);
    unset($_SESSION['admin_ok']);
}

/**
 * True when the visitor is signed in as a user that still exists. Removing a
 * user on the admin page therefore signs them out on their next request. With
 * no session, a valid device cookie silently signs the visitor back in.
 */
function is_logged_in(): bool {
    static $checked = null;
    if ($checked !== null) {
        return $checked;
    }
    if (isset($_SESSION['phone']) && !isset($_SESSION['user'])) {
        $_SESSION['user'] = $_SESSION['phone']; // sessions from before email logins
        unset($_SESSION['phone']);
    }
    $id = $_SESSION['user'] ?? null;
    if ($id !== null) {
        $u = load_users()[$id] ?? null;
        // Valid while the user exists and wasn't "signed out everywhere" (admin
        // page) after this session started.
        if ($u !== null && ($u['revoked_at'] ?? 0) <= ($_SESSION['at'] ?? 0)) {
            return $checked = true;
        }
        $_SESSION = [];   // user removed or signed out — drop the stale session
    }
    $device = $_COOKIE[DEVICE_COOKIE] ?? '';
    if (is_string($device) && $device !== '') {
        $owner = find_device($device);
        if ($owner !== null) {
            start_user_session($owner);
            return $checked = true;
        }
        clear_device_cookie();
    }
    return $checked = false;
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: /login.php');
        exit;
    }
}

function current_user(): ?string {
    return is_logged_in() ? $_SESSION['user'] : null;
}

function is_admin(): bool {
    $id = current_user();
    return $id !== null && (load_users()[$id]['admin'] ?? false) === true;
}

/**
 * Gate for admin-only pages: anonymous visitors go to the login page; signed-in
 * non-admins get a plain 404 so the page doesn't advertise its existence.
 */
function require_admin(): void {
    require_login();
    if (!is_admin()) {
        http_response_code(404);
        echo 'Not found';
        exit;
    }
}

/** Has the admin re-entered their PIN recently? Extends the window when yes. */
function admin_confirmed(): bool {
    $t = $_SESSION['admin_ok'] ?? 0;
    if (time() - $t < ADMIN_CONFIRM_SECS) {
        $_SESSION['admin_ok'] = time();
        return true;
    }
    return false;
}

/** Re-check the signed-in admin's PIN (with lockout). */
function confirm_admin_pin(string $pin): bool {
    $id = current_user();
    if ($id === null || $pin === '' || is_locked($id)) {
        return false;
    }
    $hash = load_users()[$id]['pin'] ?? null;
    if ($hash && password_verify($pin, $hash)) {
        clear_fail($id);
        session_regenerate_id(true);
        $_SESSION['admin_ok'] = time();
        return true;
    }
    record_fail($id);
    return false;
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

function is_locked(string $id): bool {
    $e = _lockout_load()[$id] ?? null;
    if (!$e || ($e['count'] ?? 0) < MAX_FAILED) {
        return false;
    }
    return (time() - ($e['last'] ?? 0)) < LOCKOUT_SECS;
}

function record_fail(string $id): void {
    $d = _lockout_load();
    $e = $d[$id] ?? ['count' => 0, 'last' => 0];
    if (time() - ($e['last'] ?? 0) > LOCKOUT_SECS) {
        $e['count'] = 0; // window expired, start over
    }
    $e['count'] = ($e['count'] ?? 0) + 1;
    $e['last']  = time();
    $d[$id]     = $e;
    _lockout_save($d);
}

function clear_fail(string $id): void {
    $d = _lockout_load();
    unset($d[$id]);
    _lockout_save($d);
}

/**
 * Verify phone-or-email + PIN against users.json. Returns true and logs in on
 * success; with $remember, the device also stays signed in (device cookie).
 */
function attempt_login(string $identifier, string $pin, bool $remember = false): bool {
    $id = normalize_identifier($identifier);
    if ($id === '' || $pin === '' || is_locked($id)) {
        return false;
    }
    $hash = load_users()[$id]['pin'] ?? null;
    if ($hash && password_verify($pin, $hash)) {
        clear_fail($id);
        start_user_session($id);
        if ($remember && ($device = issue_device($id)) !== null) {
            set_device_cookie($device);
        }
        return true;
    }
    record_fail($id);
    return false;
}

/** Sign in via a one-time login link. Returns true on success. */
function login_with_invite(string $token): bool {
    $res = redeem_invite($token);
    if ($res === null) {
        return false;
    }
    [$id, $device] = $res;
    start_user_session($id);
    set_device_cookie($device);
    return true;
}

/** Sign out: forget this device's token and destroy the session. */
function logout(): void {
    $device = $_COOKIE[DEVICE_COOKIE] ?? '';
    if (is_string($device) && $device !== '') {
        revoke_device($device);
    }
    clear_device_cookie();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
