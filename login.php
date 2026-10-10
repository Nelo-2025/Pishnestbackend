<?php
require_once __DIR__ . '/session-boot.php';
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
include 'conn.php';
require_once __DIR__ . '/lib/mail-otp.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

$email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
$password = (string)($_POST['password'] ?? '');

if (empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Email and password are required"]);
    exit;
}

otpEnsureSchema($connect);

$hasVerified = dbHasColumn($connect, 'users', 'email_verified');
$selectSql = $hasVerified
    ? "SELECT id, name, email, password, role, email_verified FROM users WHERE email = ? LIMIT 1"
    : "SELECT id, name, email, password, role FROM users WHERE email = ? LIMIT 1";
$stmt = $connect->prepare($selectSql);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Deliberately vague - don't reveal whether the email exists or the
    // password was wrong, to avoid making account enumeration easy.
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid email or password"]);
    $stmt->close();
    $connect->close();
    exit;
}

$user = $result->fetch_assoc();

if (password_verify($password, $user['password'])) {
    if ($hasVerified && !otpBypassEnabled() && (int) ($user['email_verified'] ?? 0) !== 1) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Please verify your email before logging in.",
            "needs_verification" => true,
            "email" => $user['email'],
        ]);
        $stmt->close();
        $connect->close();
        exit;
    }

    // Regenerate the session id on login to protect against session fixation.
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['role'] = (string) $user['role'];

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Login successful",
        "session_token" => session_id(),
        "user" => [
            "id" => (int) $user['id'],
            "name" => $user['name'],
            "email" => $user['email'],
            "role" => $user['role']
        ]
    ]);
} else {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid email or password"]);
}

$stmt->close();
$connect->close();
exit;
