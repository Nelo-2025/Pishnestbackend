<?php
// PHP 7.4+ compatible impact analysis helpers.

function impactMetricStartsWith($metric, $prefix) {
    return $prefix === '' || substr($metric, 0, strlen($prefix)) === $prefix;
}

function impactMetricValue($metric, array $sessions) {
    if (!$sessions) {
        return null;
    }

    if ($metric === 'session_count') {
        return (float)count($sessions);
    }

    if ($metric === 'avg_headcount') {
        $counts = array_values(array_filter(
            array_map(function ($s) { return isset($s['headcount']) ? $s['headcount'] : null; }, $sessions),
            function ($v) { return $v !== null; }
        ));
        if (!$counts) {
            return null;
        }
        return array_sum($counts) / count($counts);
    }

    if ($metric === 'total_headcount') {
        $counts = array_values(array_filter(
            array_map(function ($s) { return isset($s['headcount']) ? $s['headcount'] : null; }, $sessions),
            function ($v) { return $v !== null; }
        ));
        return $counts ? (float)array_sum($counts) : null;
    }

    if ($metric === 'max_headcount') {
        $counts = array_values(array_filter(
            array_map(function ($s) { return isset($s['headcount']) ? $s['headcount'] : null; }, $sessions),
            function ($v) { return $v !== null; }
        ));
        return $counts ? (float)max($counts) : null;
    }

    if (impactMetricStartsWith($metric, 'avg_field:')) {
        $field = substr($metric, strlen('avg_field:'));
        $values = [];
        foreach ($sessions as $session) {
            $attrs = isset($session['attributes']) ? $session['attributes'] : [];
            if (isset($attrs[$field]) && is_numeric($attrs[$field])) {
                $values[] = (float)$attrs[$field];
            }
        }
        return $values ? array_sum($values) / count($values) : null;
    }

    return null;
}

function impactMetricLabel($metric) {
    if ($metric === 'session_count') return 'Total sessions';
    if ($metric === 'avg_headcount') return 'Average attendance';
    if ($metric === 'total_headcount') return 'Total attendance';
    if ($metric === 'max_headcount') return 'Peak attendance';
    if (impactMetricStartsWith($metric, 'avg_field:')) {
        return 'Average ' . str_replace('_', ' ', substr($metric, strlen('avg_field:')));
    }
    return $metric;
}

function impactCompare($current, $target, $operator) {
    if ($operator === '>=') return $current >= $target;
    if ($operator === '<=') return $current <= $target;
    if ($operator === '=') return abs($current - $target) < 0.01;
    return false;
}

