<?php
require_once __DIR__ . '/../config.php';

/**
 * Generate a recipe via the Claude Messages API.
 *
 * Uses a single raw HTTPS call (no Composer / SDK dependency). If you later
 * install the official PHP SDK ("composer require anthropic-ai/sdk"), this is
 * the only function you need to swap.
 *
 * @param string   $mealType    'lunch' | 'dinner' | 'dessert'
 * @param string   $note        optional free-text request from the cook
 * @param string[] $ingredients the pantry the model is restricted to
 * @return string  the recipe text
 * @throws RuntimeException on any error (missing key, network, API, refusal)
 */
function generate_recipe(string $mealType, string $note, array $ingredients): string {
    $apiKey = anthropic_api_key();
    if (!$apiKey) {
        throw new RuntimeException(
            'No Anthropic API key found. Set ANTHROPIC_API_KEY in the environment, '
            . 'or create a data/api_key file containing the key.'
        );
    }

    $system = trim((string) @file_get_contents(SYSTEM_PROMPT_FILE));
    if ($system === '') {
        throw new RuntimeException('System prompt file is missing or empty.');
    }

    // Shuffle so no ingredient sits at a fixed position run-to-run — reduces
    // positional bias (e.g. always reaching for whatever's near the top) and
    // spreads variety across requests. Safe: we don't use prompt caching.
    shuffle($ingredients);

    // Constrain the model to the pantry by appending it to the system prompt.
    $system .= "\n\n## AVAILABLE INGREDIENTS (the ONLY ones you may use)\n- "
             . implode("\n- ", $ingredients);

    $userMsg = "Create a {$mealType} recipe.";
    $note    = trim($note);
    if ($note !== '') {
        // The cook is steering — honour their request, skip the random star.
        $userMsg .= "\n\nExtra request from the cook: " . $note;
    } else {
        // No specific request: centre the dish on a random suitable ingredient
        // so we don't keep getting the same protein.
        $feature = pick_feature($mealType, $ingredients);
        if ($feature !== null) {
            $label = $mealType === 'dessert' ? 'fruit' : 'protein';
            $userMsg .= "\n\nFor variety, build this recipe around {$feature} as the main {$label}.";
        }
    }

    $payload = [
        'model'      => MODEL,
        'max_tokens' => MAX_TOKENS,
        'system'     => $system,
        'messages'   => [
            ['role' => 'user', 'content' => $userMsg],
        ],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'content-type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 120,
    ]);
    $t0   = microtime(true);
    $resp = curl_exec($ch);
    $ms   = (int) round((microtime(true) - $t0) * 1000);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Network error talking to Claude: ' . $err);
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($resp, true);
    if ($code !== 200 || !is_array($data)) {
        $msg = $data['error']['message'] ?? ('HTTP ' . $code);
        throw new RuntimeException('Claude API error: ' . $msg);
    }
    if (($data['stop_reason'] ?? '') === 'refusal') {
        throw new RuntimeException('The model declined to generate this recipe.');
    }

    // Concatenate any text content blocks.
    $text = '';
    foreach ($data['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text .= $block['text'];
        }
    }
    $text = trim($text);
    if ($text === '') {
        throw new RuntimeException('Empty response from Claude.');
    }

    // Cost/trace log — metadata only, no recipe text.
    $usage    = $data['usage'] ?? [];
    $logModel = $data['model'] ?? MODEL;
    log_api_call([
        'ts'            => date('c'),
        'model'         => $logModel,
        'meal'          => $mealType,
        'input_tokens'  => (int) ($usage['input_tokens'] ?? 0),
        'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
        'cost_usd'      => estimate_cost($logModel, $usage),
        'stop_reason'   => $data['stop_reason'] ?? null,
        'ms'            => $ms,
        'id'            => $data['id'] ?? null,
    ]);

    return $text;
}
