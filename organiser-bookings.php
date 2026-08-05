<?php
require_once __DIR__ . '/session-boot.php';
header('Content-Type: application/json; charset=utf-8');

include 'conn.php';
include 'auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "error" => "Method not allowed"]);
    exit;
}

$organiser_id = requireOrganiser($connect);
$is_admin = isAdmin();

function listOrganiserBookings(mysqli $connect, int $organiser_id, bool $is_admin): void {
$event_id = filter_var($_GET['event_id'] ?? '', FILTER_VALIDATE_INT);

$eventParams = [];
$eventTypes = '';
$eventsWhere = '1=1';
if (!$is_admin) {
    $eventsWhere = 'e.organiser_id = ?';
    $eventParams[] = $organiser_id;
    $eventTypes .= 'i';
}

$eventsSql = "SELECT
                e.id,
                e.title,
                e.venue,
                e.start_datetime,
                e.status,
                COALESCE(COUNT(DISTINCT o.id), 0) AS bookings_count,
                COALESCE(SUM(CASE WHEN o.status = 'paid' THEN o.total_amount ELSE 0 END), 0) AS revenue,
                COALESCE(SUM(CASE WHEN o.status <> 'cancelled' THEN oi.quantity ELSE 0 END), 0) AS tickets_sold
              FROM events e
              LEFT JOIN orders o ON o.event_id = e.id
              LEFT JOIN order_items oi ON oi.order_id = o.id
              WHERE {$eventsWhere}
              GROUP BY e.id
              ORDER BY e.start_datetime DESC";

    $eventsStmt = $connect->prepare($eventsSql);
    if ($eventTypes !== '') {
        $eventsStmt->bind_param($eventTypes, ...$eventParams);
    }
    $eventsStmt->execute();
    $events = $eventsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $eventsStmt->close();

$bookingsWhere = [];
$bookingParams = [];
$bookingTypes = '';

if ($is_admin) {
    $bookingsWhere[] = '1=1';
} else {
    $bookingsWhere[] = 'e.organiser_id = ?';
    $bookingParams[] = $organiser_id;
    $bookingTypes .= 'i';
}

if ($event_id) {
    $bookingsWhere[] = 'e.id = ?';
    $bookingParams[] = $event_id;
    $bookingTypes .= 'i';
}

$bookingsSql = "SELECT
                  o.id AS order_id,
                  o.event_id,
                  e.title AS event_title,
                  e.start_datetime,
                  e.venue,
                  o.status,
                  o.total_amount,
                  o.created_at,
                  u.id AS attendee_id,
                  u.name AS attendee_name,
                  u.email AS attendee_email,
                  COALESCE(SUM(oi.quantity), 0) AS tickets_count,
                  GROUP_CONCAT(CONCAT(tt.name, ' x', oi.quantity) ORDER BY tt.price ASC SEPARATOR ', ') AS ticket_summary
                FROM orders o
                INNER JOIN events e ON e.id = o.event_id
                INNER JOIN users u ON u.id = o.user_id
                LEFT JOIN order_items oi ON oi.order_id = o.id
                LEFT JOIN ticket_types tt ON tt.id = oi.ticket_type_id
                WHERE " . implode(' AND ', $bookingsWhere) . "
                GROUP BY o.id
                ORDER BY o.created_at DESC";

    $bookingsStmt = $connect->prepare($bookingsSql);
    if ($bookingTypes !== '') {
        $bookingsStmt->bind_param($bookingTypes, ...$bookingParams);
    }
    $bookingsStmt->execute();
    $bookings = $bookingsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $bookingsStmt->close();

    $totalRevenue = 0.0;
    $totalBookings = 0;
    $totalTickets = 0;

    foreach ($bookings as &$booking) {
        $status = strtolower((string)$booking['status']);
        $booking['total_amount'] = (float)$booking['total_amount'];
        $booking['tickets_count'] = (int)$booking['tickets_count'];
        $booking['can_cancel'] = in_array($status, ['pending', 'paid'], true);
        $booking['can_refund'] = $status === 'paid';

        if ($status === 'paid') {
            $totalRevenue += (float)$booking['total_amount'];
        }
        if ($status !== 'cancelled') {
            $totalTickets += (int)$booking['tickets_count'];
        }
        $totalBookings++;
    }
    unset($booking);

    foreach ($events as &$eventRow) {
        $eventRow['bookings_count'] = (int)$eventRow['bookings_count'];
        $eventRow['revenue'] = (float)$eventRow['revenue'];
        $eventRow['tickets_sold'] = (int)$eventRow['tickets_sold'];
    }
    unset($eventRow);

    echo json_encode([
        "success" => true,
        "events" => $events,
        "bookings" => $bookings,
        "summary" => [
            "bookings_count" => $totalBookings,
            "tickets_sold" => $totalTickets,
            "revenue" => round($totalRevenue, 2)
        ]
    ]);
}

