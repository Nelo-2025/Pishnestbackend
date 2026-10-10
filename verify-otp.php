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

$input = $_POST;
if (empty($input)) {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
        $input = $json;
    }
}

$action = trim((string)($input['action'] ?? ''));
$email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);

otpEnsureSchema($connect);

if ($action === 'resend') {
    if (!$email) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "A valid email is required."]);
        $connect->close();
        exit;
    }

    $email = strtolower($email);
    $stmt = $connect->prepare("SELECT id, name, email_verified FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        // Vague on purpose.
        echo json_encode(["success" => true, "message" => "If that email needs verification, a new code has been sent."]);
        $connect->close();
        exit;
    }

    if ((int) ($user['email_verified'] ?? 0) === 1) {
        echo json_encode(["success" => true, "message" => "This email is already verified. You can log in."]);
        $connect->close();
        exit;
    }

    $otp = otpCreateAndSend($connect, $email, (string) ($user['name'] ?? ''), 'signup');
    $response = [
        "success" => true,
        "message" => $otp['sent']
            ? "A new verification code has been sent."
            : "Could not send email. Use the code shown below, or try again shortly.",
        "mail_sent" => (bool) $otp['sent'],
    ];
    if (!$otp['sent']) {
        $response['dev_code'] = $otp['code'];
    }
    echo json_encode($response);
    $connect->close();
    exit;
}

if ($action === 'verify') {
    $code = trim((string)($input['code'] ?? ''));
    if (!$email || $code === '') {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Email and verification code are required."]);
        $connect->close();
        exit;
    }

    $email = strtolower($email);
    if (!otpVerifyCode($connect, $email, $code, 'signup')) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "That code is invalid or has expired."]);
        $connect->close();
        exit;
    }

    $stmt = $connect->prepare("SELECT id, name, email, role, email_verified FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Account not found. Please sign up again."]);
        $connect->close();
        exit;
    }

    otpMarkUserVerified($connect, $email);

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['role'] = (string) $user['role'];

    echo json_encode([
        "success" => true,
        "message" => "Email verified. You are now signed in.",
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

http_response_code(400);
echo json_encode(["success" => false, "message" => "Unknown action."]);
$connect->close();
exit;
