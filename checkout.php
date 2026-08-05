<?php
// This did not exist at all in the original codebase — checkout.js was
// posting to '/api/checkout', a URL that matched nothing. This is a real,
// working implementation: creates one order + its order_items rows, checks
// ticket availability, and updates quantity_sold — all inside a single
// database transaction so a failure partway through can't leave orders in
// a half-written state.
//
// NOTE: this is a DEMO checkout for a student project — it does not
// process real payment. No card details are collected or stored server
// side. Do not point real card numbers at this endpoint.

require_once __DIR__ . '/session-boot.php';
header('Content-Type: application/json; charset=utf-8');

include 'conn.php';
include 'auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "error" => "Method not allowed"]);
    exit;
}

$user_id = requireLogin($connect);

$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
    $input = $_POST;
}

$event_id = filter_var($input['event_id'] ?? '', FILTER_VALIDATE_INT);
$items = $input['items'] ?? null; // [{ ticket_type_id, quantity }, ...]

if (!$event_id || !is_array($items) || count($items) === 0) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "An event and at least one ticket item are required."]);
    exit;
}

// Confirm the event exists and is actually published (can't buy tickets to
// a draft event, even if you somehow know its id).
$eventStmt = $connect->prepare("SELECT id, status FROM events WHERE id = ? LIMIT 1");
$eventStmt->bind_param("i", $event_id);
$eventStmt->execute();
$event = $eventStmt->get_result()->fetch_assoc();
$eventStmt->close();

if (!$event || $event['status'] !== 'published') {
    http_response_code(404);
    echo json_encode(["success" => false, "error" => "Event not found or not available for booking."]);
    $connect->close();
    exit;
}

$connect->begin_transaction();

try {
    $total = 0.0;
    $validatedItems = [];

    foreach ($items as $item) {
        $ticketTypeId = filter_var($item['ticket_type_id'] ?? '', FILTER_VALIDATE_INT);
        $quantity = filter_var($item['quantity'] ?? '', FILTER_VALIDATE_INT);

        if (!$ticketTypeId || !$quantity || $quantity <= 0) {
            throw new Exception("Invalid ticket selection.");
        }

        // Lock the row for update so two simultaneous checkouts can't both
        // oversell the same remaining tickets.
        $ticketStmt = $connect->prepare(
            "SELECT id, event_id, price, quantity_available, quantity_sold
             FROM ticket_types WHERE id = ? AND event_id = ? FOR UPDATE"
        );
        $ticketStmt->bind_param("ii", $ticketTypeId, $event_id);
        $ticketStmt->execute();
        $ticket = $ticketStmt->get_result()->fetch_assoc();
        $ticketStmt->close();

        if (!$ticket) {
            throw new Exception("One of the selected ticket types no longer exists.");
        }

        $remaining = (int)$ticket['quantity_available'] - (int)$ticket['quantity_sold'];
        if ($quantity > $remaining) {
            throw new Exception("Not enough tickets remaining for one of your selections.");
        }

        $lineTotal = (float)$ticket['price'] * $quantity;
        $total += $lineTotal;

        $validatedItems[] = [
            'ticket_type_id' => $ticketTypeId,
            'quantity' => $quantity,
            'unit_price' => (float)$ticket['price']
        ];
    }

    // Create the order
    $orderStmt = $connect->prepare(
        "INSERT INTO orders (user_id, event_id, total_amount, status) VALUES (?, ?, ?, 'paid')"
    );
    $orderStmt->bind_param("iid", $user_id, $event_id, $total);
    $orderStmt->execute();
    $order_id = $connect->insert_id;
    $orderStmt->close();

    // Create order_items + increment quantity_sold for each ticket type
    $itemStmt = $connect->prepare(
        "INSERT INTO order_items (order_id, ticket_type_id, quantity, unit_price) VALUES (?, ?, ?, ?)"
    );
    $incrementStmt = $connect->prepare(
        "UPDATE ticket_types SET quantity_sold = quantity_sold + ? WHERE id = ?"
    );

    foreach ($validatedItems as $vi) {
        $itemStmt->bind_param("iiid", $order_id, $vi['ticket_type_id'], $vi['quantity'], $vi['unit_price']);
        $itemStmt->execute();

        $incrementStmt->bind_param("ii", $vi['quantity'], $vi['ticket_type_id']);
        $incrementStmt->execute();
    }
    $itemStmt->close();
    $incrementStmt->close();

    $connect->commit();

    http_response_code(201);
    echo json_encode([
        "success" => true,
        "order_id" => $order_id,
        "total_amount" => round($total, 2)
    ]);
} catch (Exception $e) {
    $connect->rollback();
    http_response_code(400);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}

$connect->close();
exit;
