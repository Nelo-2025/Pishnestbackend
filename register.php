<?php
header('Content-Type: application/json; charset=utf-8');
include 'conn.php';
// Check if the request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}
// Validate and sanitize input
$email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
$name = trim((string)($_POST['name'] ?? ''));
$password = (string)($_POST['password'] ?? '');

// SECURITY: role is intentionally NOT read from $_POST here.
// The old version trusted a client-supplied "role" field, which meant
// anyone could POST role=admin and self-register as an administrator
// (this is visible in the original seed data — an account became 'admin'
// this way). Public self-registration can only ever create 'attendee' or
// 'organiser' accounts, and even then we whitelist it below rather than
// trusting the raw value.
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
// Check if the email is already registered
$stmt = $connect->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    http_response_code(409);
    echo json_encode(["success" => false, "message" => "Email already registered"]);
    $stmt->close();
    $connect->close();
    exit;
}
$stmt->close();
// Hash the password before storing it in the database
$hashed_password = password_hash($password, PASSWORD_DEFAULT);
// Insert the new user into the database
$stmt = $connect->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
$stmt->bind_param("ssss", $name, $email, $hashed_password, $role);

if ($stmt->execute()) {
    http_response_code(201);
    echo json_encode(["success" => true, "message" => "Account created successfully"]);
} else {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Error registering user"]);
}

$stmt->close();
$connect->close();
exit;
