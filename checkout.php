<?php
// Creates one order + its order_items rows, checks ticket availability,
// and updates quantity_sold - all inside a single database transaction.
//
// Families can book without an account (name + email). Account creation
// at checkout is optional. Logged-in users are charged to their account.
//
// NOTE: this is a DEMO checkout for a student project - it does not
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

function checkoutFindUserByEmail(mysqli $connect, string $email): ?array {
    $stmt = $connect->prepare("SELECT id, name, email, role FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $user ?: null;
}

function checkoutCreateAttendee(mysqli $connect, string $name, string $email, string $password): int {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $role = 'attendee';
    // Checkout created accounts are treated as verified for that booking email.
    if (dbHasColumn($connect, 'users', 'email_verified')) {
        $stmt = $connect->prepare(
            "INSERT INTO users (name, email, password, role, email_verified) VALUES (?, ?, ?, ?, 1)"
        );
    } else {
        $stmt = $connect->prepare(
            "INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)"
        );
    }
    $stmt->bind_param("ssss", $name, $email, $hash, $role);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new Exception("Could not create an account for this booking.");
    }
    $id = (int) $connect->insert_id;
    $stmt->close();
    return $id;
}

function checkoutLoginUser(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['role'] = (string) ($user['role'] ?? 'attendee');
}

$name = trim((string) ($input['name'] ?? ''));
$email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
$createAccount = !empty($input['create_account']);
$password = (string) ($input['password'] ?? '');

$loggedInId = currentUserId();
$orderUserId = $loggedInId;
$guestName = null;
$guestEmail = null;
$isGuest = false;
$sessionUser = null;

if ($orderUserId) {
    // Logged-in families skip account creation; name/email are only for confirmation copy.
} else {
    if ($name === '' || !$email) {
        http_response_code(400);
        echo json_encode(["success" => false, "error" => "Name and a valid email are required to complete this booking."]);
        $connect->close();
        exit;
    }

    $existing = checkoutFindUserByEmail($connect, $email);
    if ($existing) {
        http_response_code(409);
        echo json_encode([
            "success" => false,
            "error" => "An account already exists for this email. Log in to complete your booking.",
            "login_required" => true
        ]);
        $connect->close();
        exit;
    }

    if ($createAccount) {
        if (strlen($password) < 6) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "Choose a password of at least 6 characters, or leave account creation unticked to book as a guest."]);
            $connect->close();
            exit;
        }

        try {
            $newId = checkoutCreateAttendee($connect, $name, $email, $password);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["success" => false, "error" => $e->getMessage()]);
            $connect->close();
            exit;
        }

        $sessionUser = [
            "id" => $newId,
            "name" => $name,
            "email" => $email,
            "role" => "attendee"
        ];
        checkoutLoginUser($sessionUser);
        $orderUserId = $newId;
    } else {
        $isGuest = true;
        $guestName = $name;
        $guestEmail = $email;
        $hasGuestColumns = dbHasColumn($connect, 'orders', 'guest_email')
            && dbHasColumn($connect, 'orders', 'guest_name');

        if ($hasGuestColumns) {
            $orderUserId = null;
        } else {
            try {
                $orderUserId = checkoutCreateAttendee(
                    $connect,
                    $name,
                    $email,
                    bin2hex(random_bytes(16))
                );
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(["success" => false, "error" => $e->getMessage()]);
                $connect->close();
                exit;
            }
        }
    }
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

    $hasGuestColumns = dbHasColumn($connect, 'orders', 'guest_email')
        && dbHasColumn($connect, 'orders', 'guest_name');

    if ($hasGuestColumns && $orderUserId === null) {
        $orderStmt = $connect->prepare(
            "INSERT INTO orders (user_id, event_id, total_amount, status, guest_name, guest_email)
             VALUES (NULL, ?, ?, 'paid', ?, ?)"
        );
        $orderStmt->bind_param("idss", $event_id, $total, $guestName, $guestEmail);
    } elseif ($hasGuestColumns) {
        $emptyGuestName = null;
        $emptyGuestEmail = null;
        $orderStmt = $connect->prepare(
            "INSERT INTO orders (user_id, event_id, total_amount, status, guest_name, guest_email)
             VALUES (?, ?, ?, 'paid', ?, ?)"
        );
        $orderStmt->bind_param("iidss", $orderUserId, $event_id, $total, $emptyGuestName, $emptyGuestEmail);
    } else {
        if ($orderUserId === null) {
            throw new Exception("Guest checkout is not available yet. Please create an account or try again shortly.");
        }
        $orderStmt = $connect->prepare(
            "INSERT INTO orders (user_id, event_id, total_amount, status) VALUES (?, ?, ?, 'paid')"
        );
        $orderStmt->bind_param("iid", $orderUserId, $event_id, $total);
    }

    $orderStmt->execute();
    $order_id = $connect->insert_id;
    $orderStmt->close();

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

    $payload = [
        "success" => true,
        "order_id" => $order_id,
        "total_amount" => round($total, 2),
        "guest" => $isGuest
    ];
    if ($sessionUser) {
        $payload["user"] = $sessionUser;
        $payload["session_token"] = session_id();
    }

    http_response_code(201);
    echo json_encode($payload);
} catch (Exception $e) {
    $connect->rollback();
    http_response_code(400);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}

$connect->close();
exit;
