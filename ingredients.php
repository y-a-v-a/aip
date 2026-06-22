<?php
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/lib/view.php';

$error = '';
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Session expired, please try again.';
    } else {
        $items = normalize_ingredients((string) ($_POST['list'] ?? ''));
        if (!$items) {
            $error = 'The list cannot be empty — add at least one ingredient.';
        } elseif (!save_ingredients($items)) {
            $error = 'Could not save — check that data/ is writable.';
        } else {
            $saved = true;
        }
    }
}

$items = ingredients_list();
$token = csrf_token();
page_top('Ingredients');
?>
<div class="card">
    <p class="muted">
        These are the only ingredients Claude may use. One per line; bullets,
        blank lines, and duplicates are cleaned up automatically. Up to
        <?= MAX_INGREDIENTS ?> items.
    </p>

    <?php if ($saved): ?>
        <div class="ok">Saved — <?= count($items) ?> ingredients.</div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="err"><?= e($error) ?></div>
    <?php endif; ?>

    <form id="ingform" method="post" action="/ingredients.php">
        <input type="hidden" name="csrf" value="<?= e($token) ?>">
        <label for="list">Ingredient list (<?= count($items) ?>)</label>
        <textarea id="list" name="list" rows="16" spellcheck="false"><?= e(implode("\n", $items)) ?></textarea>
        <p></p>
        <button type="submit">Save ingredients</button>
    </form>
</div>
<?php
page_bottom();
