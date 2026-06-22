<?php
// ---------------------------------------------------------------------------
// Central configuration. Tweak values here — this is the only "settings" file.
// ---------------------------------------------------------------------------

const APP_TITLE   = 'AIP Recipe Generator';

// Model. Switch to 'claude-opus-4-8' for top quality or 'claude-haiku-4-5'
// for the cheapest/fastest option. Sonnet is a good balance for recipes.
const MODEL       = 'claude-sonnet-4-6';
const MAX_TOKENS  = 2000;

// Paths.
const DATA_DIR           = __DIR__ . '/data';
const RECIPES_DIR        = __DIR__ . '/data/recipes';
const USERS_FILE         = __DIR__ . '/data/users.json';
const LOCKOUT_FILE       = __DIR__ . '/data/lockout.json';
const SYSTEM_PROMPT_FILE = __DIR__ . '/system_prompt.txt';   // <- tweak the prompt here
const INGREDIENTS_SEED   = __DIR__ . '/ingredients.txt';     // shipped default (tracked in git)
const INGREDIENTS_FILE   = __DIR__ . '/data/ingredients.txt'; // editable copy (per-deployment)
const API_KEY_FILE       = __DIR__ . '/data/api_key';        // shared-host key fallback

// Bounds for the web-managed ingredient list (keeps the prompt small + safe).
const MAX_INGREDIENTS    = 200;
const MAX_INGREDIENT_LEN = 80;
const API_LOG_FILE       = __DIR__ . '/data/api_log.jsonl';  // one line per API call

// USD per 1,000,000 tokens as [input, output]. Used only to estimate per-call
// cost in the log — update if Anthropic pricing changes.
const PRICING = [
    'claude-opus-4-8'   => [5.0, 25.0],
    'claude-opus-4-7'   => [5.0, 25.0],
    'claude-sonnet-4-6' => [3.0, 15.0],
    'claude-haiku-4-5'  => [1.0, 5.0],
];

// Login lockout: after MAX_FAILED bad PINs, lock that phone for LOCKOUT_SECS.
const MAX_FAILED   = 5;
const LOCKOUT_SECS = 300;

// Keyword groups used to pick a random "star" ingredient per request, so the
// model varies what it centres a dish on instead of defaulting to one protein.
// Matched case-insensitively as substrings against your ingredient names, so
// formatting (e.g. "WILD SALMON", "GRASS-FED BEEF") doesn't matter. Edit these
// if you change ingredients.txt to new categories.
const PROTEIN_KEYWORDS = ['chicken', 'turkey', 'lamb', 'beef', 'salmon', 'cod',
                          'sardine', 'mackerel', 'shrimp', 'scallop', 'tuna', 'duck', 'fish'];
const FRUIT_KEYWORDS   = ['banana', 'blueberr', 'strawberr', 'raspberr', 'apple',
                          'pear', 'mango', 'papaya', 'plantain'];

/**
 * Read the editable pantry (data/ingredients.txt), one item per line. On first
 * use it is seeded from the shipped default (ingredients.txt). Strips leading
 * bullets ("• ", "-", "*") and blank lines.
 *
 * @return string[]
 */
function ingredients_list(): array {
    if (!is_file(INGREDIENTS_FILE)) {
        // Seed the editable copy from the shipped default the first time.
        if (!is_dir(dirname(INGREDIENTS_FILE))) {
            @mkdir(dirname(INGREDIENTS_FILE), 0775, true);
        }
        $seed = @file_get_contents(INGREDIENTS_SEED);
        if ($seed !== false) {
            @file_put_contents(INGREDIENTS_FILE, $seed, LOCK_EX);
        }
    }
    $raw = @file(INGREDIENTS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($raw)) {
        // Fall back to the shipped default if the editable copy is unreadable.
        $raw = @file(INGREDIENTS_SEED, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    }
    $out = [];
    foreach ($raw as $line) {
        $line = trim(preg_replace('/^[\x{2022}\-\*\s]+/u', '', $line) ?? '');
        if ($line !== '') {
            $out[] = $line;
        }
    }
    return $out;
}

/**
 * Clean raw textarea input into a safe, bounded, de-duplicated ingredient list:
 * strips control chars + bullets, trims, caps line length and count, drops
 * blanks and case-insensitive duplicates (order preserved).
 *
 * @return string[]
 */
function normalize_ingredients(string $raw): array {
    $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    $out   = [];
    $seen  = [];
    foreach ($lines as $line) {
        $line = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $line) ?? '';
        $line = trim(preg_replace('/^[\x{2022}\-\*\s]+/u', '', $line) ?? '');
        if ($line === '') {
            continue;
        }
        if (mb_strlen($line) > MAX_INGREDIENT_LEN) {
            $line = rtrim(mb_substr($line, 0, MAX_INGREDIENT_LEN));
        }
        $key = mb_strtolower($line);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $line;
        if (count($out) >= MAX_INGREDIENTS) {
            break;
        }
    }
    return $out;
}