function impactGoalStatus(array $goal, array $sessions) {
    $metric = (string)(isset($goal['metric']) ? $goal['metric'] : '');

    if ($metric === 'free_text') {
        return [
            'goal_id' => (int)$goal['id'],
            'label' => (string)$goal['label'],
            'status' => 'stated',
            'met' => null,
            'message' => 'Your stated impact goal for this report.',
        ];
    }

    $target = (float)$goal['target_value'];
    $operator = (string)$goal['operator'];
    $current = impactMetricValue($metric, $sessions);

    if ($current === null) {
        return [
            'goal_id' => (int)$goal['id'],
            'label' => (string)$goal['label'],
            'metric' => $metric,
            'metric_label' => impactMetricLabel($metric),
            'target' => $target,
            'operator' => $operator,
            'current' => null,
            'status' => 'no_data',
            'met' => false,
            'gap' => null,
            'message' => 'Upload session data with the fields needed for this goal.',
            'projection' => null,
        ];
    }

    $met = impactCompare($current, $target, $operator);
    if ($operator === '>=') {
        $gap = $target - $current;
    } elseif ($operator === '<=') {
        $gap = $current - $target;
    } elseif ($operator === '=') {
        $gap = $target - $current;
    } else {
        $gap = 0;
    }

    $status = $met ? 'met' : 'not_met';
    $message = $met
        ? sprintf('Goal met, %s is %s (target %s %s).', (string)$goal['label'], impactFormatNumber($current), $operator, impactFormatNumber($target))
        : sprintf(
            'Not yet met, %s is %s; you need %s more to reach %s %s.',
            (string)$goal['label'],
            impactFormatNumber($current),
            impactFormatNumber(abs($gap)),
            $operator,
            impactFormatNumber($target)
        );

    $projection = null;
    $deadline = !empty($goal['deadline']) ? (string)$goal['deadline'] : null;
    if (!$met && $deadline && $metric === 'session_count') {
        $projection = impactProjectSessionsToTarget($sessions, $current, $target, $deadline);
        if ($projection) {
            $message .= ' ' . $projection['message'];
        }
    } elseif (!$met && $deadline && in_array($metric, ['avg_headcount', 'total_headcount'], true)) {
        $projection = impactProjectAttendanceGoal($sessions, $metric, $current, $target, $operator, $deadline);
        if ($projection) {
            $message .= ' ' . $projection['message'];
        }
    }

    if (!$met && $gap > 0 && $status === 'not_met' && $current > 0 && $target > 0 && $metric === 'avg_headcount') {
        $pct = min(100, round(($current / $target) * 100));
        if ($pct >= 75) {
            $status = 'on_track';
        }
    }

    return [
        'goal_id' => (int)$goal['id'],
        'label' => (string)$goal['label'],
        'metric' => $metric,
        'metric_label' => impactMetricLabel($metric),
        'target' => $target,
        'operator' => $operator,
        'current' => round($current, 2),
        'status' => $status,
        'met' => $met,
        'gap' => round($gap, 2),
        'deadline' => $deadline,
        'message' => $message,
        'projection' => $projection,
    ];
}

function impactFormatNumber($n) {
    if (abs($n - round($n)) < 0.01) {
        return (string)(int)round($n);
    }
    return number_format($n, 1);
}

function impactProjectSessionsToTarget(array $sessions, $current, $target, $deadline) {
    $remaining = max(0, $target - $current);
    if ($remaining <= 0) {
        return null;
    }

    $deadlineTs = strtotime($deadline);
    if (!$deadlineTs) {
        return null;
    }

    $daysLeft = max(1, (int)ceil(($deadlineTs - time()) / 86400));
    $weeksLeft = max(1, $daysLeft / 7);

    if (count($sessions) >= 2) {
        $first = strtotime($sessions[0]['session_date']);
        $last = strtotime($sessions[count($sessions) - 1]['session_date']);
        $weeksSpan = max(1, ($last - $first) / (7 * 86400));
        $sessionsPerWeek = count($sessions) / $weeksSpan;
    } else {
        $sessionsPerWeek = 1;
    }

    $weeksNeeded = $sessionsPerWeek > 0 ? ceil($remaining / $sessionsPerWeek) : null;
    $onPace = $weeksNeeded !== null && $weeksNeeded <= $weeksLeft;

    return [
        'sessions_remaining' => (int)$remaining,
        'weeks_left' => round($weeksLeft, 1),
        'sessions_per_week' => round($sessionsPerWeek, 2),
        'weeks_needed' => $weeksNeeded,
        'on_pace' => $onPace,
        'message' => $onPace
            ? sprintf('At your current pace (~%s sessions/week), you could hit this by the deadline.', impactFormatNumber($sessionsPerWeek))
            : sprintf('You need ~%s more sessions; at your current pace that may take ~%s weeks (deadline in ~%s weeks).', (int)$remaining, impactFormatNumber($weeksNeeded !== null ? $weeksNeeded : 0), impactFormatNumber($weeksLeft)),
    ];
}

function impactProjectAttendanceGoal(array $sessions, $metric, $current, $target, $operator, $deadline) {
    if ($operator !== '>=') {
        return null;
    }

    $deadlineTs = strtotime($deadline);
    if (!$deadlineTs) {
        return null;
    }

    if ($metric === 'avg_headcount') {
        $needed = $target - $current;
        if ($needed <= 0) {
            return null;
        }
        return [
            'message' => sprintf(
                'Raise average attendance by ~%s per session across future sessions before %s.',
                impactFormatNumber($needed),
                impactFormatDisplayDate(substr((string) $deadline, 0, 10))
            ),
        ];
    }

    return null;
}

