<?php
// Shared auth helpers. The app session must be started BEFORE this file is
// included (and before any output), so every endpoint that uses this does:
//
//   require_once __DIR__ . '/session-boot.php';
//   include 'conn.php';
//   include 'auth.php';

function currentUserId(): ?int {
    return !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function currentUserRole(): string {
    return strtolower(trim((string)($_SESSION['role'] ?? '')));
}

function isLoggedIn(): bool {
    return currentUserId() !== null;
}

// Accepts both UK and US spelling since the database stores 'organiser'
// but some older code/checks used 'organizer'.
function canManageEvents(): bool {
    return in_array(currentUserRole(), ['admin', 'organiser', 'organizer'], true);
}

function isAdmin(): bool {
    return currentUserRole() === 'admin';
}

// Call this at the top of any endpoint that requires the user to be logged
// in as an organiser/admin. Exits with a 401 JSON response if not.
function requireOrganiser(mysqli $connect): int {
    if (!isLoggedIn() || !canManageEvents()) {
        http_response_code(401);
        echo json_encode(["success" => false, "error" => "You must be logged in as an organiser to do this."]);
        $connect->close();
        exit;
    }
    return currentUserId();
}

// Call this at the top of any endpoint that just requires being logged in
// (any role). Exits with a 401 JSON response if not.
function requireLogin(mysqli $connect): int {
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(["success" => false, "error" => "You must be logged in to do this."]);
        $connect->close();
        exit;
    }
    return currentUserId();
}
