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

// For a single event, use event.php?id=123 instead.

$search = trim((string)($_GET['q'] ?? ''));
$categoryId = filter_input(INPUT_GET, 'category', FILTER_VALIDATE_INT);
$eventIdRaw = $_GET['id'] ?? $_GET['event_id'] ?? $_GET['event-id'] ?? null;
$eventId = filter_var($eventIdRaw, FILTER_VALIDATE_INT);

// Organisers/admins additionally see their OWN events regardless of status
// (draft/cancelled included). Everyone else only sees published events.
$canSeeOwnEvents = isLoggedIn() && canManageEvents();
$currentUserId = $canSeeOwnEvents ? currentUserId() : 0;

// NOTE: organiser_name and organiser_id are included below specifically so
// the dashboard can filter "my events" correctly - the previous version of
// this file omitted them, which meant that filter always silently matched
// zero events even for organisers who *had* created events.
$sql = "SELECT e.id, e.title, e.venue, e.start_datetime, e.status,
           e.organiser_id,
           e.organiser_id AS organizer_id,
               c.id AS category_id, c.name AS category_name,
           u.name AS organiser_name,
           u.name AS organizer_name,
               MIN(t.price) AS min_price
        FROM events e
        LEFT JOIN categories c ON c.id = e.category_id
        LEFT JOIN users u ON u.id = e.organiser_id
        LEFT JOIN ticket_types t ON t.event_id = e.id
        WHERE (e.status = 'published'";

$params = [];
$types = '';

if ($canSeeOwnEvents) {
    $sql .= " OR e.organiser_id = ?";
    $params[] = $currentUserId;
    $types .= 'i';
}
$sql .= ")";

if ($search !== '') {
    $sql .= " AND (e.title LIKE ? OR e.venue LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}

if ($categoryId) {
    $sql .= " AND e.category_id = ?";
    $params[] = $categoryId;
    $types .= 'i';
}

if ($eventId) {
    $sql .= " AND e.id = ?";
    $params[] = $eventId;
    $types .= 'i';
}

$sql .= " GROUP BY e.id ORDER BY e.start_datetime ASC";

$stmt = $connect->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($events as &$eventRow) {
    // Legacy field aliases used by older frontend pages.
    $eventRow['event_name'] = $eventRow['title'];
    $eventRow['event_date'] = substr((string) $eventRow['start_datetime'], 0, 10);
    $eventRow['event_time'] = substr((string) $eventRow['start_datetime'], 11, 8);
}
unset($eventRow);

echo json_encode(["success" => true, "events" => $events, "data" => $events]);

$connect->close();
exit;