function impactBuildTrend(array $sessions) {
    $byDate = [];
    foreach ($sessions as $session) {
        $date = $session['session_date'];
        $hc = isset($session['headcount']) ? $session['headcount'] : null;
        if (!isset($byDate[$date])) {
            $byDate[$date] = ['sum' => 0, 'count' => 0, 'rows' => 0];
        }
        $byDate[$date]['rows']++;
        if ($hc !== null) {
            $byDate[$date]['sum'] += (int)$hc;
            $byDate[$date]['count']++;
        }
    }

    ksort($byDate);
    $daily = [];
    foreach ($byDate as $date => $bucket) {
        $daily[] = [
            'date' => $date,
            'headcount' => $bucket['count'] > 0 ? round($bucket['sum'] / $bucket['count'], 1) : null,
            'sessions' => $bucket['rows'],
        ];
    }

    $pointCount = count($daily);
    $bucket = 'day';
    $label = 'Daily average';

    if ($pointCount > 31) {
        $weekly = [];
        foreach ($daily as $point) {
            $weekDt = DateTime::createFromFormat('!Y-m-d', (string) $point['date']);
            $weekKey = $weekDt ? $weekDt->format('o-\WW') : (string) $point['date'];
            if (!isset($weekly[$weekKey])) {
                $weekly[$weekKey] = ['sum' => 0, 'n' => 0, 'start' => $point['date']];
            }
            if ($point['headcount'] !== null) {
                $weekly[$weekKey]['sum'] += $point['headcount'];
                $weekly[$weekKey]['n']++;
            }
        }
        $daily = [];
        foreach ($weekly as $weekKey => $w) {
            $daily[] = [
                'date' => $w['start'],
                'headcount' => $w['n'] > 0 ? round($w['sum'] / $w['n'], 1) : null,
                'label' => $weekKey,
            ];
        }
        $bucket = 'week';
        $label = 'Weekly average';
    }

    if (count($daily) > 24) {
        $daily = array_slice($daily, -24);
        $trimmed = true;
    } else {
        $trimmed = false;
    }

    return [
        'bucket' => $bucket,
        'label' => $label,
        'trimmed' => $trimmed,
        'points' => $daily,
    ];
}

function impactAssessDataQuality(array $sessions, array $detected) {
    $warnings = [];
    $headcountCol = isset($detected['headcount_column']) ? (string)$detected['headcount_column'] : '';

    if ($headcountCol !== '' && preg_match('/\b(age|year|grade|year_group)\b/i', $headcountCol)) {
        $warnings[] = 'The column "' . $headcountCol . '" looks like student age, not group attendance. Numbers labelled "attendance" in this report may be misleading, add a headcount or group_size column to your CSV.';
    }

    $dates = [];
    foreach ($sessions as $session) {
        $dates[] = $session['session_date'];
    }
    $uniqueDates = count(array_unique($dates));
    $totalRows = count($sessions);
    if ($totalRows > 0 && $uniqueDates > 0 && $totalRows / $uniqueDates >= 3) {
        $warnings[] = sprintf(
            'Your file has %d rows but only %d unique dates. Each row may be one child/participant rather than one session. The chart groups by %s to make trends readable.',
            $totalRows,
            $uniqueDates,
            $totalRows > 31 ? 'week' : 'day'
        );
    }

    if ($headcountCol === '') {
        $warnings[] = 'No attendance column was detected. Session counts are still shown, but attendance charts and averages are unavailable.';
    }

    foreach ($sessions as $session) {
        $note = trim((string) (($session['attributes']['data_note'] ?? '')));
        if ($note !== '') {
            $warnings[] = $note;
            break;
        }
    }

    return $warnings;
}

