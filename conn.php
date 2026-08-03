<?php
// Shared DB connection, included by every endpoint.
// Support local dev frontends served from XAMPP or VS Code Live Server,
// while keeping cookie-based session auth usable via credentials.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = [
    'http://localhost',
    'http://127.0.0.1',
    'http://localhost:5500',
    'http://127.0.0.1:5500'
];

if ($origin && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
} else {
    // Same-origin requests (no Origin header) continue to work normally.
    header('Access-Control-Allow-Origin: http://localhost');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "eventbrite";

$connect = new mysqli($servername, $username, $password, $dbname);

if ($connect->connect_error) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(["success" => false, "error" => "Database connection failed."]);
    exit;
}

$connect->set_charset('utf8mb4');
