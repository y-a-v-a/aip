<?php
// List allowed logins (phone/email, date added, flags). Never prints PIN hashes.
//
//   php tools/list_users.php
//
// Reads data/users.json. Use via tools/listusers.sh if you have no local PHP.

require __DIR__ . '/../lib/users.php';

$users = load_users();

if (!$users) {
    echo "No users yet. Add one with tools/adduser.sh (or make_user.php).\n";
    exit(0);
}

foreach ($users as $id => $u) {
    $flags = array_filter([
        !empty($u['admin']) ? 'admin' : '',
        !empty($u['pin']) ? 'pin' : 'link-only',
        ($n = count($u['devices'] ?? [])) ? "{$n} device(s)" : '',
    ]);
    printf("%-28s  added %s  [%s]%s\n", $id, $u['added'] ?? '?', implode(', ', $flags),
        isset($u['name']) ? "  {$u['name']}" : '');
}
echo count($users) . " user(s).\n";
