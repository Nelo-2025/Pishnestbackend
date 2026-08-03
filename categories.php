<?php
header('Content-Type: application/json; charset=utf-8');

include 'conn.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["success" => false, "error" => "Method not allowed"]);
    exit;
}

$result = $connect->query("SELECT id, name FROM categories ORDER BY name ASC");
$categories = $result->fetch_all(MYSQLI_ASSOC);

// Returned as a plain array (not wrapped in {success, categories}) because
// index.js's loadCategories() expects `data.length` directly on the response.
echo json_encode($categories);

$connect->close();
exit;
