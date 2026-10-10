<?php
// Shared DB connection, included by every endpoint.
// Production: copy conn.local.php.example → conn.local.php with Hostinger MySQL credentials.

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = [
    'http://localhost',
    'http://127.0.0.1',
    'http://localhost:5500',
    'http://127.0.0.1:5500',
    'http://localhost:3000',
    'http://127.0.0.1:3000',
    'http://localhost:8080',
    'http://127.0.0.1:8080',
    'https://pishnest.co.uk',
    'https://www.pishnest.co.uk',
    'http://pishnest.co.uk',
    'http://www.pishnest.co.uk',
];

$originAllowed = false;
if ($origin) {
    if (in_array($origin, $allowedOrigins, true)) {
        $originAllowed = true;
    } else {
        $parts = parse_url($origin);
        $host = strtolower((string)($parts['host'] ?? ''));
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (($scheme === 'http' || $scheme === 'https') && ($host === 'localhost' || $host === '127.0.0.1')) {
            $originAllowed = true;
        }
        if (($scheme === 'http' || $scheme === 'https') && ($host === 'pishnest.co.uk' || $host === 'www.pishnest.co.uk')) {
            $originAllowed = true;
        }
    }
}

if ($origin && $originAllowed) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
} elseif (!$origin) {
    // Same-origin requests (no Origin header) continue to work normally.
} else {
    header('Access-Control-Allow-Origin: https://pishnest.co.uk');
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Session-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (is_file(__DIR__ . '/conn.local.php')) {
    require __DIR__ . '/conn.local.php';
} else {
    $servername = 'localhost';
    $username = 'u538929207_event';
    $password = 'tMfunWt9j>7';
    $dbname = 'u538929207_event';
}

mysqli_report(MYSQLI_REPORT_OFF);

try {
    $connect = new mysqli($servername, $username, $password, $dbname);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Database connection failed.']);
    exit;
}

if ($connect->connect_error) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Database connection failed.']);
    exit;
}

$connect->set_charset('utf8mb4');

/**
 * Re-open MySQL if the connection dropped (common after long Claude API calls).
 */
function dbReconnect(mysqli &$connect): void {
    global $servername, $username, $password, $dbname;

    $alive = false;
    try {
        $alive = @$connect->ping();
    } catch (Throwable $e) {
        $alive = false;
    }

    if ($alive) {
        return;
    }

    try {
        @$connect->close();
    } catch (Throwable $e) {
        // ignore
    }

    $connect = new mysqli($servername, $username, $password, $dbname);
    if ($connect->connect_error) {
        throw new RuntimeException('Database reconnection failed.');
    }
    $connect->set_charset('utf8mb4');
}

function dbHasColumn(mysqli $connect, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (!empty($cache[$key])) {
        return true;
    }

    $tableSafe = str_replace('`', '', $table);
    $columnSafe = $connect->real_escape_string($column);
    $result = $connect->query("SHOW COLUMNS FROM `{$tableSafe}` LIKE '{$columnSafe}'");
    $cache[$key] = ($result && $result->num_rows > 0);
    return $cache[$key];
}
