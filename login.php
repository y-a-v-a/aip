<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/view.php';

// A login link looks like /login.php?t=<token>. Opening it only shows a button;
// the token is consumed by the POST. That keeps chat apps' link previews (which
// GET the URL) from burning the single-use link before the person taps it.
$invite = (string) ($_POST['t'] ?? $_GET['t'] ?? '');

if (is_logged_in() && $invite === '') {
    header('Location: /');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ident = (string) ($_POST['identifier'] ?? '');
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Session expired, please try again.';
    } elseif ($invite !== '') {
        if (login_with_invite($invite)) {
            header('Location: /');
            exit;
        }
        $error = 'This login link is invalid, already used, or expired. Ask for a new one.';
    } elseif (is_locked(normalize_identifier($ident))) {
        $error = 'Too many attempts. Try again in a few minutes.';
    } elseif (attempt_login($ident, (string) ($_POST['pin'] ?? ''), !empty($_POST['remember']))) {
        header('Location: /');
        exit;
    } else {
        $error = 'Invalid phone number / email or PIN.';
    }
}

$token = csrf_token();

if ($invite !== '') {
    $who  = find_invite($invite);
    $name = $who !== null ? (load_users()[$who]['name'] ?? '') : '';
    page_top('Sign in', false);
    ?>
<div class="card">
    <?php if ($error): ?><div class="err"><?= e($error) ?></div><?php endif; ?>
    <?php if ($who !== null): ?>
        <p>Welcome<?= $name !== '' ? ', ' . e($name) : '' ?>! Tap the button to sign in.
           You'll stay signed in on this device.</p>
        <form method="post" action="/login.php">
            <input type="hidden" name="csrf" value="<?= e($token) ?>">
            <input type="hidden" name="t" value="<?= e($invite) ?>">
            <button type="submit">Sign in</button>
        </form>
    <?php elseif (!$error): ?>
        <div class="err">This login link is invalid, already used, or expired. Ask for a new one.</div>
    <?php endif; ?>
</div>
<p class="muted"><a href="/login.php">Log in with a PIN instead</a></p>
    <?php
    page_bottom();
    exit;
}

page_top('Log in', false);
?>
<div class="card">
    <form method="post" action="/login.php" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e($token) ?>">
        <?php if ($error): ?><div class="err"><?= e($error) ?></div><?php endif; ?>
        <label for="identifier">Phone number or email</label>
        <input type="text" id="identifier" name="identifier" inputmode="email" placeholder="+31612345678 or you@example.com" required>
        <label for="pin">PIN</label>
        <input type="password" id="pin" name="pin" inputmode="numeric" required>
        <label class="check"><input type="checkbox" name="remember" value="1" checked> Keep me signed in on this device</label>
        <button type="submit">Log in</button>
    </form>
</div>
<p class="muted">Access is by invitation. Got a login link? Just open it.</p>
<?php
page_bottom();
