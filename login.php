<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/view.php';

if (is_logged_in()) {
    header('Location: /');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Session expired, please try again.';
    } elseif (is_locked(normalize_phone($_POST['phone'] ?? ''))) {
        $error = 'Too many attempts. Try again in a few minutes.';
    } elseif (attempt_login($_POST['phone'] ?? '', $_POST['pin'] ?? '')) {
        header('Location: /');
        exit;
    } else {
        $error = 'Invalid phone number or PIN.';
    }
}

$token = csrf_token();
page_top('Log in', false);
?>
<div class="card">
    <form method="post" action="/login.php" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e($token) ?>">
        <?php if ($error): ?><div class="err"><?= e($error) ?></div><?php endif; ?>
        <label for="phone">Phone number</label>
        <input type="text" id="phone" name="phone" inputmode="tel" placeholder="+15551234567" required>
        <label for="pin">PIN</label>
        <input type="password" id="pin" name="pin" inputmode="numeric" required>
        <button type="submit">Log in</button>
    </form>
</div>
<p class="muted">Access is limited to approved phone numbers.</p>
<?php
page_bottom();
