<?php
// Replaces the old forgot-password.js flow, which read/wrote a
// 'nearby_users' key in localStorage — completely disconnected from the
// real MySQL `users` table, so it could never actually find a real account
// or update a real password. This version is backed by the database, via
// a password_resets table that stores a hashed 6-digit code with an
// expiry.
//
// NOTE: there's no real email server here, so the generated code is
// returned directly in the JSON response with a "dev_code" field, the same
// way the original fake version showed it on-screen. In a real deployment
// you would email this code instead of returning it to the browser.

require_once __DIR__ . '/session-boot.php';
header('Content-Type: application/json; charset=utf-8');

include 'conn.php';

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

function generateCode(): string {
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

if ($action === 'request') {
    $email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
    if (!$email) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "A valid email is required."]);
        exit;
    }

    $userStmt = $connect->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $userStmt->bind_param("s", $email);
    $userStmt->execute();
    $exists = $userStmt->get_result()->fetch_assoc();
    $userStmt->close();

    if (!$exists) {
        // Deliberately vague to avoid confirming which emails are registered.
        echo json_encode(["success" => true, "message" => "If that email is registered, a code has been generated."]);
        $connect->close();
        exit;
    }

    $code = generateCode();
    $codeHash = password_hash($code, PASSWORD_DEFAULT);
    $expiresAt = (new DateTime('+10 minutes'))->format('Y-m-d H:i:s');

    $insertStmt = $connect->prepare(
        "INSERT INTO password_resets (email, code_hash, expires_at) VALUES (?, ?, ?)"
    );
    $insertStmt->bind_param("sss", $email, $codeHash, $expiresAt);
    $insertStmt->execute();
    $insertStmt->close();

    echo json_encode([
        "success" => true,
        "message" => "Verification code generated.",
        "dev_code" => $code // remove this field once real email sending is wired up
    ]);
    $connect->close();
    exit;
}

if ($action === 'verify') {
    $email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $code = trim((string)($input['code'] ?? ''));

    if (!$email || $code === '') {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Email and code are required."]);
        exit;
    }

    $stmt = $connect->prepare(
        "SELECT id, code_hash FROM password_resets
         WHERE email = ? AND used = 0 AND expires_at > NOW()
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $reset = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$reset || !password_verify($code, $reset['code_hash'])) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "That code is invalid or has expired."]);
        $connect->close();
        exit;
    }

    // Mark verified in the session so reset() below can trust it without
    // re-sending the code (the reset step still re-checks server-side).
    $_SESSION['password_reset_verified_email'] = $email;
    $_SESSION['password_reset_id'] = (int) $reset['id'];

    echo json_encode(["success" => true, "message" => "Code verified."]);
    $connect->close();
    exit;
}

if ($action === 'reset') {
    $newPassword = (string)($input['new_password'] ?? '');
    $email = $_SESSION['password_reset_verified_email'] ?? null;
    $resetId = $_SESSION['password_reset_id'] ?? null;

    if (!$email || !$resetId) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Please verify your code again before resetting your password."]);
        exit;
    }

    if (strlen($newPassword) < 6) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Password must be at least 6 characters."]);
        exit;
    }

    $hashed = password_hash($newPassword, PASSWORD_DEFAULT);

    $connect->begin_transaction();
    try {
        $updateUser = $connect->prepare("UPDATE users SET password = ? WHERE email = ?");
        $updateUser->bind_param("ss", $hashed, $email);
        $updateUser->execute();
        $updateUser->close();

        $markUsed = $connect->prepare("UPDATE password_resets SET used = 1 WHERE id = ?");
        $markUsed->bind_param("i", $resetId);
        $markUsed->execute();
        $markUsed->close();

        $connect->commit();

        unset($_SESSION['password_reset_verified_email'], $_SESSION['password_reset_id']);

        echo json_encode(["success" => true, "message" => "Password updated. You can now log in."]);
    } catch (Exception $e) {
        $connect->rollback();
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Could not update password. Please try again."]);
    }

    $connect->close();
    exit;
}

http_response_code(400);
echo json_encode(["success" => false, "message" => "Unknown action."]);
exit;
