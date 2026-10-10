<?php
/**
 * Claude (Anthropic) integration for impact goal analysis.
 */

require_once __DIR__ . '/secrets.php';

function impactIsPiiColumn(string $column): bool {
    return (bool)preg_match(
        '/name|email|e_mail|phone|mobile|address|postcode|surname|firstname|first_name|last_name|child_name|pupil|student_id|dob|birth/i',
        $column
    );
}

function impactBuildLlmSample(array $sessions, array $fieldMap, int $limit = 12): array {
    $columns = [];
    foreach ($fieldMap as $name => $type) {
        if (!impactIsPiiColumn($name)) {
            $columns[] = $name;
        }
    }

    $sample = [];
    $take = array_slice($sessions, 0, $limit);
    foreach ($take as $session) {
        $row = ['session_date' => $session['session_date']];
        if ($session['headcount'] !== null) {
            $row['headcount'] = $session['headcount'];
        }

        $attrs = is_array($session['attributes'] ?? null) ? $session['attributes'] : [];
        foreach ($columns as $col) {
            if (array_key_exists($col, $attrs)) {
                $row[$col] = $attrs[$col];
            }
        }
        $sample[] = $row;
    }

    return $sample;
}

function impactExtractJsonObject(string $text): ?array {
    $text = trim($text);
    if ($text === '') {
        return null;
    }

    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $text, $m)) {
        $decoded = json_decode(trim($m[1]), true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return null;
}

function impactNormaliseMeetsGoals(string $value): string {
    $v = strtolower(trim($value));
    $allowed = ['yes', 'partial', 'no', 'not_measurable', 'not_measurable_yet'];
    if (in_array($v, $allowed, true)) {
        return $v === 'not_measurable_yet' ? 'not_measurable' : $v;
    }
    return 'not_measurable';
}

function impactMeetsGoalsLabel(string $meetsGoals): string {
    switch ($meetsGoals) {
        case 'yes':
            return 'Likely on track';
        case 'partial':
            return 'Partially supported';
        case 'no':
            return 'Gap identified';
        default:
            return 'Needs qualitative evidence';
    }
}

function impactCallClaudeGoalAssessment(array $payload): array {
    $secrets = impactLoadSecrets();
    $apiKey = $secrets['anthropic_api_key'];
    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'Claude API key not configured.'];
    }

    $model = $secrets['anthropic_model'] ?: 'claude-sonnet-4-20250514';

    $system = <<<'PROMPT'
You analyse youth/community programme CSV data against organiser impact goals for a UK charity dashboard.

Rules:
- Respond with valid JSON only (no markdown fences).
- Be honest when attendance counts cannot prove learning outcomes.
- Flag if rows look like one child per row rather than one session per row.
- Do not invent numbers; use only the stats and sample rows provided.
- Use plain English suitable for funders and programme leads.

JSON schema:
{
  "headline": "short headline",
  "explanation": "2-4 sentences",
  "meets_goals": "yes|partial|no|not_measurable",
  "meets_goals_label": "short badge label",
  "supports": ["bullet 1", "bullet 2"],
  "what_you_can_say": "one paragraph for funders",
  "suggested_headcount_column": "column name or null",
  "data_quality_notes": ["optional note"]
}
PROMPT;

    $userContent = "Analyse this programme data against the organiser's goals.\n\n"
        . json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    $body = json_encode([
        'model' => $model,
        'max_tokens' => 1200,
        'system' => $system,
        'messages' => [
            ['role' => 'user', 'content' => $userContent],
        ],
    ], JSON_UNESCAPED_UNICODE);

    if ($body === false) {
        return ['ok' => false, 'error' => 'Could not encode Claude request.'];
    }

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'error' => 'Claude request failed: ' . $curlError];
    }

    $parsed = json_decode($response, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        $msg = is_array($parsed) ? ($parsed['error']['message'] ?? $response) : $response;
        return ['ok' => false, 'error' => 'Claude API error (' . $httpCode . '): ' . $msg];
    }

    $text = '';
    if (is_array($parsed) && !empty($parsed['content']) && is_array($parsed['content'])) {
        foreach ($parsed['content'] as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $text .= (string)($block['text'] ?? '');
            }
        }
    }

    $llm = impactExtractJsonObject($text);
    if (!$llm) {
        return ['ok' => false, 'error' => 'Claude returned an unreadable response.'];
    }

    $meetsGoals = impactNormaliseMeetsGoals((string)($llm['meets_goals'] ?? 'not_measurable'));

    return [
        'ok' => true,
        'assessment' => [
            'headline' => trim((string)($llm['headline'] ?? '')),
            'explanation' => trim((string)($llm['explanation'] ?? '')),
            'meets_goals' => $meetsGoals,
            'meets_goals_label' => trim((string)($llm['meets_goals_label'] ?? '')) ?: impactMeetsGoalsLabel($meetsGoals),
            'supports' => array_values(array_filter(array_map('strval', $llm['supports'] ?? []))),
            'what_you_can_say' => trim((string)($llm['what_you_can_say'] ?? '')),
            'suggested_headcount_column' => $llm['suggested_headcount_column'] ?? null,
            'data_quality_notes' => array_values(array_filter(array_map('strval', $llm['data_quality_notes'] ?? []))),
            'source' => 'claude',
        ],
    ];
}

function impactEnhanceGoalAssessmentWithLlm(
    array $baseline,
    string $goalsText,
    array $summary,
    array $sessions,
    array $detectedMeta,
    array $dataWarnings,
    array $trend
): array {
    if (!impactHasClaudeKey()) {
        $baseline['llm_enabled'] = false;
        return $baseline;
    }

    $baseline['llm_enabled'] = true;

    $payload = [
        'goals_text' => $goalsText,
        'stats' => [
            'session_rows' => count($sessions),
            'unique_dates' => count(array_unique(array_map(static fn($s) => $s['session_date'], $sessions))),
            'avg_headcount' => $summary['avg_headcount'] ?? null,
            'min_headcount' => $summary['min_headcount'] ?? null,
            'max_headcount' => $summary['max_headcount'] ?? null,
            'total_headcount' => $summary['total_headcount'] ?? null,
        ],
        'detected_columns' => [
            'date_column' => $detectedMeta['date_column'] ?? null,
            'headcount_column' => $detectedMeta['headcount_column'] ?? null,
            'field_map' => $detectedMeta['field_map'] ?? [],
        ],
        'data_warnings' => $dataWarnings,
        'trend' => [
            'bucket' => $trend['bucket'] ?? null,
            'label' => $trend['label'] ?? null,
            'points' => array_slice($trend['points'] ?? [], -8),
        ],
        'sample_rows' => impactBuildLlmSample($sessions, $detectedMeta['field_map'] ?? []),
    ];

    $result = impactCallClaudeGoalAssessment($payload);
    if (!$result['ok']) {
        $baseline['llm_error'] = $result['error'];
        return $baseline;
    }

    $llm = $result['assessment'];
    if ($goalsText !== '') {
        $llm['goal_summary'] = $goalsText;
    }
    if (!empty($dataWarnings)) {
        $llm['warnings'] = $dataWarnings;
    }

    $extraNotes = $llm['data_quality_notes'] ?? [];
    if ($extraNotes) {
        $llm['warnings'] = array_values(array_unique(array_merge($llm['warnings'] ?? [], $extraNotes)));
    }

    unset($llm['data_quality_notes'], $llm['suggested_headcount_column']);

    return $llm;
}
