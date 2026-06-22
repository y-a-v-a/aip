<?php
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/lib/store.php';
require_once __DIR__ . '/lib/view.php';

$id  = (string) ($_GET['id'] ?? '');
$one = $id !== '' ? get_recipe($id) : null;

if ($id !== '' && $one === null) {
    page_top('Not found');
    echo "<div class='err'>That recipe was not found.</div>";
    echo "<p><a href='/recipes.php'>Back to saved recipes</a></p>";
    page_bottom();
    exit;
}

if ($one !== null) {
    page_top($one['title'] ?? 'Recipe');
    echo "<div class='card'>";
    render_recipe($one['recipe'] ?? '');
    $when = !empty($one['created']) ? date('j M Y, H:i', strtotime($one['created'])) : '';
    echo "<p class='muted'>" . e(ucfirst($one['meal'] ?? '')) . " &middot; " . e($when) . "</p>";
    echo "</div><p><a href='/recipes.php'>&larr; All saved recipes</a></p>";
    page_bottom();
    exit;
}

// List view
$all = list_recipes();
page_top('Saved recipes');
echo "<div class='card list'>";
if (!$all) {
    echo "<p class='muted'>No saved recipes yet. <a href='/'>Make one.</a></p>";
} else {
    foreach ($all as $r) {
        $when = !empty($r['created']) ? date('j M Y, H:i', strtotime($r['created'])) : '';
        echo "<a href='/recipes.php?id=" . e($r['id'] ?? '') . "'>";
        echo "<span class='t'>" . e($r['title'] ?? 'Untitled') . "</span><br>";
        echo "<span class='muted'>" . e(ucfirst($r['meal'] ?? '')) . " &middot; " . e($when) . "</span>";
        echo "</a>";
    }
}
echo "</div>";
page_bottom();
