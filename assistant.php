<?php
// Organiser Assistant endpoint. Conversations are stored in the database per
// user, so they follow the organiser across devices.
//
//   GET  ?action=list                       -> { conversations: [{id,title,updated_at}] }
//   GET  ?action=get&id=123                 -> { conversation: {...}, messages: [{role,content,created_at}] }
//   POST {action:"send", message, conversation_id?, page?}
//                                           -> { conversation_id, title, reply }
//   POST {action:"delete", conversation_id} -> { success }
//
// Currently open to every organiser/admin. When plans are enforced, add the
// Pro Plus check in assistantIsAllowed() below.

require_once __DIR__ . '/session-boot.php';
header('Content-Type: application/json; charset=utf-8');

include 'conn.php';
include 'auth.php';
require_once __DIR__ . '/lib/assistant-llm.php';

const ASSISTANT_RATE_LIMIT = 30;     // user messages
const ASSISTANT_RATE_WINDOW = 3600;  // per hour, per user (across all devices)
const ASSISTANT_LIST_LIMIT = 50;
const ASSISTANT_TITLE_CHARS = 60;

function assistantJson(mysqli $connect, array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    @$connect->close();
    exit;
}

function assistantEnsureTables(mysqli $connect): void {
    $statements = [
        "CREATE TABLE IF NOT EXISTS assistant_conversations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(120) NOT NULL DEFAULT 'New chat',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_assistant_conv_user (user_id, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS assistant_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            conversation_id INT NOT NULL,
            role ENUM('user', 'assistant') NOT NULL,
            content MEDIUMTEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_assistant_msg_conv (conversation_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($statements as $statement) {
        if (!$connect->query($statement)) {
            throw new RuntimeException('Database setup failed: ' . $connect->error);
        }
    }
}

// Placeholder for plan gating (Pro Plus). Everyone who is an organiser for now.
function assistantIsAllowed(int $organiserId): bool {
    return true;
}

function assistantTitleFrom(string $message): string {
    $text = trim(preg_replace('/\s+/', ' ', $message));
    if (function_exists('mb_strlen') && mb_strlen($text) > ASSISTANT_TITLE_CHARS) {
        $text = rtrim(mb_substr($text, 0, ASSISTANT_TITLE_CHARS - 1)) . '…';
    } elseif (!function_exists('mb_strlen') && strlen($text) > ASSISTANT_TITLE_CHARS) {
        $text = rtrim(substr($text, 0, ASSISTANT_TITLE_CHARS - 1)) . '…';
    }
    return $text !== '' ? $text : 'New chat';
}

/** Returns the conversation row only if it belongs to this user. */
function assistantFindConversation(mysqli $connect, int $conversationId, int $userId): ?array {
    $stmt = $connect->prepare(
        'SELECT id, title, created_at, updated_at FROM assistant_conversations WHERE id = ? AND user_id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $conversationId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function assistantOrganiserContext(mysqli $connect, int $organiserId): array {
    $organiserName = '';
    $stmt = $connect->prepare('SELECT name FROM users WHERE id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $organiserId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $organiserName = trim((string)($row['name'] ?? ''));
        $stmt->close();
    }

    $eventsContext = 'No events yet.';
    $stmt = $connect->prepare(
        "SELECT e.title, e.venue, e.start_datetime, e.status,
                COALESCE(COUNT(DISTINCT o.id), 0) AS bookings_count,
                COALESCE(SUM(CASE WHEN o.status = 'paid' THEN o.total_amount ELSE 0 END), 0) AS revenue,
                COALESCE(SUM(CASE WHEN o.status <> 'cancelled' THEN oi.quantity ELSE 0 END), 0) AS tickets_sold
         FROM events e
         LEFT JOIN orders o ON o.event_id = e.id
         LEFT JOIN order_items oi ON oi.order_id = o.id
         WHERE e.organiser_id = ?
         GROUP BY e.id
         ORDER BY e.start_datetime DESC
         LIMIT 15"
    );
    if ($stmt) {
        $stmt->bind_param('i', $organiserId);
        $stmt->execute();
        $events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if ($events) {
            $lines = [];
            foreach ($events as $ev) {
                $lines[] = sprintf(
                    '- "%s" | %s | %s | status: %s | bookings: %d | tickets sold: %d | revenue: £%.2f',
                    (string)$ev['title'],
                    (string)($ev['venue'] ?: 'venue TBC'),
                    (string)$ev['start_datetime'],
                    (string)$ev['status'],
                    (int)$ev['bookings_count'],
                    (int)$ev['tickets_sold'],
                    (float)$ev['revenue']
                );
            }
            $eventsContext = implode("\n", $lines);
        }
    }

    return [$organiserName, $eventsContext];
}

// ---------------------------------------------------------------------------

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET' && $method !== 'POST') {
    assistantJson($connect, ['success' => false, 'error' => 'Method not allowed'], 405);
}

$organiser_id = requireOrganiser($connect);

if (!assistantIsAllowed($organiser_id)) {
    assistantJson($connect, ['success' => false, 'error' => 'The Organiser Assistant is part of the Pro Plus plan.'], 403);
}

try {
    assistantEnsureTables($connect);
} catch (Throwable $e) {
    error_log('Assistant setup failed: ' . $e->getMessage());
    assistantJson($connect, ['success' => false, 'error' => 'The assistant is not available right now.'], 500);
}

// ---- GET: list / get ------------------------------------------------------

if ($method === 'GET') {
    $action = (string)($_GET['action'] ?? 'list');

    if ($action === 'list') {
        $limit = ASSISTANT_LIST_LIMIT;
        $stmt = $connect->prepare(
            'SELECT id, title, updated_at FROM assistant_conversations
             WHERE user_id = ? ORDER BY updated_at DESC, id DESC LIMIT ?'
        );
        $stmt->bind_param('ii', $organiser_id, $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $conversations = array_map(static fn($r) => [
            'id' => (int)$r['id'],
            'title' => (string)$r['title'],
            'updated_at' => (string)$r['updated_at'],
        ], $rows);

        assistantJson($connect, ['success' => true, 'conversations' => $conversations]);
    }

    if ($action === 'get') {
        $id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
        $conversation = $id ? assistantFindConversation($connect, (int)$id, $organiser_id) : null;
        if (!$conversation) {
            assistantJson($connect, ['success' => false, 'error' => 'Conversation not found.'], 404);
        }

        $stmt = $connect->prepare(
            'SELECT role, content, created_at FROM assistant_messages WHERE conversation_id = ? ORDER BY id ASC'
        );
        $cid = (int)$conversation['id'];
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        assistantJson($connect, [
            'success' => true,
            'conversation' => [
                'id' => $cid,
                'title' => (string)$conversation['title'],
                'updated_at' => (string)$conversation['updated_at'],
            ],
            'messages' => $messages,
        ]);
    }

    assistantJson($connect, ['success' => false, 'error' => 'Unknown action.'], 400);
}

// ---- POST: send / delete --------------------------------------------------

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    assistantJson($connect, ['success' => false, 'error' => 'Invalid request.'], 400);
}

$action = (string)($input['action'] ?? 'send');

if ($action === 'delete') {
    $id = filter_var($input['conversation_id'] ?? '', FILTER_VALIDATE_INT);
    $conversation = $id ? assistantFindConversation($connect, (int)$id, $organiser_id) : null;
    if (!$conversation) {
        assistantJson($connect, ['success' => false, 'error' => 'Conversation not found.'], 404);
    }

    $cid = (int)$conversation['id'];
    $stmt = $connect->prepare('DELETE FROM assistant_messages WHERE conversation_id = ?');
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $stmt->close();

    $stmt = $connect->prepare('DELETE FROM assistant_conversations WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $cid, $organiser_id);
    $stmt->execute();
    $stmt->close();

    assistantJson($connect, ['success' => true]);
}

if ($action !== 'send') {
    assistantJson($connect, ['success' => false, 'error' => 'Unknown action.'], 400);
}

if (!impactHasClaudeKey()) {
    assistantJson($connect, ['success' => false, 'error' => 'The assistant is not available right now.'], 503);
}

$userMessage = trim((string)($input['message'] ?? ''));
if ($userMessage === '') {
    assistantJson($connect, ['success' => false, 'error' => 'Please type a message first.'], 400);
}
$userMessage = function_exists('mb_substr')
    ? mb_substr($userMessage, 0, ASSISTANT_MAX_MESSAGE_CHARS)
    : substr($userMessage, 0, ASSISTANT_MAX_MESSAGE_CHARS);

$allowedPages = ['overview', 'bookings', 'activities', 'sales', 'impact', 'assistant', 'discover', 'settings'];
$page = strtolower(trim((string)($input['page'] ?? '')));
if (!in_array($page, $allowedPages, true)) {
    $page = '';
}

// Existing conversation (must belong to this user) or a new one.
$conversationId = 0;
$conversationTitle = '';
$history = [];
$requestedId = filter_var($input['conversation_id'] ?? '', FILTER_VALIDATE_INT);
if ($requestedId) {
    $conversation = assistantFindConversation($connect, (int)$requestedId, $organiser_id);
    if (!$conversation) {
        assistantJson($connect, ['success' => false, 'error' => 'Conversation not found.'], 404);
    }
    $conversationId = (int)$conversation['id'];
    $conversationTitle = (string)$conversation['title'];

    $limit = ASSISTANT_MAX_HISTORY;
    $stmt = $connect->prepare(
        'SELECT role, content FROM (
             SELECT id, role, content FROM assistant_messages WHERE conversation_id = ? ORDER BY id DESC LIMIT ?
         ) recent ORDER BY id ASC'
    );
    $stmt->bind_param('ii', $conversationId, $limit);
    $stmt->execute();
    $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Rate limit per user across all devices, based on stored messages.
$windowSeconds = ASSISTANT_RATE_WINDOW;
$stmt = $connect->prepare(
    "SELECT COUNT(*) AS c FROM assistant_messages m
     INNER JOIN assistant_conversations c ON c.id = m.conversation_id
     WHERE c.user_id = ? AND m.role = 'user' AND m.created_at >= (NOW() - INTERVAL ? SECOND)"
);
$stmt->bind_param('ii', $organiser_id, $windowSeconds);
$stmt->execute();
$recentCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();
if ($recentCount >= ASSISTANT_RATE_LIMIT) {
    assistantJson($connect, [
        'success' => false,
        'error' => 'You have reached the hourly message limit. Please try again a little later.',
    ], 429);
}

[$organiserName, $eventsContext] = assistantOrganiserContext($connect, $organiser_id);

$history[] = ['role' => 'user', 'content' => $userMessage];
$messages = assistantSanitiseMessages($history);
if (empty($messages)) {
    assistantJson($connect, ['success' => false, 'error' => 'Could not read your message.'], 400);
}

// Release the session lock before the slow Claude call (DB stays open).
session_write_close();

$system = assistantSystemPrompt($organiserName, $eventsContext, $page);
$result = assistantCallClaude($system, $messages);

if (!$result['ok']) {
    assistantJson($connect, ['success' => false, 'error' => $result['error']], 502);
}

// The call can be slow enough for MySQL to drop an idle connection.
try {
    dbReconnect($connect);
} catch (Throwable $e) {
    error_log('Assistant DB reconnect failed: ' . $e->getMessage());
    assistantJson($connect, ['success' => false, 'error' => 'Could not save your conversation. Please try again.'], 500);
}

// Save the exchange only after a successful reply, so failures leave no orphans.
if ($conversationId === 0) {
    $conversationTitle = assistantTitleFrom($userMessage);
    $stmt = $connect->prepare('INSERT INTO assistant_conversations (user_id, title) VALUES (?, ?)');
    $stmt->bind_param('is', $organiser_id, $conversationTitle);
    $stmt->execute();
    $conversationId = (int)$connect->insert_id;
    $stmt->close();
}

$reply = $result['reply'];
$stmt = $connect->prepare('INSERT INTO assistant_messages (conversation_id, role, content) VALUES (?, ?, ?)');
$role = 'user';
$content = $userMessage;
$stmt->bind_param('iss', $conversationId, $role, $content);
$stmt->execute();
$role = 'assistant';
$content = $reply;
$stmt->execute();
$stmt->close();

// Bump updated_at so the conversation moves to the top of the list.
$stmt = $connect->prepare('UPDATE assistant_conversations SET updated_at = CURRENT_TIMESTAMP WHERE id = ?');
$stmt->bind_param('i', $conversationId);
$stmt->execute();
$stmt->close();

assistantJson($connect, [
    'success' => true,
    'conversation_id' => $conversationId,
    'title' => $conversationTitle,
    'reply' => $reply,
]);
