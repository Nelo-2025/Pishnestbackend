<?php
// Starts the PHP session with cookie params that work for cross-origin
// frontends (e.g. Live Server on http://127.0.0.1:5500 talking to this
// HTTPS API). Without SameSite=None; Secure, browsers refuse to store or
// send the session cookie on credentialed cross-site fetch() calls — so
// login appears to succeed once, then every later page looks logged out.
//
// Also accepts a Bearer / X-Session-Id token (returned by login.php) so
// auth still works when third-party cookies are blocked entirely.

if (session_status() === PHP_SESSION_ACTIVE) {
    return;
}

function eventorgIncomingSessionId(): ?string {
    $candidates = [];

    $auth = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';
    if (preg_match('/^\s*Bearer\s+(\S+)/i', $auth, $matches)) {
        $candidates[] = $matches[1];
    }

    if (!empty($_SERVER['HTTP_X_SESSION_ID'])) {
        $candidates[] = trim((string) $_SERVER['HTTP_X_SESSION_ID']);
    }

    foreach ($candidates as $id) {
        if ($id !== '' && preg_match('/^[A-Za-z0-9,-]{16,128}$/', $id)) {
            return $id;
        }
    }

    return null;
}

$incomingSessionId = eventorgIncomingSessionId();
if ($incomingSessionId) {
    session_id($incomingSessionId);
}

$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || (isset($_SERVER['REQUEST_SCHEME']) && strtolower((string) $_SERVER['REQUEST_SCHEME']) === 'https')
);

// Cross-site cookies require Secure + SameSite=None. On plain HTTP local
// PHP (XAMPP), fall back to Lax so same-origin local setups still work.
session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 14, // 14 days
    'path' => '/',
    'domain' => '',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => $isHttps ? 'None' : 'Lax',
]);

session_start();