/** Write the editable pantry. Returns false on failure. */
function save_ingredients(array $items): bool {
    if (!is_dir(dirname(INGREDIENTS_FILE))) {
        @mkdir(dirname(INGREDIENTS_FILE), 0775, true);
    }
    $body = implode("\n", $items) . "\n";
    return @file_put_contents(INGREDIENTS_FILE, $body, LOCK_EX) !== false;
}

/**
 * Resolve the Anthropic API key, never baked into the app, from (in order):
 *   1. process env  ANTHROPIC_API_KEY  — Docker `-e`, shell export
 *   2. request env  $_SERVER           — Apache SetEnv / php-fpm fastcgi_param
 *   3. protected file  data/api_key    — shared hosting (denied via .htaccess)
 * Returns null if none is set.
 */
function anthropic_api_key(): ?string {
    $k = getenv('ANTHROPIC_API_KEY');
    if (is_string($k) && trim($k) !== '') {
        return trim($k);
    }
    if (!empty($_SERVER['ANTHROPIC_API_KEY'])) {
        return trim((string) $_SERVER['ANTHROPIC_API_KEY']);
    }
    if (is_file(API_KEY_FILE)) {
        $v = trim((string) @file_get_contents(API_KEY_FILE));
        if ($v !== '') {
            return $v;
        }
    }
    return null;
}

/**
 * Rough USD cost for a call from its usage block (input + output only; this app
 * doesn't use prompt caching). Returns 0.0 if the model isn't in PRICING.
 */
function estimate_cost(string $model, array $usage): float {
    $p = PRICING[$model] ?? null;
    if (!$p) {
        return 0.0;
    }
    $in  = (int) ($usage['input_tokens'] ?? 0);
    $out = (int) ($usage['output_tokens'] ?? 0);
    return round($in / 1e6 * $p[0] + $out / 1e6 * $p[1], 6);
}

/** Append one compact JSON line (no recipe text) about an API call. */
function log_api_call(array $record): void {
    if (!is_dir(dirname(API_LOG_FILE))) {
        @mkdir(dirname(API_LOG_FILE), 0775, true);
    }
    @file_put_contents(API_LOG_FILE, json_encode($record) . "\n", FILE_APPEND | LOCK_EX);
}

/** @return string[] ingredients whose name contains any of the keywords. */
function _match_group(array $ingredients, array $keywords): array {
    $out = [];
    foreach ($ingredients as $ing) {
        $low = strtolower($ing);
        foreach ($keywords as $kw) {
            if (strpos($low, $kw) !== false) {
                $out[] = $ing;
                break;
            }
        }
    }
    return $out;
}

/**
 * Pick a random "star" ingredient suited to the meal so recipes vary which
 * protein/fruit they centre on. Proteins for lunch/dinner, fruit for dessert.
 * Returns null if no candidate matches (e.g. a heavily edited list).
 */
function pick_feature(string $mealType, array $ingredients): ?string {
    $group = $mealType === 'dessert'
        ? _match_group($ingredients, FRUIT_KEYWORDS)
        : _match_group($ingredients, PROTEIN_KEYWORDS);
    if (!$group) {
        return null;
    }
    return $group[random_int(0, count($group) - 1)];
}
