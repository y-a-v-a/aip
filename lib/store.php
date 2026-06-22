<?php
require_once __DIR__ . '/../config.php';

/** Make sure the data + recipes directories exist. */
function ensure_dirs(): void {
    if (!is_dir(DATA_DIR))    { @mkdir(DATA_DIR, 0775, true); }
    if (!is_dir(RECIPES_DIR)) { @mkdir(RECIPES_DIR, 0775, true); }
}

/** Use the first non-empty line of the recipe as its title. */
function recipe_title(string $text): string {
    $first = strtok($text, "\n");
    $first = trim(preg_replace('/^[#\s\-\*]+/', '', (string) $first) ?? '');
    return $first !== '' ? $first : 'Untitled recipe';
}

/**
 * Save a recipe as a JSON file and return its id.
 * Filenames are server-generated (timestamp + random) — never user input.
 */
function save_recipe(string $meal, string $note, string $text): string {
    ensure_dirs();
    $id  = 'recipe_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
    $rec = [
        'id'      => $id,
        'created' => date('c'),
        'meal'    => $meal,
        'note'    => $note,
        'model'   => MODEL,
        'title'   => recipe_title($text),
        'recipe'  => $text,
    ];
    file_put_contents(RECIPES_DIR . '/' . $id . '.json', json_encode($rec, JSON_PRETTY_PRINT), LOCK_EX);
    return $id;
}

/** All saved recipes, newest first. */
function list_recipes(): array {
    if (!is_dir(RECIPES_DIR)) {
        return [];
    }
    $files = glob(RECIPES_DIR . '/recipe_*.json') ?: [];
    rsort($files); // filenames start with a timestamp, so this is newest-first
    $out = [];
    foreach ($files as $f) {
        $d = json_decode((string) @file_get_contents($f), true);
        if (is_array($d)) {
            $out[] = $d;
        }
    }
    return $out;
}

/** Fetch one recipe by id, with a strict id whitelist (blocks path traversal). */
function get_recipe(string $id): ?array {
    if (!preg_match('/^recipe_\d{8}_\d{6}_[0-9a-f]{6}$/', $id)) {
        return null;
    }
    $f = RECIPES_DIR . '/' . $id . '.json';
    if (!is_file($f)) {
        return null;
    }
    $d = json_decode((string) @file_get_contents($f), true);
    return is_array($d) ? $d : null;
}
