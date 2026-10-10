<?php
// Upload to backend/ and open in browser to diagnose 500 errors.
// DELETE this file after fixing - do not leave on production long-term.

header('Content-Type: application/json; charset=utf-8');

$report = [
    'php_version' => PHP_VERSION,
    'mysqli_loaded' => extension_loaded('mysqli'),
    'json_loaded' => extension_loaded('json'),
    'conn_local_exists' => is_file(__DIR__ . '/conn.local.php'),
    'conn_exists' => is_file(__DIR__ . '/conn.php'),
];

if (is_file(__DIR__ . '/conn.local.php')) {
    require __DIR__ . '/conn.local.php';
    $report['db_config'] = [
        'host' => isset($servername) ? $servername : null,
        'user' => isset($username) ? $username : null,
        'database' => isset($dbname) ? $dbname : null,
        'password_set' => !empty($password),
    ];

    if (extension_loaded('mysqli')) {
        mysqli_report(MYSQLI_REPORT_OFF);
        try {
            $test = new mysqli($servername, $username, $password, $dbname);
            if ($test->connect_error) {
                $report['db_connect'] = 'failed';
                $report['db_error'] = $test->connect_error;
            } else {
                $report['db_connect'] = 'ok';
                $result = $test->query('SHOW TABLES');
                $tables = [];
                if ($result) {
                    while ($row = $result->fetch_array()) {
                        $tables[] = $row[0];
                    }
                }
                $report['tables'] = $tables;
                $test->close();
            }
        } catch (Throwable $e) {
            $report['db_connect'] = 'exception';
            $report['db_error'] = $e->getMessage();
        }
    }
} else {
    $report['hint'] = 'Create conn.local.php from conn.local.php.example with your Hostinger MySQL credentials.';
}

echo json_encode($report, JSON_PRETTY_PRINT);
