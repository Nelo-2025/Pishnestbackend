<?php
/**
 * Load API keys and other secrets from secrets.local.php or environment variables.
 */

function impactLoadSecrets(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [
        'anthropic_api_key' => '',
        'anthropic_model' => 'claude-sonnet-4-20250514',
    ];

    $localPath = dirname(__DIR__) . '/secrets.local.php';
    if (is_file($localPath)) {
        $local = require $localPath;
        if (is_array($local)) {
            $cache = array_merge($cache, $local);
        }
    }

    $envKey = getenv('ANTHROPIC_API_KEY');
    if ($envKey !== false && trim($envKey) !== '') {
        $cache['anthropic_api_key'] = trim($envKey);
    }

    $envModel = getenv('ANTHROPIC_MODEL');
    if ($envModel !== false && trim($envModel) !== '') {
        $cache['anthropic_model'] = trim($envModel);
    }

    $cache['anthropic_api_key'] = trim((string)$cache['anthropic_api_key']);

    return $cache;
}

function impactHasClaudeKey(): bool {
    $secrets = impactLoadSecrets();
    return $secrets['anthropic_api_key'] !== '';
}
