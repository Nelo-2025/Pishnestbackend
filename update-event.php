<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

include 'conn.php';
include 'auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "error" => "Method not allowed"]);
    exit;
}

$organiser_id = requireOrganiser($connect);

function normalizeStartDateTime(array $input): ?string {
    $start = trim((string)($input['start_datetime'] ?? ''));
    if ($start === '') {
        $eventDate = trim((string)($input['event_date'] ?? $input['date'] ?? ''));
        $eventTime = trim((string)($input['event_time'] ?? $input['time'] ?? ''));
        if ($eventDate !== '' && $eventTime !== '') {
            $start = $eventDate . ' ' . $eventTime;
        }
    }
    if ($start === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $start)
        ?: DateTime::createFromFormat('Y-m-d H:i:s', $start)
        ?: DateTime::createFromFormat('Y-m-d H:i', $start);
    return $dt ? $dt->format('Y-m-d H:i:s') : null;
}

$input = $_POST;
if (empty($input)) {
    $rawBody = file_get_contents('php://input');
    $json = json_decode($rawBody, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
        $input = $json;
    }
}

$event_id           = filter_var($input['event_id'] ?? '', FILTER_VALIDATE_INT);
$event_name         = trim((string)($input['event_name'] ?? $input['title'] ?? ''));
$event_description  = trim((string)($input['event_description'] ?? $input['description'] ?? ''));
$event_datetime     = normalizeStartDateTime($input);
$venue              = trim((string)($input['venue'] ?? $input['location'] ?? ''));
$category_id        = filter_var($input['category_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
$custom_category_name = trim((string)($input['custom_category_name'] ?? ''));

$requestedStatus = trim((string)($input['status'] ?? ''));
$allowed_statuses = ['draft', 'published', 'cancelled'];
$status = in_array($requestedStatus, $allowed_statuses, true) ? $requestedStatus : null;

if ($event_id === false || $event_id <= 0 || $event_name === '' || $event_description === '' || $event_datetime === null) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Event name, description, and a valid date/time are required."]);
    exit;
}

$errors = [];
if ($category_id) {
    $checkCategoryStmt = $connect->prepare("SELECT id FROM categories WHERE id = ? LIMIT 1");
    $checkCategoryStmt->bind_param("i", $category_id);
    $checkCategoryStmt->execute();
    $validCategory = $checkCategoryStmt->get_result()->fetch_assoc();
    $checkCategoryStmt->close();
    if (!$validCategory) {
        $errors[] = "Selected category is invalid.";
    }
}

if (!$category_id && $custom_category_name !== '') {
    $findCategoryStmt = $connect->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(?) LIMIT 1");
    $findCategoryStmt->bind_param("s", $custom_category_name);
    $findCategoryStmt->execute();
    $existingCategory = $findCategoryStmt->get_result()->fetch_assoc();
    $findCategoryStmt->close();

    if ($existingCategory) {
        $category_id = (int) $existingCategory['id'];
    } else {
        $createCategoryStmt = $connect->prepare("INSERT INTO categories (name) VALUES (?)");
        $createCategoryStmt->bind_param("s", $custom_category_name);
        if ($createCategoryStmt->execute()) {
            $category_id = (int) $connect->insert_id;
        } else {
            $errors[] = "Could not create custom category.";
        }
        $createCategoryStmt->close();
    }
}

if (!$category_id) {
    $errors[] = "Category is required.";
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => implode(' ', $errors)]);
    $connect->close();
    exit;
}

// Ownership is checked first (separately) so we can return a clear,
// distinct error instead of a generic "not found" — this endpoint is only
// ever called by the organiser's own edit form, so a clearer message here
// doesn't leak anything sensitive to a stranger.
$checkStmt = $connect->prepare("SELECT organiser_id FROM events WHERE id = ?");
$checkStmt->bind_param("i", $event_id);
$checkStmt->execute();
$existing = $checkStmt->get_result()->fetch_assoc();
$checkStmt->close();

if (!$existing) {
    http_response_code(404);
    echo json_encode(["success" => false, "error" => "Event not found"]);
    $connect->close();
    exit;
}

if ((int)$existing['organiser_id'] !== $organiser_id && !isAdmin()) {
    http_response_code(403);
    echo json_encode(["success" => false, "error" => "You don't have permission to edit this event."]);
    $connect->close();
    exit;
}

// Build the update dynamically so status is only touched if one was sent.
$fields = "title = ?, description = ?, venue = ?, category_id = ?, start_datetime = ?";
$params = [$event_name, $event_description, $venue, $category_id, $event_datetime];
$types = "sssis";

if ($status !== null) {
    $fields .= ", status = ?";
    $params[] = $status;
    $types .= "s";
}

$params[] = $event_id;
$types .= "i";

$stmt = $connect->prepare("UPDATE events SET {$fields} WHERE id = ?");
$stmt->bind_param($types, ...$params);

if ($stmt->execute()) {
    http_response_code(200);
    echo json_encode(["success" => true, "message" => "Event updated successfully"]);
} else {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Error updating event"]);
}

$stmt->close();
$connect->close();
exit;
