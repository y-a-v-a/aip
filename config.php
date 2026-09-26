<?php
// ---------------------------------------------------------------------------
// Central configuration. Tweak values here — this is the only "settings" file.
// ---------------------------------------------------------------------------

const APP_TITLE   = 'AIP Recipe Generator';

// Model (OpenRouter ID). Switch to 'anthropic/claude-opus-4.8' for top quality
// or 'anthropic/claude-haiku-4.5' for the cheapest/fastest option. Any model on
// https://openrouter.ai/models works. Sonnet is a good balance for recipes.
const MODEL       = 'anthropic/claude-sonnet-4.6';
const MAX_TOKENS  = 2000;

// Chat-completions endpoint. Override with OPENROUTER_BASE_URL only to point at
// a mock server (the e2e test suite does this); leave unset in production.
const OPENROUTER_DEFAULT_BASE_URL = 'https://openrouter.ai/api/v1';

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
 * Resolve the OpenRouter API key, never baked into the app, from (in order):
 *   1. process env  OPENROUTER_API_KEY — Docker `-e`, shell export
 *   2. request env  $_SERVER           — Apache SetEnv / php-fpm fastcgi_param
 *   3. protected file  data/api_key    — shared hosting (denied via .htaccess)
 * Returns null if none is set.
 */
function openrouter_api_key(): ?string {
    $k = getenv('OPENROUTER_API_KEY');
    if (is_string($k) && trim($k) !== '') {
        return trim($k);
    }
    if (!empty($_SERVER['OPENROUTER_API_KEY'])) {
        return trim((string) $_SERVER['OPENROUTER_API_KEY']);
    }
    if (is_file(API_KEY_FILE)) {
        $v = trim((string) @file_get_contents(API_KEY_FILE));
        if ($v !== '') {
            return $v;
        }
    }
    return null;
}

/** OpenRouter API base URL (no trailing slash), overridable via env for tests. */
function openrouter_base_url(): string {
    $u = getenv('OPENROUTER_BASE_URL');
    return rtrim(is_string($u) && trim($u) !== '' ? trim($u) : OPENROUTER_DEFAULT_BASE_URL, '/');
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
