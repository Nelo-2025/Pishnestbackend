<?php
require_once __DIR__ . '/session-boot.php';
header('Content-Type: application/json; charset=utf-8');
include 'conn.php';
require_once __DIR__ . '/lib/mail-otp.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

$email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
$name = trim((string)($_POST['name'] ?? ''));
$password = (string)($_POST['password'] ?? '');

$requestedRole = strtolower(trim((string)($_POST['role'] ?? 'attendee')));
$allowedPublicRoles = ['attendee', 'organiser'];
$role = in_array($requestedRole, $allowedPublicRoles, true) ? $requestedRole : 'attendee';

if (empty($email) || $name === '' || $password === '') {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "All fields are required"]);
    exit;
}

if (strlen($password) < 6) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Password must be at least 6 characters"]);
    exit;
}

otpEnsureSchema($connect);

$email = strtolower($email);
$verified = otpBypassEnabled() ? 1 : 0;

$stmt = $connect->prepare("SELECT id, email_verified FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing) {
    if ((int) ($existing['email_verified'] ?? 0) === 1) {
        http_response_code(409);
        echo json_encode(["success" => false, "message" => "Email already registered"]);
        $connect->close();
        exit;
    }

    // Allow restarting signup for an unverified account with the same email.
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $update = $connect->prepare(
        "UPDATE users SET name = ?, password = ?, role = ?, email_verified = ? WHERE id = ?"
    );
    $userId = (int) $existing['id'];
    $update->bind_param("sssii", $name, $hashed_password, $role, $verified, $userId);
    if (!$update->execute()) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Error updating account"]);
        $update->close();
        $connect->close();
        exit;
    }
    $update->close();
} else {
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $insert = $connect->prepare(
        "INSERT INTO users (name, email, password, role, email_verified) VALUES (?, ?, ?, ?, ?)"
    );
    $insert->bind_param("ssssi", $name, $email, $hashed_password, $role, $verified);
    if (!$insert->execute()) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Error registering user"]);
        $insert->close();
        $connect->close();
        exit;
    }
    $insert->close();
}

$userStmt = $connect->prepare("SELECT id, name, email, role FROM users WHERE email = ? LIMIT 1");
$userStmt->bind_param("s", $email);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (!$user) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Error registering user"]);
    $connect->close();
    exit;
}

if (otpBypassEnabled()) {
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['role'] = (string) $user['role'];

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Account created.",
        "needs_verification" => false,
        "otp_bypassed" => true,
        "session_token" => session_id(),
        "user" => [
            "id" => (int) $user['id'],
            "name" => $user['name'],
            "email" => $user['email'],
            "role" => $user['role'],
        ],
    ]);
    $connect->close();
    exit;
}

$otp = otpCreateAndSend($connect, $email, $name, 'signup');

$response = [
    "success" => true,
    "message" => $otp['sent']
        ? "Account created. Enter the verification code we emailed to you."
        : "Account created, but the verification email could not be sent. Use the code shown below, or request a new one.",
    "needs_verification" => true,
    "email" => $email,
    "mail_sent" => (bool) $otp['sent'],
];

if (!$otp['sent']) {
    $response['dev_code'] = $otp['code'];
}

http_response_code(200);
echo json_encode($response);
$connect->close();
exit;
