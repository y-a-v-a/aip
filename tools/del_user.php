<?php
// Revoke a login by phone number or email.
//
//   php tools/del_user.php +15551234567
//
// Removes the user from data/users.json. Input is normalized the same way
// make_user.php stores it, so formatting doesn't matter. Their open sessions
// end on their next request. Use via tools/deluser.sh if you have no local PHP.

require __DIR__ . '/../lib/users.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/del_user.php <phone|email>\n");
    exit(1);
}

$id = normalize_identifier($argv[1]);

$removed = users_update(function (array &$users) use ($id) {
    if (!isset($users[$id])) {
        return false;
    }
    unset($users[$id]);
    return true;
});

if (!$removed) {
    fwrite(STDERR, "No such user: {$argv[1]}\n");
    exit(1);
}
echo "Removed user {$id}\n";