function impactBuildGoalAssessment($goalsText, array $summary, array $sessions, array $warnings) {
    $goalText = trim((string)$goalsText);
    $sessionCount = count($sessions);
    $uniqueDates = count(array_unique(array_map(function ($s) {
        return $s['session_date'];
    }, $sessions)));

    $supports = [];
    $supports[] = sprintf('You recorded %d rows of activity data across %d different dates.', $sessionCount, $uniqueDates);

    if (!empty($summary['avg_headcount'])) {
        $supports[] = sprintf('The numeric column we mapped averages %s (range %s to %s).', $summary['avg_headcount'], $summary['min_headcount'] ?? '?', $summary['max_headcount'] ?? '?');
    }

    $assessment = [
        'headline' => 'Your goals describe educational impact, not something attendance alone can prove.',
        'explanation' => 'Goals like expanding understanding of habitats or sparking curiosity about space are outcomes you observe in children. This report counts sessions and patterns from your CSV. Click Analyze with your Claude API key configured to get an AI-written assessment of how well the data supports your goals.',
        'meets_goals' => 'not_measurable_yet',
        'meets_goals_label' => 'Cannot auto-score yet',
        'supports' => $supports,
        'warnings' => $warnings,
    ];

    if ($goalText !== '') {
        $assessment['goal_summary'] = $goalText;
        $assessment['what_you_can_say'] = sprintf(
            'For funders you can say: over this period we ran activity on %d days, logged %d participant records, and our stated aims were: %s',
            $uniqueDates,
            $sessionCount,
            $goalText
        );
    }

    return $assessment;
}

function impactBuildSummary(array $sessions, array $goalResults) {
    $counts = array_values(array_filter(
        array_map(function ($s) { return isset($s['headcount']) ? $s['headcount'] : null; }, $sessions),
        function ($v) { return $v !== null; }
    ));

    $met = count(array_filter($goalResults, function ($g) { return !empty($g['met']); }));
    $totalGoals = count($goalResults);

    $dateFrom = $sessions[0]['session_date'] ?? null;
    $dateTo = $sessions[count($sessions) - 1]['session_date'] ?? null;
    $dateRangeLabel = '';
    if ($dateFrom && $dateTo) {
        $dateRangeLabel = $dateFrom === $dateTo
            ? impactFormatDisplayDate($dateFrom)
            : impactFormatDisplayDate($dateFrom) . ' to ' . impactFormatDisplayDate($dateTo);
    }

    $lines = [];
    $lines[] = sprintf('You logged %d session%s.', count($sessions), count($sessions) === 1 ? '' : 's');
    if ($dateRangeLabel !== '') {
        $lines[] = 'Imported data covers ' . $dateRangeLabel . '.';
    }

    if ($counts) {
        $avg = array_sum($counts) / count($counts);
        $lines[] = sprintf(
            'The mapped numeric column ranges from %d to %d, averaging %s.',
            min($counts),
            max($counts),
            impactFormatNumber($avg)
        );
    } else {
        $lines[] = 'No numeric attendance column was detected, session counts are still shown below.';
    }

    if ($totalGoals === 0) {
        $lines[] = 'Add your impact goals above to include them in this report.';
    } else {
        $lines[] = 'Your stated goals are included below alongside the session data.';
    }

    return [
        'paragraph' => implode(' ', $lines),
        'goals_met' => $met,
        'goals_total' => $totalGoals,
        'session_count' => count($sessions),
        'avg_headcount' => $counts ? round(array_sum($counts) / count($counts), 2) : null,
        'min_headcount' => $counts ? min($counts) : null,
        'max_headcount' => $counts ? max($counts) : null,
        'total_headcount' => $counts ? array_sum($counts) : null,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'date_range_label' => $dateRangeLabel,
        'unique_dates' => count(array_unique(array_map(static function ($s) {
            return $s['session_date'] ?? '';
        }, $sessions))),
    ];
}

function impactAvailableMetrics(array $detected) {
    $metrics = [
        ['value' => 'session_count', 'label' => 'Total sessions logged'],
        ['value' => 'avg_headcount', 'label' => 'Average attendance per session'],
        ['value' => 'total_headcount', 'label' => 'Total attendance (all sessions)'],
        ['value' => 'max_headcount', 'label' => 'Peak attendance (single session)'],
    ];

    $numericFields = isset($detected['numeric_fields']) ? $detected['numeric_fields'] : [];
    foreach ($numericFields as $field) {
        $metrics[] = [
            'value' => 'avg_field:' . $field,
            'label' => 'Average ' . str_replace('_', ' ', $field),
        ];
    }

    return $metrics;
}

