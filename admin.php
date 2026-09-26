<?php
// Admin page: invite people (phone number or email) and manage who has access.
// Only accounts with "admin": true in users.json get here (set via the CLI:
// php tools/make_user.php <phone> <pin> --admin), and every visit needs the
// admin's PIN re-entered within the last ADMIN_CONFIRM_SECS.
require_once __DIR__ . '/auth.php';
require_admin();
require_once __DIR__ . '/lib/view.php';

$me      = current_user();
$error   = '';
$notice  = '';
$newLink = null;   // [identifier, url] of a freshly issued login link

/** Absolute URL of a login link, based on the host this page was opened on. */
function login_url(string $token): string {
    $scheme = REQUEST_IS_HTTPS ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return "{$scheme}://{$host}/login.php?t=" . rawurlencode($token);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $target = (string) ($_POST['id'] ?? '');

    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Session expired, please try again.';
    } elseif ($action === 'confirm') {
        if (is_locked($me)) {
            $error = 'Too many attempts. Try again in a few minutes.';
        } elseif (!confirm_admin_pin((string) ($_POST['pin'] ?? ''))) {
            $error = 'Wrong PIN.';
        }
    } elseif (!admin_confirmed()) {
        $error = 'Please confirm your PIN first.';
    } elseif ($action === 'add') {
        $id   = normalize_identifier((string) ($_POST['identifier'] ?? ''));
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($_POST['name'] ?? '')) ?? '');
        $name = mb_substr($name, 0, 60);
        $pin  = (string) ($_POST['pin'] ?? '');
        if ($id === '') {
            $error = 'Enter a valid phone number (e.g. +31612345678) or email address.';
        } elseif (!is_email_identifier($id) && $id[0] !== '+') {
            $error = 'Enter the phone number in international format, starting with + (e.g. +31612345678).';
        } elseif ($pin !== '' && strlen($pin) < 6) {
            $error = 'A PIN must be at least 6 characters (or leave it empty).';
        } else {
            $added = users_update(function (array &$users) use ($id, $name, $pin) {
                if (isset($users[$id])) {
                    return false;
                }
                $u = ['added' => date('c')];
                if ($name !== '') {
                    $u['name'] = $name;
                }
                if ($pin !== '') {
                    $u['pin'] = password_hash($pin, PASSWORD_DEFAULT);
                }
                $users[$id] = $u;
                return true;
            });
            if (!$added) {
                $error = "{$id} already has access. Use “New login link” below to send them a fresh link.";
            } elseif (($tok = issue_invite($id)) !== null) {
                $newLink = [$id, login_url($tok)];
                $notice  = "Added {$id}.";
            }
        }
    } elseif (!isset(load_users()[$target])) {
        $error = 'No such user.';
    } elseif ($action === 'link') {
        if (($tok = issue_invite($target)) !== null) {
            $newLink = [$target, login_url($tok)];
            $notice  = "New login link for {$target}. Any older link for them no longer works.";
        }
    } elseif ($action === 'signout') {
        users_update(function (array &$users) use ($target) {
            $users[$target]['devices']    = [];
            $users[$target]['revoked_at'] = microtime(true);
            unset($users[$target]['invite']);
        });
        if ($target === $me) {
            start_user_session($me);   // keep the admin's own current session
            $_SESSION['admin_ok'] = time();
        }
        $notice = "Signed {$target} out on all devices.";
    } elseif ($action === 'remove') {
        if ($target === $me) {
            $error = "You can't remove yourself.";
        } else {
            users_update(function (array &$users) use ($target) {
                unset($users[$target]);
            });
            $notice = "Removed {$target}.";
        }
    } else {
        $error = 'Unknown action.';
    }
}

$token = csrf_token();
page_top('Admin');

if (!admin_confirmed()): ?>
<div class="card">
    <?php if ($error): ?><div class="err"><?= e($error) ?></div><?php endif; ?>
    <p class="muted">Admin area. Confirm it's you to continue.</p>
    <form method="post" action="/admin.php" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e($token) ?>">
        <input type="hidden" name="action" value="confirm">
        <label for="pin">Your PIN</label>
        <input type="password" id="pin" name="pin" inputmode="numeric" required autofocus>
        <button type="submit">Continue</button>
    </form>
</div>
<?php
    page_bottom();
    exit;
endif;

$users = load_users();
ksort($users);
?>
<?php if ($error): ?><div class="err"><?= e($error) ?></div><?php endif; ?>
<?php if ($notice): ?><div class="ok"><?= e($notice) ?></div><?php endif; ?>

