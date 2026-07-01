<?php
// Revoke a login by phone number.
//
//   php tools/del_user.php +15551234567
//
// Removes the phone from data/users.json. The phone is normalized to "+digits"
// the same way make_user.php stores it, so input formatting doesn't matter.
// Use via tools/deluser.sh if you have no local PHP.

require __DIR__ . '/../config.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/del_user.php <phone>\n");
    exit(1);
}

$phone = trim($argv[1]);
$plus  = (strncmp($phone, '+', 1) === 0) ? '+' : '';
$phone = $plus . preg_replace('/\D+/', '', $phone);

$users = is_file(USERS_FILE)
    ? (json_decode((string) file_get_contents(USERS_FILE), true) ?: [])
    : [];

if (!isset($users[$phone])) {
    fwrite(STDERR, "No such user: {$phone}\n");
    exit(1);
}

unset($users[$phone]);
file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT));
echo "Removed user {$phone}\n";
