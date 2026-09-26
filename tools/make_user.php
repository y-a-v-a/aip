<?php
// Add or update an allowed user (phone number or email + PIN).
//
//   php tools/make_user.php +15551234567 123456
//   php tools/make_user.php you@example.com 123456 --admin
//
// --admin marks the user as an administrator (may open /admin.php to invite
// others); this is the only way to create an admin. PINs are stored only as
// bcrypt hashes in data/users.json. Re-run to change a PIN; other fields (name,
// signed-in devices) are kept. Phones are normalized to "+digits", emails lowercased.

require __DIR__ . '/../lib/users.php';

$args  = array_slice($argv, 1);
$admin = in_array('--admin', $args, true);
$args  = array_values(array_filter($args, fn($a) => $a !== '--admin'));

if (count($args) < 2) {
    fwrite(STDERR, "Usage: php tools/make_user.php <phone|email> <pin> [--admin]\n");
    exit(1);
}

$id  = normalize_identifier($args[0]);
$pin = $args[1];

if ($id === '') {
    fwrite(STDERR, "Invalid phone number or email.\n");
    exit(1);
}
if (strlen($pin) < 6) {
    fwrite(STDERR, "PIN must be at least 6 characters.\n");
    exit(1);
}

$isUpdate = users_update(function (array &$users) use ($id, $pin, $admin) {
    $existed = isset($users[$id]);
    $u = $users[$id] ?? ['added' => date('c')];
    $u['pin'] = password_hash($pin, PASSWORD_DEFAULT);
    if ($admin) {
        $u['admin'] = true;
    }
    $users[$id] = $u;
    return $existed;
});

echo ($isUpdate ? 'Updated' : 'Added') . ($admin ? ' admin' : ' user') . " {$id}\n";
