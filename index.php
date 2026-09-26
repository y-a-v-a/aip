<?php
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/lib/openrouter.php';
require_once __DIR__ . '/lib/store.php';
require_once __DIR__ . '/lib/view.php';

$recipe = null;
$error  = '';
$meal   = $_POST['meal'] ?? '';
$note   = $_POST['note'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Session expired, please try again.';
    } elseif (!in_array($meal, ['lunch', 'dinner', 'dessert'], true)) {
        $error = 'Please choose lunch, dinner, or dessert.';
    } else {
        try {
            $ingredients = ingredients_list();
            if (!$ingredients) {
                throw new RuntimeException('Ingredient list is empty — check ingredients.txt.');
            }
            $recipe = generate_recipe($meal, $note, $ingredients);
            save_recipe($meal, $note, $recipe);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$token = csrf_token();
page_top('New recipe');
?>
<div class="card">
    <form id="genform" method="post" action="/">
        <input type="hidden" name="csrf" value="<?= e($token) ?>">
        <label>What meal?</label>
        <div class="meals">
            <?php foreach (['lunch' => 'Lunch', 'dinner' => 'Dinner', 'dessert' => 'Dessert'] as $val => $lbl): ?>
                <label>
                    <input type="radio" name="meal" value="<?= e($val) ?>" <?= $meal === $val ? 'checked' : '' ?> required>
                    <span><?= e($lbl) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
        <label for="note">Anything specific? (optional)</label>
        <textarea id="note" name="note" placeholder="e.g. something warming, quick, or salmon-based"><?= e($note) ?></textarea>
        <p></p>
        <button id="go" type="submit">Generate recipe</button>
    </form>
</div>

<script>
  // Full-page POST takes a few seconds; show progress until the page reloads.
  // The submit event only fires once HTML5 validation passes, so the button
  // never gets stuck on an invalid form.
  document.getElementById('genform').addEventListener('submit', function () {
    var b = document.getElementById('go');
    b.disabled = true;
    b.innerHTML = '<span class="spin"></span>Generating…';
  });
</script>

<?php if ($error): ?>
    <div class="err"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($recipe !== null): ?>
    <div class="card">
        <?php render_recipe($recipe); ?>
        <p class="muted">Saved &middot; <a href="/recipes.php">view all saved recipes</a></p>
    </div>
<?php endif; ?>

<?php
page_bottom();
