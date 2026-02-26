<?php
// engaz_backend/api/contacts.php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Security.php';
require_once __DIR__ . '/../core/Database.php';

Auth::startSession();
Auth::setJsonHeader();
Auth::requireLogin();

$action = $_GET['action'] ?? '';
$type = $_GET['type'] ?? 'customer'; // customer or supplier
$dbInstance = Database::getInstance();
$pdo = $dbInstance->getConnection();
$tenant_id = $_SESSION['tenant_id'];
$method = $_SERVER['REQUEST_METHOD'];

$table = $type === 'supplier' ? 'suppliers' : 'customers';

if ($action === 'list' && $method === 'GET') {
    $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE tenant_id = ? ORDER BY name ASC");
    $stmt->execute([$tenant_id]);
    $contacts = $stmt->fetchAll();

    echo json_encode(['success' => true, 'contacts' => $contacts]);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['csrf_token'])) { http_response_code(403); exit; }
    Security::validateCSRFToken($input['csrf_token']);

    if ($action === 'create') {
        $name = Security::sanitizeInput($input['name'] ?? '');
        $phone = Security::sanitizeInput($input['phone'] ?? '');
        $address = Security::sanitizeInput($input['address'] ?? '');
        $activity = Security::sanitizeInput($input['activity'] ?? '');
        $opening = floatval($input['opening_balance'] ?? 0);

        if (empty($name)) {
            http_response_code(400); echo json_encode(['error' => 'Name is required.']); exit;
        }

        $stmt = $pdo->prepare("INSERT INTO `$table` (tenant_id, name, phone, address, activity, opening_balance) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$tenant_id, $name, $phone, $address, $activity, $opening]);

        echo json_encode(['success' => true, 'message' => ucfirst($type) . ' added successfully.', 'id' => $pdo->lastInsertId()]);
        exit;
    }
}

http_response_code(400);
echo json_encode(['error' => 'Invalid action.']);
