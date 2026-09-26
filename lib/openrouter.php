<?php
require_once __DIR__ . '/../config.php';

/**
 * Generate a recipe via the OpenRouter Chat Completions API.
 *
 * Uses a single raw HTTPS call (no Composer / SDK dependency). OpenRouter is
 * OpenAI-compatible, so any OpenAI-style client library would also work if you
 * later want an SDK — this is the only function you'd need to swap.
 *
 * @param string   $mealType    'lunch' | 'dinner' | 'dessert'
 * @param string   $note        optional free-text request from the cook
 * @param string[] $ingredients the pantry the model is restricted to
 * @return string  the recipe text
 * @throws RuntimeException on any error (missing key, network, API, refusal)
 */
function generate_recipe(string $mealType, string $note, array $ingredients): string {
    $apiKey = openrouter_api_key();
    if (!$apiKey) {
        throw new RuntimeException(
            'No OpenRouter API key found. Set OPENROUTER_API_KEY in the environment, '
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
        'messages'   => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $userMsg],
        ],
        // Ask OpenRouter to include the exact USD cost in the usage block.
        'usage'      => ['include' => true],
    ];

    $ch = curl_init(openrouter_base_url() . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            // Optional attribution headers (show up on openrouter.ai rankings).
            'X-Title: ' . APP_TITLE,
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
        throw new RuntimeException('Network error talking to OpenRouter: ' . $err);
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($resp, true);
    // OpenRouter can return an error object even with HTTP 200 (e.g. moderation).
    if ($code !== 200 || !is_array($data) || isset($data['error'])) {
        $msg = is_array($data) ? ($data['error']['message'] ?? ('HTTP ' . $code)) : ('HTTP ' . $code);
        throw new RuntimeException('OpenRouter API error: ' . $msg);
    }

    $choice = $data['choices'][0] ?? [];
    $finish = (string) ($choice['finish_reason'] ?? '');
    if ($finish === 'content_filter') {
        throw new RuntimeException('The model declined to generate this recipe.');
    }

    $text = trim((string) ($choice['message']['content'] ?? ''));
    if ($text === '') {
        throw new RuntimeException('Empty response from the model.');
    }

    // Cost/trace log — metadata only, no recipe text. Cost comes straight from
    // OpenRouter (usage.cost, in USD) instead of a local pricing table.
    $usage    = $data['usage'] ?? [];
    $logModel = $data['model'] ?? MODEL;
    log_api_call([
        'ts'            => date('c'),
        'model'         => $logModel,
        'meal'          => $mealType,
        'input_tokens'  => (int) ($usage['prompt_tokens'] ?? 0),
        'output_tokens' => (int) ($usage['completion_tokens'] ?? 0),
        'cost_usd'      => round((float) ($usage['cost'] ?? 0.0), 6),
        'stop_reason'   => $finish !== '' ? $finish : null,
        'ms'            => $ms,
        'id'            => $data['id'] ?? null,
    ]);

    return $text;
}
