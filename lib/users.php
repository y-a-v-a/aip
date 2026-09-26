<?php
// ---------------------------------------------------------------------------
// The user store: data/users.json, keyed by a normalized identifier — a phone
// number ("+31612345678") or a lowercased email address. No sessions here, so
// the CLI tools in tools/ can share it with the web app.
//
// Per-user record (every field optional except 'added'):
//   pin      bcrypt hash of the PIN (users added without a PIN log in by link)
//   name     free-text label shown on the admin page
//   admin    true = may open /admin.php (set only via the CLI, never the web)
//   invite   {hash, expires}   one-time login link (sha256 of the token)
//   devices  [{hash, created}] "keep me signed in" cookies (sha256 of the token)
//
// Only hashes of secrets are stored; the raw tokens exist only in the link /
// cookie handed to the user.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../config.php';

const INVITE_TTL_SECS   = 7 * 86400;   // login links expire after a week
const REMEMBER_TTL_SECS = 365 * 86400; // a signed-in device stays signed in a year
const MAX_DEVICES       = 10;          // oldest device tokens are dropped beyond this

/** Keep a leading "+" and digits only, so input formatting doesn't matter. */
function normalize_phone(string $p): string {
    $p    = trim($p);
    $plus = (strncmp($p, '+', 1) === 0) ? '+' : '';
    return $plus . preg_replace('/\D+/', '', $p);
}

/**
 * Turn user input into a storage key: a lowercased email if it contains "@",
 * otherwise a normalized phone number. Returns '' when it is neither.
 */
function normalize_identifier(string $raw): string {
    $raw = trim($raw);
    if (strpos($raw, '@') !== false) {
        $email = strtolower($raw);
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }
    if (preg_match('/[^\d\s+().\-\/]/', $raw)) {
        return ''; // letters etc. — not a phone number
    }
    $phone = normalize_phone($raw);
    return strlen(ltrim($phone, '+')) >= 6 ? $phone : '';
}

function is_email_identifier(string $id): bool {
    return strpos($id, '@') !== false;
}

function token_hash(string $token): string {
    return hash('sha256', $token);
}

function new_token(): string {
    return bin2hex(random_bytes(24));
}

function load_users(): array {
    $j = @file_get_contents(USERS_FILE);
    $u = $j ? json_decode($j, true) : [];
    return is_array($u) ? $u : [];
}

/**
 * Read-modify-write users.json under an exclusive lock, so concurrent requests
 * (admin edits, logins consuming links) can't clobber each other. $fn gets the
 * users array by reference; whatever it returns is passed back to the caller.
 */
function users_update(callable $fn) {
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0775, true);
    }
    $fh = fopen(USERS_FILE, 'c+');
    if (!$fh) {
        throw new RuntimeException('Cannot open users file — check that data/ is writable.');
    }
    try {
        flock($fh, LOCK_EX);
        $raw   = stream_get_contents($fh);
        $users = $raw ? json_decode($raw, true) : [];
        if (!is_array($users)) {
            $users = [];
        }
        $result = $fn($users);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($users, JSON_PRETTY_PRINT));
        fflush($fh);
        return $result;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** Create a one-time login link token for $id (replacing any previous one). */
function issue_invite(string $id): ?string {
    $token = new_token();
    $ok = users_update(function (array &$users) use ($id, $token) {
        if (!isset($users[$id])) {
            return false;
        }
        $users[$id]['invite'] = ['hash' => token_hash($token), 'expires' => time() + INVITE_TTL_SECS];
        return true;
    });
    return $ok ? $token : null;
}

/** Find which user a still-valid login-link token belongs to (does not consume it). */
function find_invite(string $token): ?string {
    if ($token === '') {
        return null;
    }
    $h = token_hash($token);
    foreach (load_users() as $id => $u) {
        $inv = $u['invite'] ?? null;
        if ($inv && hash_equals($inv['hash'] ?? '', $h) && ($inv['expires'] ?? 0) > time()) {
            return (string) $id;
        }
    }
    return null;
}

/**
 * Consume a login-link token: on success it is removed (single use), a new
 * device token is registered, and [identifier, device token] is returned.
 */
function redeem_invite(string $token): ?array {
    if ($token === '') {
        return null;
    }
    $h      = token_hash($token);
    $device = new_token();
    return users_update(function (array &$users) use ($h, $device) {
        foreach ($users as $id => &$u) {
            $inv = $u['invite'] ?? null;
            if ($inv && hash_equals($inv['hash'] ?? '', $h) && ($inv['expires'] ?? 0) > time()) {
                unset($u['invite']);
                _add_device($u, $device);
                return [(string) $id, $device];
            }
        }
        return null;
    });
}

/** Register a new "keep me signed in" device for $id; returns its token. */
function issue_device(string $id): ?string {
    $device = new_token();
    $ok = users_update(function (array &$users) use ($id, $device) {
        if (!isset($users[$id])) {
            return false;
        }
        _add_device($users[$id], $device);
        return true;
    });
    return $ok ? $device : null;
}

function _add_device(array &$u, string $device): void {
    $devs   = $u['devices'] ?? [];
    $devs[] = ['hash' => token_hash($device), 'created' => time()];
    $u['devices'] = array_slice($devs, -MAX_DEVICES);
}

/** Which user a device token belongs to, or null if unknown/expired. */
function find_device(string $token): ?string {
    if ($token === '') {
        return null;
    }
    $h = token_hash($token);
    foreach (load_users() as $id => $u) {
        foreach ($u['devices'] ?? [] as $d) {
            if (hash_equals($d['hash'] ?? '', $h) && ($d['created'] ?? 0) + REMEMBER_TTL_SECS > time()) {
                return (string) $id;
            }
        }
    }
    return null;
}

/** Forget one device token (on logout). */
function revoke_device(string $token): void {
    $h = token_hash($token);
    users_update(function (array &$users) use ($h) {
        foreach ($users as &$u) {
            if (!empty($u['devices'])) {
                $u['devices'] = array_values(array_filter(
                    $u['devices'],
                    fn($d) => !hash_equals($d['hash'] ?? '', $h)
                ));
            }
        }
    });
}