function impactHumanizeField(string $field): string {
    $label = str_replace('_', ' ', $field);
    return ucwords($label);
}

function impactFieldChartScore(string $field): int {
    $score = 0;
    if (preg_match('/topic|theme|activity|programme|program|subject|category|type|venue|location|site|excursion|trip/i', $field)) {
        $score += 10;
    }
    if (preg_match('/name|id|email|phone|date|age|year|grade|count|headcount|notes|comment/i', $field)) {
        $score -= 5;
    }
    return $score;
}

function impactCountFieldValues(array $sessions, string $field): array {
    $counts = [];
    foreach ($sessions as $session) {
        $attrs = isset($session['attributes']) ? $session['attributes'] : [];
        if (!array_key_exists($field, $attrs)) {
            continue;
        }
        $val = trim((string)$attrs[$field]);
        if ($val === '') {
            continue;
        }
        if (!isset($counts[$val])) {
            $counts[$val] = 0;
        }
        $counts[$val]++;
    }
    return $counts;
}

function impactPieFromCounts(string $title, string $field, array $counts, $labelFn = null): ?array {
    if (count($counts) < 2) {
        return null;
    }

    arsort($counts);
    $total = array_sum($counts);
    if ($total <= 0) {
        return null;
    }

    $maxSlices = 6;
    $slices = [];
    $other = 0;
    $i = 0;

    foreach ($counts as $label => $value) {
        if ($i >= $maxSlices) {
            $other += $value;
            continue;
        }
        $displayLabel = $labelFn ? $labelFn($label) : (string)$label;
        $slices[] = [
            'label' => $displayLabel,
            'value' => (int)$value,
            'percent' => round($value / $total * 100, 1),
        ];
        $i++;
    }

    if ($other > 0) {
        $slices[] = [
            'label' => 'Other',
            'value' => $other,
            'percent' => round($other / $total * 100, 1),
        ];
    }

    return [
        'title' => $title,
        'field' => $field,
        'total' => $total,
        'slices' => $slices,
    ];
}

function impactBuildPieCharts(array $sessions, array $fieldMap): array {
    if (!$sessions) {
        return [];
    }

    $charts = [];
    $byMonth = [];
    foreach ($sessions as $session) {
        $ts = strtotime($session['session_date']);
        if (!$ts) {
            continue;
        }
        $monthKey = date('Y-m', $ts);
        if (!isset($byMonth[$monthKey])) {
            $byMonth[$monthKey] = 0;
        }
        $byMonth[$monthKey]++;
    }

    if (count($byMonth) >= 2) {
        ksort($byMonth);
        $monthChart = impactPieFromCounts(
            'Records by month',
            'session_month',
            $byMonth,
            function ($key) {
                $ts = strtotime($key . '-01');
                return $ts ? date('M Y', $ts) : (string)$key;
            }
        );
        if ($monthChart) {
            $charts[] = $monthChart;
        }
    }

    $candidates = [];
    foreach ($fieldMap as $name => $type) {
        if (preg_match('/date|headcount|attendance|count|age|year|grade|group_size/i', $name)) {
            continue;
        }
        if (!in_array($type, ['categorical', 'boolean'], true)) {
            if ($type !== 'text') {
                continue;
            }
        }

        $counts = impactCountFieldValues($sessions, $name);
        $cardinality = count($counts);
        if ($cardinality < 2 || $cardinality > 12) {
            continue;
        }

        $candidates[] = [
            'name' => $name,
            'counts' => $counts,
            'score' => impactFieldChartScore($name) + min($cardinality, 6),
        ];
    }

    usort($candidates, function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    foreach (array_slice($candidates, 0, 2) as $candidate) {
        $chart = impactPieFromCounts(
            impactHumanizeField($candidate['name']),
            $candidate['name'],
            $candidate['counts']
        );
        if ($chart) {
            $charts[] = $chart;
        }
    }

    return $charts;
}
