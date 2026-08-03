<?php
// Was completely missing before — dashboard.js was faking a success message
// even when this request failed, which would have misled real users into
// thinking they'd subscribed when nothing was saved anywhere.

header('Content-Type: application/json; charset=utf-8');

include 'conn.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

$email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);

if (!$email) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "A valid email address is required."]);
    exit;
}

// INSERT IGNORE so subscribing twice with the same email doesn't error —
// it just silently stays subscribed once.
$stmt = $connect->prepare("INSERT IGNORE INTO newsletter_subscribers (email) VALUES (?)");
$stmt->bind_param("s", $email);

if ($stmt->execute()) {
    http_response_code(201);
    echo json_encode(["success" => true, "message" => "Thanks for subscribing!"]);
} else {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Could not subscribe right now. Please try again."]);
}

$stmt->close();
$connect->close();
exit;