<?php if ($newLink):
    [$lid, $url] = $newLink;
    $lname = $users[$lid]['name'] ?? '';
    $msg   = ($lname !== '' ? "Hi {$lname}! " : 'Hi! ')
           . "Here's your personal link to the AIP recipe app. Open it and tap Sign in: {$url}";
    ?>
<div class="card" id="newlink">
    <label for="link">Login link for <?= e($lid) ?></label>
    <input class="link" id="link" type="text" readonly value="<?= e($url) ?>">
    <div class="btnrow">
        <button type="button" class="small" id="copy">Copy link</button>
        <?php if (is_email_identifier($lid)): ?>
            <a class="btn" href="mailto:<?= e($lid) ?>?subject=<?= rawurlencode('Your AIP recipe app login') ?>&amp;body=<?= rawurlencode($msg) ?>">Send by email</a>
        <?php else: ?>
            <a class="btn" target="_blank" rel="noopener" href="https://wa.me/<?= e(ltrim($lid, '+')) ?>?text=<?= rawurlencode($msg) ?>">Send via WhatsApp</a>
        <?php endif; ?>
    </div>
    <p class="muted">Works once, until <?= e(date('j M Y', time() + INVITE_TTL_SECS)) ?>. After tapping it they stay
        signed in on that device. This link is shown only now.</p>
</div>
<script>
document.getElementById('copy').addEventListener('click', function () {
    var f = document.getElementById('link'), b = this;
    f.select();
    (navigator.clipboard ? navigator.clipboard.writeText(f.value) : Promise.reject())
        .catch(function () { document.execCommand('copy'); })
        .finally(function () { b.textContent = 'Copied'; });
});
</script>
<?php endif; ?>

<div class="card">
    <form method="post" action="/admin.php" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e($token) ?>">
        <input type="hidden" name="action" value="add">
        <label for="identifier">Add a person: phone number or email</label>
        <input type="text" id="identifier" name="identifier" placeholder="+31612345678 or name@example.com" required>
        <label for="name">Name <span class="muted">(optional)</span></label>
        <input type="text" id="name" name="name" maxlength="60">
        <label for="newpin">PIN <span class="muted">(optional — without one they sign in with the link)</span></label>
        <input type="password" id="newpin" name="pin" inputmode="numeric" autocomplete="new-password">
        <button type="submit">Add &amp; create login link</button>
    </form>
</div>

<div class="card">
    <label>People with access (<?= count($users) ?>)</label>
    <ul class="users">
    <?php foreach ($users as $id => $u):
        $id      = (string) $id;
        $devices = count($u['devices'] ?? []);
        $inv     = $u['invite'] ?? null;
        ?>
        <li data-user="<?= e($id) ?>">
            <span class="id"><?= e($u['name'] ?? '') !== '' ? e($u['name']) . ' · ' : '' ?><?= e($id) ?></span>
            <?php if (!empty($u['admin'])): ?><span class="tag">admin</span><?php endif; ?>
            <?php if (!empty($u['pin'])): ?><span class="tag">PIN</span><?php endif; ?>
            <?php if ($devices): ?><span class="tag"><?= $devices ?> device<?= $devices === 1 ? '' : 's' ?></span><?php endif; ?>
            <?php if ($inv && ($inv['expires'] ?? 0) > time()): ?><span class="tag">link pending</span><?php endif; ?>
            <div class="acts">
                <form method="post" action="/admin.php">
                    <input type="hidden" name="csrf" value="<?= e($token) ?>">
                    <input type="hidden" name="id" value="<?= e($id) ?>">
                    <button class="small" name="action" value="link">New login link</button>
                </form>
                <form method="post" action="/admin.php">
                    <input type="hidden" name="csrf" value="<?= e($token) ?>">
                    <input type="hidden" name="id" value="<?= e($id) ?>">
                    <button class="small ghost" name="action" value="signout">Sign out everywhere</button>
                </form>
                <?php if ($id !== $me): ?>
                <form method="post" action="/admin.php" onsubmit="return confirm('Remove this person? They lose access immediately.');">
                    <input type="hidden" name="csrf" value="<?= e($token) ?>">
                    <input type="hidden" name="id" value="<?= e($id) ?>">
                    <button class="small ghost" name="action" value="remove">Remove</button>
                </form>
                <?php endif; ?>
            </div>
        </li>
    <?php endforeach; ?>
    </ul>
</div>
<?php
page_bottom();
