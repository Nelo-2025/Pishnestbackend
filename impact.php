<?php
require_once __DIR__ . '/session-boot.php';
header('Content-Type: application/json; charset=utf-8');

include 'conn.php';
include 'auth.php';
require_once __DIR__ . '/lib/impact-parser.php';
require_once __DIR__ . '/lib/impact-analyzer.php';
require_once __DIR__ . '/lib/impact-llm.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$organiser_id = requireOrganiser($connect);

function impactEnsureTables(mysqli $connect): void {
    $statements = [
        "CREATE TABLE IF NOT EXISTS organiser_impact_goals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            organiser_id INT NOT NULL,
            label TEXT NOT NULL,
            metric VARCHAR(128) NOT NULL DEFAULT 'free_text',
            target_value DECIMAL(12, 2) NOT NULL DEFAULT 0,
            operator ENUM('>=', '<=', '=') NOT NULL DEFAULT '>=',
            deadline DATE NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_impact_goals_organiser (organiser_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS organiser_session_uploads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            organiser_id INT NOT NULL,
            original_filename VARCHAR(255) NOT NULL,
            stored_path VARCHAR(512) NOT NULL,
            row_count INT NOT NULL DEFAULT 0,
            detected_json LONGTEXT NULL,
            uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_impact_uploads_organiser (organiser_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS organiser_impact_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            organiser_id INT NOT NULL,
            upload_id INT NULL,
            goals_text TEXT NOT NULL,
            original_filename VARCHAR(255) NULL,
            row_count INT NOT NULL DEFAULT 0,
            report_json LONGTEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_impact_reports_organiser (organiser_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS organiser_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            organiser_id INT NOT NULL,
            upload_id INT NULL,
            session_date DATE NOT NULL,
            headcount INT NULL,
            attributes JSON NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_impact_sessions_organiser_date (organiser_id, session_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS organiser_field_map (
            id INT AUTO_INCREMENT PRIMARY KEY,
            organiser_id INT NOT NULL,
            field_name VARCHAR(128) NOT NULL,
            field_type ENUM('boolean', 'date', 'numeric', 'categorical', 'text', 'ignore') NOT NULL,
            UNIQUE KEY uq_impact_field_map (organiser_id, field_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($statements as $statement) {
        if (!$connect->query($statement)) {
            throw new RuntimeException('Database setup failed: ' . $connect->error);
        }
    }

    impactEnsureColumn($connect, 'organiser_session_uploads', 'detected_json', 'detected_json LONGTEXT NULL');
}

function impactDbPrepare(mysqli $connect, string $sql, string $context = 'query'): mysqli_stmt {
    $stmt = $connect->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException('Database prepare failed (' . $context . '): ' . $connect->error);
    }
    return $stmt;
}

function impactEnsureColumn(mysqli $connect, string $table, string $column, string $definition): void {
    $safeTable = preg_replace('/[^a-z0-9_]/', '', $table);
    $safeColumn = preg_replace('/[^a-z0-9_]/', '', $column);
    $result = $connect->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    if ($result && $result->num_rows === 0) {
        if (!$connect->query("ALTER TABLE `{$safeTable}` ADD COLUMN {$definition}")) {
            throw new RuntimeException('Database migration failed: ' . $connect->error);
        }
    }
}

function impactOrganiserHasSessions(mysqli $connect, int $organiser_id): bool {
    $stmt = impactDbPrepare($connect,
        'SELECT 1 FROM organiser_sessions WHERE organiser_id = ? LIMIT 1',
        'check sessions exist'
    );
    $stmt->bind_param('i', $organiser_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (bool)$row;
}

function impactLoadSessions(mysqli $connect, int $organiser_id, ?int $upload_id = null): array {
    if ($upload_id === null) {
        $latest = impactLoadLatestUpload($connect, $organiser_id);
        $upload_id = $latest ? (int)$latest['id'] : null;
    }

    if ($upload_id === null) {
        return [];
    }

    $stmt = impactDbPrepare($connect,
        'SELECT session_date, headcount, attributes
         FROM organiser_sessions
         WHERE organiser_id = ? AND upload_id = ?
         ORDER BY session_date ASC',
        'load sessions'
    );
    $stmt->bind_param('ii', $organiser_id, $upload_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!$rows && $upload_id !== null) {
        $legacy = impactDbPrepare($connect,
            'SELECT session_date, headcount, attributes
             FROM organiser_sessions
             WHERE organiser_id = ? AND upload_id IS NULL
             ORDER BY session_date ASC',
            'load legacy sessions'
        );
        $legacy->bind_param('i', $organiser_id);
        $legacy->execute();
        $rows = $legacy->get_result()->fetch_all(MYSQLI_ASSOC);
        $legacy->close();
    }

    $sessions = [];
    foreach ($rows as $row) {
        $attrs = [];
        if (!empty($row['attributes'])) {
            $decoded = json_decode((string)$row['attributes'], true);
            if (is_array($decoded)) {
                $attrs = $decoded;
            }
        }
        $sessions[] = [
            'session_date' => $row['session_date'],
            'headcount' => $row['headcount'] !== null ? (int)$row['headcount'] : null,
            'attributes' => $attrs,
        ];
    }

    return $sessions;
}

function impactLoadGoals(mysqli $connect, int $organiser_id): array {
    $stmt = $connect->prepare(
        'SELECT id, label, metric, target_value, operator, deadline, created_at
         FROM organiser_impact_goals
         WHERE organiser_id = ?
         ORDER BY created_at ASC'
    );
    $stmt->bind_param('i', $organiser_id);
    $stmt->execute();
    $goals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $goals;
}

function impactLoadFieldMap(mysqli $connect, int $organiser_id): array {
    $stmt = $connect->prepare(
        'SELECT field_name, field_type FROM organiser_field_map WHERE organiser_id = ?'
    );
    $stmt->bind_param('i', $organiser_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $map = [];
    foreach ($rows as $row) {
        $map[$row['field_name']] = $row['field_type'];
    }
    return $map;
}

function impactLoadLatestUpload(mysqli $connect, int $organiser_id): ?array {
    $stmt = impactDbPrepare($connect,
        'SELECT id, original_filename, row_count, detected_json, uploaded_at
         FROM organiser_session_uploads
         WHERE organiser_id = ?
         ORDER BY uploaded_at DESC
         LIMIT 1',
        'load latest upload'
    );
    $stmt->bind_param('i', $organiser_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function impactLoadUploadById(mysqli $connect, int $organiser_id, int $upload_id): ?array {
    $stmt = impactDbPrepare($connect,
        'SELECT id, original_filename, row_count, detected_json, uploaded_at
         FROM organiser_session_uploads
         WHERE organiser_id = ? AND id = ?
         LIMIT 1',
        'load upload by id'
    );
    $stmt->bind_param('ii', $organiser_id, $upload_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function impactDetectedFromUpload(?array $upload, array $fieldMapFallback = []): array {
    $detected = [
        'date_column' => null,
        'headcount_column' => null,
        'numeric_fields' => [],
        'field_map' => $fieldMapFallback,
    ];

    if (!$upload || empty($upload['detected_json'])) {
        return $detected;
    }

    $decoded = json_decode((string)$upload['detected_json'], true);
    if (!is_array($decoded)) {
        return $detected;
    }

    if (!empty($decoded['date_column'])) {
        $detected['date_column'] = $decoded['date_column'];
    }
    if (!empty($decoded['headcount_column'])) {
        $detected['headcount_column'] = $decoded['headcount_column'];
    }
    if (!empty($decoded['numeric_fields']) && is_array($decoded['numeric_fields'])) {
        $detected['numeric_fields'] = $decoded['numeric_fields'];
    }
    if (!empty($decoded['field_map']) && is_array($decoded['field_map'])) {
        $detected['field_map'] = $decoded['field_map'];
    }

    return $detected;
}

function impactCountReports(mysqli $connect, int $organiser_id): int {
    dbReconnect($connect);
    $stmt = impactDbPrepare($connect,
        'SELECT COUNT(*) AS total FROM organiser_impact_reports WHERE organiser_id = ?',
        'count reports'
    );
    $stmt->bind_param('i', $organiser_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['total'] ?? 0);
}

function impactReportPagination(mysqli $connect, int $organiser_id, int $page, int $perPage = 12): array {
    $total = impactCountReports($connect, $organiser_id);
    return [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => max(1, (int)ceil($total / max(1, $perPage))),
    ];
}

function impactListReportHistory(mysqli $connect, int $organiser_id, int $page = 1, int $perPage = 12): array {
    dbReconnect($connect);

    $page = max(1, $page);
    $perPage = max(1, min(50, $perPage));
    $offset = ($page - 1) * $perPage;

    $stmt = impactDbPrepare($connect,
        'SELECT id, upload_id, goals_text, original_filename, row_count, created_at
         FROM organiser_impact_reports
         WHERE organiser_id = ?
         ORDER BY created_at DESC
         LIMIT ' . $perPage . ' OFFSET ' . $offset,
        'list report history'
    );
    $stmt->bind_param('i', $organiser_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $history = [];
    foreach ($rows as $row) {
        $goals = trim((string)$row['goals_text']);
        $preview = $goals;
        if (strlen($preview) > 90) {
            $preview = substr($preview, 0, 87) . '…';
        }

        $history[] = [
            'id' => (int)$row['id'],
            'upload_id' => $row['upload_id'] !== null ? (int)$row['upload_id'] : null,
            'created_at' => $row['created_at'],
            'goals_preview' => $preview,
            'original_filename' => $row['original_filename'],
            'row_count' => (int)$row['row_count'],
        ];
    }

    return $history;
}

function impactHydrateReportDates(mysqli $connect, int $organiser_id, array $row, array $report): array {
    $report['report_id'] = (int) $row['id'];
    $uploadId = isset($row['upload_id']) && $row['upload_id'] !== null ? (int) $row['upload_id'] : null;
    if ($uploadId === null && !empty($report['upload']['id'])) {
        $uploadId = (int) $report['upload']['id'];
    }
    if (!$uploadId) {
        return $report;
    }

    $sessions = impactLoadSessions($connect, $organiser_id, $uploadId);
    if (!$sessions) {
        return $report;
    }

    $dates = [];
    foreach ($sessions as $session) {
        if (!empty($session['session_date'])) {
            $dates[] = $session['session_date'];
        }
    }
    sort($dates);
    if (!$dates) {
        return $report;
    }

    $from = $dates[0];
    $to = $dates[count($dates) - 1];
    if (!isset($report['summary']) || !is_array($report['summary'])) {
        $report['summary'] = [];
    }
    $report['summary']['date_from'] = $from;
    $report['summary']['date_to'] = $to;
    $report['summary']['date_range_label'] = $from === $to
        ? impactFormatDisplayDate($from)
        : impactFormatDisplayDate($from) . ' to ' . impactFormatDisplayDate($to);
    $report['unique_dates'] = count(array_unique($dates));
    $report['sessions_count'] = count($sessions);
    return $report;
}

function impactLoadReportSnapshot(mysqli $connect, int $organiser_id, int $report_id): ?array {
    $stmt = $connect->prepare(
        'SELECT id, upload_id, report_json FROM organiser_impact_reports WHERE id = ? AND organiser_id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $report_id, $organiser_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    $report = json_decode((string)$row['report_json'], true);
    if (!is_array($report)) {
        return null;
    }

    return [
        'id' => (int)$row['id'],
        'report' => impactHydrateReportDates($connect, $organiser_id, $row, $report),
    ];
}

function impactLoadLatestReportSnapshot(mysqli $connect, int $organiser_id): ?array {
    $stmt = $connect->prepare(
        'SELECT id, upload_id, report_json FROM organiser_impact_reports
         WHERE organiser_id = ?
         ORDER BY created_at DESC
         LIMIT 1'
    );
    $stmt->bind_param('i', $organiser_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    $report = json_decode((string)$row['report_json'], true);
    if (!is_array($report)) {
        return null;
    }

    return [
        'id' => (int)$row['id'],
        'report' => impactHydrateReportDates($connect, $organiser_id, $row, $report),
    ];
}

function impactSaveReportSnapshot(
    mysqli $connect,
    int $organiser_id,
    ?int $upload_id,
    string $goalsText,
    array $report
): int {
    dbReconnect($connect);

    $upload = $report['upload'] ?? null;
    $filename = $upload ? (string)($upload['original_filename'] ?? '') : '';
    $rowCount = $upload ? (int)($upload['row_count'] ?? 0) : (int)($report['sessions_count'] ?? 0);
    if ($rowCount <= 0) {
        $rowCount = (int)($report['sessions_count'] ?? 0);
    }
    $uploadIdParam = $upload_id;

    $json = json_encode($report, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Could not save impact report snapshot.');
    }

    $stmt = impactDbPrepare($connect,
        'INSERT INTO organiser_impact_reports
         (organiser_id, upload_id, goals_text, original_filename, row_count, report_json)
         VALUES (?, ?, ?, ?, ?, ?)',
        'save report snapshot'
    );
    $stmt->bind_param('iissis', $organiser_id, $uploadIdParam, $goalsText, $filename, $rowCount, $json);
    if (!$stmt->execute()) {
        $err = $stmt->error ?: $connect->error;
        $stmt->close();
        throw new RuntimeException('Could not save impact report: ' . $err);
    }
    $reportId = (int)$connect->insert_id;
    $stmt->close();

    return $reportId;
}

function impactSaveFieldMap(mysqli $connect, int $organiser_id, array $fieldMap): void {
    $del = impactDbPrepare($connect, 'DELETE FROM organiser_field_map WHERE organiser_id = ?', 'clear field map');
    $del->bind_param('i', $organiser_id);
    $del->execute();
    $del->close();

    if (!$fieldMap) {
        return;
    }

    $ins = impactDbPrepare($connect,
        'INSERT INTO organiser_field_map (organiser_id, field_name, field_type) VALUES (?, ?, ?)',
        'save field map'
    );
    foreach ($fieldMap as $name => $type) {
        $ins->bind_param('iss', $organiser_id, $name, $type);
        $ins->execute();
    }
    $ins->close();
}

function impactBuildReport(mysqli $connect, int $organiser_id, ?int $upload_id = null, bool $runLlm = true): array {
    $latestUpload = $upload_id
        ? impactLoadUploadById($connect, $organiser_id, $upload_id)
        : impactLoadLatestUpload($connect, $organiser_id);

    if ($latestUpload) {
        $upload_id = (int)$latestUpload['id'];
    }

    $sessions = impactLoadSessions($connect, $organiser_id, $upload_id);
    $goals = impactLoadGoals($connect, $organiser_id);
    $fieldMapFallback = impactLoadFieldMap($connect, $organiser_id);
    $detectedMeta = impactDetectedFromUpload($latestUpload, $fieldMapFallback);

    if (empty($detectedMeta['numeric_fields'])) {
        foreach ($detectedMeta['field_map'] as $name => $type) {
            if ($type === 'numeric' && !preg_match('/headcount|attendance|count/i', $name)) {
                $detectedMeta['numeric_fields'][] = $name;
            }
        }
    }

    $goalResults = array_map(
        static fn($goal) => impactGoalStatus($goal, $sessions),
        $goals
    );

    $dataWarnings = impactAssessDataQuality($sessions, $detectedMeta);
    $goalsText = $goals ? (string)$goals[0]['label'] : '';
    $summary = impactBuildSummary($sessions, $goalResults);
    $trend = impactBuildTrend($sessions);
    $pieCharts = impactBuildPieCharts($sessions, $detectedMeta['field_map'] ?? []);
    $baselineAssessment = impactBuildGoalAssessment($goalsText, $summary, $sessions, $dataWarnings);
    $goalAssessment = $runLlm
        ? impactEnhanceGoalAssessmentWithLlm(
            $baselineAssessment,
            $goalsText,
            $summary,
            $sessions,
            $detectedMeta,
            $dataWarnings,
            $trend
        )
        : $baselineAssessment;

    return [
        'success' => true,
        'upload' => $latestUpload ? [
            'id' => (int)$latestUpload['id'],
            'original_filename' => $latestUpload['original_filename'],
            'row_count' => (int)$latestUpload['row_count'],
            'uploaded_at' => $latestUpload['uploaded_at'],
        ] : null,
        'upload_id' => $upload_id,
        'sessions_count' => count($sessions),
        'unique_dates' => count(array_unique(array_map(static fn($s) => $s['session_date'], $sessions))),
        'goals_text' => $goalsText,
        'detected' => $detectedMeta,
        'data_warnings' => $dataWarnings,
        'summary' => $summary,
        'trend' => $trend,
        'pie_charts' => $pieCharts,
        'goal_assessment' => $goalAssessment,
        'llm_configured' => impactHasClaudeKey(),
        'goals' => $goals,
        'goal_results' => $goalResults,
    ];
}

function impactImportCsvFile(mysqli $connect, int $organiser_id, array $file): array {
    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed. Please try again.');
    }

    $originalName = basename((string)$file['name']);
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, impactAllowedUploadExtensions(), true)) {
        if ($ext === 'xls') {
            throw new RuntimeException('Older Excel (.xls) files are not supported. Save the sheet as .xlsx or CSV and try again.');
        }
        if ($ext === 'doc') {
            throw new RuntimeException('Older Word (.doc) files are not supported. Save the document as .docx or CSV and try again.');
        }
        throw new RuntimeException(impactUploadHelpMessage());
    }

    $storageDir = __DIR__ . '/storage/impact';
    if (!is_dir($storageDir)) {
        mkdir($storageDir, 0755, true);
    }

    $storedName = sprintf('org_%d_%s.%s', $organiser_id, bin2hex(random_bytes(8)), $ext);
    $storedPath = $storageDir . '/' . $storedName;

    if (!move_uploaded_file($file['tmp_name'], $storedPath)) {
        throw new RuntimeException('Could not save uploaded file.');
    }

    try {
        $parsed = impactParseUpload($storedPath, $ext);
    } catch (Throwable $e) {
        @unlink($storedPath);
        throw $e;
    }

    $detectedPayload = json_encode([
        'date_column' => $parsed['detected']['date_column'] ?? null,
        'headcount_column' => $parsed['detected']['headcount_column'] ?? null,
        'numeric_fields' => $parsed['detected']['numeric_fields'] ?? [],
        'field_map' => $parsed['field_map'],
    ], JSON_UNESCAPED_UNICODE);

    $connect->begin_transaction();
    try {
        $uploadStmt = impactDbPrepare($connect,
            'INSERT INTO organiser_session_uploads (organiser_id, original_filename, stored_path, row_count, detected_json)
             VALUES (?, ?, ?, ?, ?)',
            'save upload'
        );
        $rowCount = count($parsed['sessions']);
        $uploadStmt->bind_param('issis', $organiser_id, $originalName, $storedPath, $rowCount, $detectedPayload);
        if (!$uploadStmt->execute()) {
            throw new RuntimeException('Could not save upload record: ' . $uploadStmt->error);
        }
        $upload_id = (int)$connect->insert_id;
        $uploadStmt->close();

        $sessionStmtWithHeadcount = impactDbPrepare($connect,
            'INSERT INTO organiser_sessions (organiser_id, upload_id, session_date, headcount, attributes)
             VALUES (?, ?, ?, ?, ?)',
            'insert session with headcount'
        );
        $sessionStmtNullHeadcount = impactDbPrepare($connect,
            'INSERT INTO organiser_sessions (organiser_id, upload_id, session_date, headcount, attributes)
             VALUES (?, ?, ?, NULL, ?)',
            'insert session without headcount'
        );

        foreach ($parsed['sessions'] as $session) {
            $attrsJson = json_encode($session['attributes'], JSON_UNESCAPED_UNICODE);
            $headcount = $session['headcount'];
            $sessionDate = $session['session_date'];

            if ($headcount === null) {
                $sessionStmtNullHeadcount->bind_param('iiss', $organiser_id, $upload_id, $sessionDate, $attrsJson);
                if (!$sessionStmtNullHeadcount->execute()) {
                    throw new RuntimeException($connect->error);
                }
                continue;
            }

            $headcountInt = (int)$headcount;
            $sessionStmtWithHeadcount->bind_param('iisis', $organiser_id, $upload_id, $sessionDate, $headcountInt, $attrsJson);
            if (!$sessionStmtWithHeadcount->execute()) {
                throw new RuntimeException($connect->error);
            }
        }
        $sessionStmtWithHeadcount->close();
        $sessionStmtNullHeadcount->close();

        impactSaveFieldMap($connect, $organiser_id, $parsed['field_map']);
        $connect->commit();
    } catch (Throwable $e) {
        $connect->rollback();
        @unlink($storedPath);
        throw new RuntimeException('Could not store session data: ' . $e->getMessage());
    }

    return [
        'upload_id' => $upload_id,
        'filename' => $originalName,
        'row_count' => count($parsed['sessions']),
        'detected' => $parsed['detected'],
    ];
}

function impactSaveGoalsText(mysqli $connect, int $organiser_id, string $goalsText): void {
    $del = impactDbPrepare($connect, 'DELETE FROM organiser_impact_goals WHERE organiser_id = ?', 'clear goals');
    $del->bind_param('i', $organiser_id);
    $del->execute();
    $del->close();

    $metric = 'free_text';
    $target = 0.0;
    $operator = '>=';

    $stmt = impactDbPrepare($connect,
        'INSERT INTO organiser_impact_goals (organiser_id, label, metric, target_value, operator, deadline)
         VALUES (?, ?, ?, ?, ?, NULL)',
        'save goals'
    );
    $stmt->bind_param('issds', $organiser_id, $goalsText, $metric, $target, $operator);
    if (!$stmt->execute()) {
        $err = $stmt->error ?: $connect->error;
        $stmt->close();
        throw new RuntimeException('Could not save your goals: ' . $err);
    }
    $stmt->close();
}

try {
    impactEnsureTables($connect);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    $connect->close();
    exit;
}

$action = '';
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        if ($reportId) {
            $snapshot = impactLoadReportSnapshot($connect, $organiser_id, $reportId);
            if (!$snapshot) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Report not found.']);
                $connect->close();
                exit;
            }
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 12;
            $history = impactListReportHistory($connect, $organiser_id, $page, $perPage);
            $report = $snapshot['report'];
            $report['report_id'] = $reportId;
            $report['report_history'] = $history;
            $report['pagination'] = impactReportPagination($connect, $organiser_id, $page, $perPage);
            echo json_encode($report);
            $connect->close();
            exit;
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 12;
        $history = impactListReportHistory($connect, $organiser_id, $page, $perPage);
        echo json_encode([
            'success' => true,
            'report_history' => $history,
            'has_sessions' => impactOrganiserHasSessions($connect, $organiser_id),
            'llm_configured' => impactHasClaudeKey(),
            'pagination' => impactReportPagination($connect, $organiser_id, $page, $perPage),
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $connect->close();
    exit;
}

if (!empty($_POST['action'])) {
    $action = strtolower(trim((string)$_POST['action']));
} else {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json) && !empty($json['action'])) {
        $action = strtolower(trim((string)$json['action']));
        $_POST = array_merge($_POST, $json);
    }
}

if ($action === 'analyze') {
    $goalsText = trim((string)($_POST['goals'] ?? ''));

    if ($goalsText === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Describe your impact goals before analysing.']);
        $connect->close();
        exit;
    }

    $importMeta = null;
    $uploadId = null;
    if (!empty($_FILES['session_file']) && is_uploaded_file($_FILES['session_file']['tmp_name'])) {
        try {
            $importMeta = impactImportCsvFile($connect, $organiser_id, $_FILES['session_file']);
            $uploadId = (int)$importMeta['upload_id'];
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            $connect->close();
            exit;
        }
    } else {
        $latestUpload = impactLoadLatestUpload($connect, $organiser_id);
        $uploadId = $latestUpload ? (int)$latestUpload['id'] : null;
    }

    $sessions = impactLoadSessions($connect, $organiser_id, $uploadId);
    if (!$sessions) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Upload a CSV, Excel, or Word file with your session data.']);
        $connect->close();
        exit;
    }

    try {
        impactSaveGoalsText($connect, $organiser_id, $goalsText);
        $report = impactBuildReport($connect, $organiser_id, $uploadId, true);
        if ($importMeta) {
            $report['upload_message'] = sprintf(
                'Imported %d sessions from %s.',
                $importMeta['row_count'],
                $importMeta['filename']
            );
        }
        dbReconnect($connect);
        $reportId = impactSaveReportSnapshot($connect, $organiser_id, $uploadId, $goalsText, $report);
        $report['report_id'] = $reportId;
        $report['report_history'] = impactListReportHistory($connect, $organiser_id, 1, 12);
        $report['pagination'] = impactReportPagination($connect, $organiser_id, 1, 12);
        echo json_encode($report);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $connect->close();
    exit;
}

if ($action === 'upload') {
    if (empty($_FILES['session_file']) || !is_uploaded_file($_FILES['session_file']['tmp_name'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Choose a CSV, Excel, or Word file to upload.']);
        $connect->close();
        exit;
    }

    try {
        $importMeta = impactImportCsvFile($connect, $organiser_id, $_FILES['session_file']);
        $report = impactBuildReport($connect, $organiser_id);
        $report['upload_message'] = sprintf(
            'Imported %d sessions from %s.',
            $importMeta['row_count'],
            $importMeta['filename']
        );
        $report['detected']['date_column'] = $importMeta['detected']['date_column'];
        $report['detected']['headcount_column'] = $importMeta['detected']['headcount_column'];
        $report['detected']['numeric_fields'] = $importMeta['detected']['numeric_fields'];
        echo json_encode($report);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $connect->close();
    exit;
}

if ($action === 'save_goal') {
    $label = trim((string)($_POST['label'] ?? ''));
    if ($label === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Goal text is required.']);
        $connect->close();
        exit;
    }

    try {
        impactSaveGoalsText($connect, $organiser_id, $label);
        echo json_encode(impactBuildReport($connect, $organiser_id));
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $connect->close();
    exit;
}

if ($action === 'delete_goal') {
    $goalId = filter_var($_POST['goal_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$goalId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Goal id is required.']);
        $connect->close();
        exit;
    }

    try {
        $stmt = $connect->prepare('DELETE FROM organiser_impact_goals WHERE id = ? AND organiser_id = ?');
        $stmt->bind_param('ii', $goalId, $organiser_id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(impactBuildReport($connect, $organiser_id));
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $connect->close();
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action.']);
$connect->close();
exit;
