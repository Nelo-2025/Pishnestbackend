<?php
// Replaces the old get-user.php, which let anyone look up ANY user's name
// and role just by knowing their email address (no auth check at all).
// This endpoint only ever returns the currently logged-in user's own
// record, based on their session - never someone else's.

require_once __DIR__ . '/session-boot.php';
header('Content-Type: application/json; charset=utf-8');

include 'conn.php';
include 'auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

if (!isLoggedIn()) {
    // Not an error - this is the normal "am I logged in?" check the
    // frontend can call on page load. Just say no.
    echo json_encode(["success" => false, "message" => "Not logged in"]);
    exit;
}

$stmt = $connect->prepare("SELECT id, name, email, role FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
$connect->close();

if (!$user) {
    echo json_encode(["success" => false, "message" => "User not found"]);
    exit;
}

echo json_encode([
    "success" => true,
    "user" => [
        "id" => (int) $user['id'],
        "name" => $user['name'],
        "email" => $user['email'],
        "role" => $user['role']
    ]
]);
exit;
