<?php
// Add or update an allowed user (phone + PIN).
//
//   php tools/make_user.php +15551234567 1234
//
// PINs are stored only as bcrypt hashes in data/users.json. Re-run to change
// a PIN; the phone number is normalized to "+digits".

require __DIR__ . '/../config.php';

if ($argc < 3) {
    fwrite(STDERR, "Usage: php tools/make_user.php <phone> <pin>\n");
    exit(1);
}

$phone = trim($argv[1]);
$pin   = $argv[2];

$plus  = (strncmp($phone, '+', 1) === 0) ? '+' : '';
$phone = $plus . preg_replace('/\D+/', '', $phone);

if ($phone === '' || $phone === '+') {
    fwrite(STDERR, "Invalid phone number.\n");
    exit(1);
}
if (strlen($pin) < 4) {
    fwrite(STDERR, "PIN must be at least 4 characters.\n");
    exit(1);
}

if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0775, true);
}

$users = is_file(USERS_FILE)
    ? (json_decode((string) file_get_contents(USERS_FILE), true) ?: [])
    : [];

$isUpdate = isset($users[$phone]);
$users[$phone] = [
    'pin'   => password_hash($pin, PASSWORD_DEFAULT),
    'added' => date('c'),
];

file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT));
echo ($isUpdate ? 'Updated' : 'Added') . " user {$phone}\n";
