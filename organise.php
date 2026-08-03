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

// ---------- Read input (form POST or JSON body) ----------
$input = $_POST;
if (empty($input)) {
    $rawBody = file_get_contents('php://input');
    $json = json_decode($rawBody, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
        $input = $json;
    }
}

$event_name        = trim((string)($input['event_name'] ?? $input['title'] ?? ''));
$event_description = trim((string)($input['event_description'] ?? $input['description'] ?? ''));
$start_datetime    = normalizeStartDateTime($input);
$venue             = trim((string)($input['venue'] ?? $input['location'] ?? ''));
$category_id       = filter_var($input['category_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
$custom_category_name = trim((string)($input['custom_category_name'] ?? ''));
$saveForLater      = !empty($input['save_to_return']) || !empty($input['save-to-return']);

// Status: honour an explicit status if one was sent, otherwise derive it
// from the "save to return later" checkbox. Never trust an arbitrary
// client-supplied status string — restrict to the real enum values.
$requestedStatus = trim((string)($input['status'] ?? ''));
$allowed_statuses = ['draft', 'published', 'cancelled'];
$status = in_array($requestedStatus, $allowed_statuses, true)
    ? $requestedStatus
    : ($saveForLater ? 'draft' : 'published');

// ---------- Validation ----------
$errors = [];
if ($event_name === '') {
    $errors[] = "Event name is required.";
}
if ($event_description === '') {
    $errors[] = "Event description is required.";
}
if (!$start_datetime) {
    $errors[] = "Start date/time is missing or not in a valid format.";
}

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

// ---------- Insert event ----------
// events.id is AUTO_INCREMENT in the cleaned schema — no manual id
// generation needed (the old MAX(id)+1 approach had a race condition and
// the table didn't even have a primary key before).
$stmt = $connect->prepare(
    "INSERT INTO events (organiser_id, category_id, title, description, venue, start_datetime, status)
     VALUES (?, ?, ?, ?, ?, ?, ?)"
);
$stmt->bind_param(
    "iisssss",
    $organiser_id, $category_id, $event_name, $event_description, $venue, $start_datetime, $status
);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Error creating event.", "details" => $stmt->error]);
    $stmt->close();
    $connect->close();
    exit;
}

$event_id = $connect->insert_id;
$stmt->close();

$eventUrl = 'event.php?id=' . urlencode((string)$event_id);

// ---------- Optional: ticket types sent alongside the event ----------
// Expects input['ticket_types'] as a JSON string like:
//   [{"name":"General Admission","price":0,"quantity_available":100}, ...]
// This is optional — an event can be created with no ticket types yet and
// they can be added later via a dedicated ticket-types endpoint.
$ticketTypesRaw = $input['ticket_types'] ?? null;
if ($ticketTypesRaw) {
    $ticketTypes = is_string($ticketTypesRaw) ? json_decode($ticketTypesRaw, true) : $ticketTypesRaw;

    if (is_array($ticketTypes)) {
        $ticketStmt = $connect->prepare(
            "INSERT INTO ticket_types (event_id, name, price, quantity_available) VALUES (?, ?, ?, ?)"
        );
        foreach ($ticketTypes as $t) {
            $tName = trim((string)($t['name'] ?? ''));
            $tPrice = (float)($t['price'] ?? 0);
            $tQty = (int)($t['quantity_available'] ?? 0);
            if ($tName === '') {
                continue;
            }
            $ticketStmt->bind_param("isdi", $event_id, $tName, $tPrice, $tQty);
            $ticketStmt->execute();
        }
        $ticketStmt->close();
    }
}

// For normal browser form submissions, redirect straight to the new
// event page. Keep JSON output for API/AJAX callers.
$wantsJson = false;
if (isset($_SERVER['HTTP_ACCEPT']) && strpos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
    $wantsJson = true;
}
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    $wantsJson = true;
}
if (isset($_SERVER['CONTENT_TYPE']) && strpos(strtolower((string)$_SERVER['CONTENT_TYPE']), 'application/json') !== false) {
    $wantsJson = true;
}
// Browser form POST navigations should redirect, while fetch/XHR/API calls
// should consistently receive JSON.
$isNavigationRequest = isset($_SERVER['HTTP_SEC_FETCH_MODE'])
    && strtolower((string)$_SERVER['HTTP_SEC_FETCH_MODE']) === 'navigate';
if (!$isNavigationRequest) {
    $wantsJson = true;
}
if (!empty($input['force_json'])) {
    $wantsJson = true;
}

if (!$wantsJson) {
    header('Content-Type: text/html; charset=utf-8');
    http_response_code(303);
    header('Location: ' . $eventUrl);
    $connect->close();
    exit;
}

http_response_code(201);
echo json_encode([
    "success"      => true,
    "event_id"     => $event_id,
    "status"       => $status,
    "redirect_url" => $eventUrl,
    "event_url"    => $eventUrl
]);

$connect->close();
exit;