function manageOrganiserOrder(mysqli $connect, int $organiser_id, bool $is_admin, int $order_id, string $action): void {
    if (!in_array($action, ['cancel', 'refund'], true)) {
        http_response_code(400);
        echo json_encode(["success" => false, "error" => "Invalid action."]);
        return;
    }

    $connect->begin_transaction();
    try {
        if ($is_admin) {
            $orderSql = "SELECT o.id, o.status, e.organiser_id
                         FROM orders o
                         INNER JOIN events e ON e.id = o.event_id
                         WHERE o.id = ?
                         FOR UPDATE";
            $orderStmt = $connect->prepare($orderSql);
            $orderStmt->bind_param("i", $order_id);
        } else {
            $orderSql = "SELECT o.id, o.status, e.organiser_id
                         FROM orders o
                         INNER JOIN events e ON e.id = o.event_id
                         WHERE o.id = ? AND e.organiser_id = ?
                         FOR UPDATE";
            $orderStmt = $connect->prepare($orderSql);
            $orderStmt->bind_param("ii", $order_id, $organiser_id);
        }

        $orderStmt->execute();
        $order = $orderStmt->get_result()->fetch_assoc();
        $orderStmt->close();

        if (!$order) {
            throw new Exception("Booking not found or access denied.");
        }

        $status = strtolower((string)$order['status']);
        if ($status === 'cancelled') {
            $connect->commit();
            $message = ($action === 'refund')
                ? 'Booking is already cancelled/refunded.'
                : 'Booking is already cancelled.';
            echo json_encode(["success" => true, "message" => $message]);
            return;
        }

        if ($action === 'refund' && $status !== 'paid') {
            throw new Exception("Only paid bookings can be refunded.");
        }

        if (!in_array($status, ['pending', 'paid'], true)) {
            throw new Exception("This booking cannot be updated.");
        }

        $itemsStmt = $connect->prepare(
            "SELECT oi.ticket_type_id, oi.quantity
             FROM order_items oi
             INNER JOIN ticket_types tt ON tt.id = oi.ticket_type_id
             WHERE oi.order_id = ?
             FOR UPDATE"
        );
        $itemsStmt->bind_param("i", $order_id);
        $itemsStmt->execute();
        $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $itemsStmt->close();

        if (!empty($items)) {
            $restoreStmt = $connect->prepare(
                "UPDATE ticket_types
                 SET quantity_sold = GREATEST(quantity_sold - ?, 0)
                 WHERE id = ?"
            );
            foreach ($items as $item) {
                $qty = (int)$item['quantity'];
                $ticketTypeId = (int)$item['ticket_type_id'];
                $restoreStmt->bind_param("ii", $qty, $ticketTypeId);
                $restoreStmt->execute();
            }
            $restoreStmt->close();
        }

        $updateStmt = $connect->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?");
        $updateStmt->bind_param("i", $order_id);
        $updateStmt->execute();
        $updateStmt->close();

        $connect->commit();

        $message = ($action === 'refund')
            ? 'Booking refunded and cancelled successfully.'
            : 'Booking cancelled successfully.';
        echo json_encode(["success" => true, "message" => $message]);
    } catch (Exception $e) {
        $connect->rollback();
        http_response_code(400);
        echo json_encode(["success" => false, "error" => $e->getMessage()]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    listOrganiserBookings($connect, $organiser_id, $is_admin);
    $connect->close();
    exit;
}

$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
    $input = $_POST;
}

$action = strtolower(trim((string)($input['action'] ?? '')));
$order_id = filter_var($input['order_id'] ?? '', FILTER_VALIDATE_INT);

if (!$order_id) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "A valid booking id is required."]);
    $connect->close();
    exit;
}

manageOrganiserOrder($connect, $organiser_id, $is_admin, (int)$order_id, $action);
$connect->close();
exit;
