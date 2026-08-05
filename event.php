<?php
require_once __DIR__ . '/session-boot.php';
header('Content-Type: application/json; charset=utf-8');

include 'conn.php';
include 'auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["success" => false, "error" => "Method not allowed"]);
    exit;
}

$idRaw = $_GET['id'] ?? $_GET['event_id'] ?? $_GET['event-id'] ?? null;
$id = filter_var($idRaw, FILTER_VALIDATE_INT);

if (!$id) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "A valid event id is required"]);
    exit;
}

$stmt = $connect->prepare(
    "SELECT e.id, e.organiser_id, e.title, e.description, e.venue,
            e.start_datetime, e.end_datetime, e.status,
            c.id AS category_id, c.name AS category_name,
            u.name AS organiser_name
     FROM events e
     LEFT JOIN categories c ON c.id = e.category_id
     LEFT JOIN users u ON u.id = e.organiser_id
     WHERE e.id = ?
     LIMIT 1"
);
$stmt->bind_param("i", $id);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$event) {
    http_response_code(404);
    echo json_encode(["success" => false, "error" => "Event not found"]);
    $connect->close();
    exit;
}

// Draft/cancelled events are only visible to their owner.
// Published events are visible to everyone.
$isOwner = isLoggedIn() && currentUserId() === (int) $event['organiser_id'];

if ($event['status'] !== 'published' && !$isOwner) {
    // Behave as "not found" rather than confirming a draft exists at this id
    http_response_code(404);
    echo json_encode(["success" => false, "error" => "Event not found"]);
    $connect->close();
    exit;
}

$ticketStmt = $connect->prepare(
    "SELECT id, name, price, quantity_available, quantity_sold
     FROM ticket_types WHERE event_id = ? ORDER BY price ASC"
);
$ticketStmt->bind_param("i", $id);
$ticketStmt->execute();
$event['ticket_types'] = $ticketStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$ticketStmt->close();

$event['event_name'] = $event['title'];
$event['event_description'] = $event['description'];
$event['event_date'] = substr((string) $event['start_datetime'], 0, 10);
$event['event_time'] = substr((string) $event['start_datetime'], 11, 8);
$event['organizer_id'] = $event['organiser_id'];
$event['organizer_name'] = $event['organiser_name'];

$event['is_owner_preview'] = $isOwner && $event['status'] !== 'published';
$event['is_owner'] = $isOwner;

echo json_encode(["success" => true, "event" => $event]);

$connect->close();
exit;
