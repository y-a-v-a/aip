<?php
// List allowed logins (phone + date added). Never prints PIN hashes.
//
//   php tools/list_users.php
//
// Reads data/users.json. Use via tools/listusers.sh if you have no local PHP.

require __DIR__ . '/../config.php';

$users = is_file(USERS_FILE)
    ? (json_decode((string) file_get_contents(USERS_FILE), true) ?: [])
    : [];

if (!$users) {
    echo "No users yet. Add one with tools/adduser.sh (or make_user.php).\n";
    exit(0);
}

foreach ($users as $phone => $u) {
    printf("%-16s  added %s\n", $phone, $u['added'] ?? '?');
}
echo count($users) . " user(s).\n";
