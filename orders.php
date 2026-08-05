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

$user_id = requireLogin($connect);

function listMyOrders(mysqli $connect, int $user_id): void {
    $sql = "SELECT
                o.id,
                o.event_id,
                o.total_amount,
                o.status,
                o.created_at,
                e.title AS event_title,
                e.venue,
                e.start_datetime,
                COALESCE(SUM(oi.quantity), 0) AS tickets_count,
                GROUP_CONCAT(CONCAT(tt.name, ' x', oi.quantity) ORDER BY tt.price ASC SEPARATOR ', ') AS ticket_summary
            FROM orders o
            INNER JOIN events e ON e.id = o.event_id
            LEFT JOIN order_items oi ON oi.order_id = o.id
            LEFT JOIN ticket_types tt ON tt.id = oi.ticket_type_id
            WHERE o.user_id = ?
            GROUP BY o.id
            ORDER BY o.created_at DESC";

    $stmt = $connect->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($orders as &$order) {
        $order['can_cancel'] = in_array(strtolower((string)$order['status']), ['pending', 'paid'], true);
        $order['total_amount'] = (float)$order['total_amount'];
        $order['tickets_count'] = (int)$order['tickets_count'];
    }
    unset($order);

    echo json_encode(["success" => true, "orders" => $orders]);
}

function cancelMyOrder(mysqli $connect, int $user_id, int $order_id): void {
    $connect->begin_transaction();

    try {
        $orderStmt = $connect->prepare("SELECT id, status FROM orders WHERE id = ? AND user_id = ? FOR UPDATE");
        $orderStmt->bind_param("ii", $order_id, $user_id);
        $orderStmt->execute();
        $order = $orderStmt->get_result()->fetch_assoc();
        $orderStmt->close();

        if (!$order) {
            throw new Exception("Order not found.");
        }

        $status = strtolower((string)$order['status']);
        if ($status === 'cancelled') {
            $connect->commit();
            echo json_encode(["success" => true, "message" => "Order is already cancelled."]);
            return;
        }

        if (!in_array($status, ['pending', 'paid'], true)) {
            throw new Exception("This order cannot be cancelled.");
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

        $cancelStmt = $connect->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ? AND user_id = ?");
        $cancelStmt->bind_param("ii", $order_id, $user_id);
        $cancelStmt->execute();
        $cancelStmt->close();

        $connect->commit();
        echo json_encode(["success" => true, "message" => "Booking cancelled successfully."]);
    } catch (Exception $e) {
        $connect->rollback();
        http_response_code(400);
        echo json_encode(["success" => false, "error" => $e->getMessage()]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    listMyOrders($connect, $user_id);
    $connect->close();
    exit;
}

$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
    $input = $_POST;
}

$action = strtolower(trim((string)($input['action'] ?? '')));
if ($action === 'cancel') {
    $order_id = filter_var($input['order_id'] ?? '', FILTER_VALIDATE_INT);
    if (!$order_id) {
        http_response_code(400);
        echo json_encode(["success" => false, "error" => "A valid order id is required."]);
        $connect->close();
        exit;
    }

    cancelMyOrder($connect, $user_id, (int)$order_id);
    $connect->close();
    exit;
}

http_response_code(400);
echo json_encode(["success" => false, "error" => "Invalid action."]);
$connect->close();
exit;
