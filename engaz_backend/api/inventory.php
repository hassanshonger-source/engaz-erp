<?php
// engaz_backend/api/inventory.php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Security.php';
require_once __DIR__ . '/../core/Database.php';

Auth::startSession();
Auth::setJsonHeader();
Auth::requireLogin();

$action = $_GET['action'] ?? '';
$dbInstance = Database::getInstance();
$pdo = $dbInstance->getConnection();
$tenant_id = $_SESSION['tenant_id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($action === 'list' && $method === 'GET') {
    // Current stock can be calculated via stock_movements or cached in products.
    // For absolute source of truth, compute sum(IN) - sum(OUT) + sum(ADJ)
    
    $stmt = $pdo->prepare("
        SELECT p.*, 
        COALESCE((
            SELECT SUM(CASE 
                WHEN m.type = 'IN' THEN m.qty 
                WHEN m.type = 'OUT' THEN -m.qty 
                WHEN m.type = 'ADJ' THEN m.qty 
                END)
            FROM stock_movements m 
            WHERE m.product_id = p.id AND m.tenant_id = ?
        ), 0) as current_stock
        FROM products p 
        WHERE p.tenant_id = ? ORDER BY p.name ASC
    ");
    $stmt->execute([$tenant_id, $tenant_id]);
    $products = $stmt->fetchAll();

    echo json_encode(['success' => true, 'products' => $products]);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['csrf_token'])) { http_response_code(403); exit; }
    Security::validateCSRFToken($input['csrf_token']);

    if ($action === 'create') {
        $name = Security::sanitizeInput($input['name'] ?? '');
        $sku = Security::sanitizeInput($input['sku'] ?? '');
        $sale_price = floatval($input['sale_price'] ?? 0);
        $low_stock_threshold = floatval($input['low_stock_threshold'] ?? 0);

        if (empty($name)) {
            http_response_code(400); echo json_encode(['error' => 'Product name required.']); exit;
        }

        $stmt = $pdo->prepare("INSERT INTO products (tenant_id, name, sku, sale_price, low_stock_threshold) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$tenant_id, $name, $sku, $sale_price, $low_stock_threshold]);
        
        echo json_encode(['success' => true, 'message' => 'Product added.']);
        exit;
    }

    if ($action === 'adjust_stock') {
        $product_id = intval($input['product_id'] ?? 0);
        $qty = floatval($input['qty'] ?? 0); // Can be negative or positive
        $cost = floatval($input['cost'] ?? 0);

        $pdo->beginTransaction();
        try {
            // Validate product belongs to tenant
            $chk = $pdo->prepare("SELECT id, avg_cost FROM products WHERE id = ? AND tenant_id = ?");
            $chk->execute([$product_id, $tenant_id]);
            $prod = $chk->fetch();

            if (!$prod) throw new Exception("Product not found");

            // Insert adjustment
            $stmt = $pdo->prepare("INSERT INTO stock_movements (tenant_id, product_id, type, qty, reference_type) VALUES (?, ?, 'ADJ', ?, 'manual_adjustment')");
            $stmt->execute([$tenant_id, $product_id, $qty]);

            // If cost is provided, we might update avg_cost, but typically adjustments don't easily change weighted avg without knowing current stock. 
            // Simplified: only purchases update avg_cost unless specified.

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Stock adjusted.']);
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(400); echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }
}

http_response_code(400);
echo json_encode(['error' => 'Invalid action.']);
