<?php
// engaz_backend/api/sales.php

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
    $stmt = $pdo->prepare("
        SELECT i.*, c.name as customer_name 
        FROM sales_invoices i
        LEFT JOIN customers c ON i.customer_id = c.id
        WHERE i.tenant_id = ? ORDER BY i.created_at DESC LIMIT 100
    ");
    $stmt->execute([$tenant_id]);
    $invoices = $stmt->fetchAll();

    echo json_encode(['success' => true, 'invoices' => $invoices]);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['csrf_token'])) { http_response_code(403); exit; }
    Security::validateCSRFToken($input['csrf_token']);

    if ($action === 'create') {
        $customer_id = intval($input['customer_id'] ?? 0);
        $items = $input['items'] ?? []; // Array of {product_id, qty, price}
        
        if (!$customer_id || empty($items)) {
            http_response_code(400); echo json_encode(['error' => 'Customer and Items required.']); exit;
        }

        $invoice_no = "INV-" . time(); // Simple sequential mapping can be done later via settings loop
        $date = date('Y-m-d');
        
        $pdo->beginTransaction();
        try {
            $subtotal = 0;
            // First pass to compute totals
            foreach ($items as $item) {
                $subtotal += floatval($item['qty']) * floatval($item['price']);
            }
            $total = $subtotal; // Add tax logic later

            $stmt = $pdo->prepare("INSERT INTO sales_invoices (tenant_id, customer_id, invoice_no, date, subtotal, total, status) VALUES (?, ?, ?, ?, ?, ?, 'DRAFT')");
            $stmt->execute([$tenant_id, $customer_id, $invoice_no, $date, $subtotal, $total]);
            $invoice_id = $pdo->lastInsertId();

            $insertItem = $pdo->prepare("INSERT INTO sales_invoice_items (tenant_id, invoice_id, product_id, qty, unit_price, total) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($items as $item) {
                $lineTotal = floatval($item['qty']) * floatval($item['price']);
                $insertItem->execute([$tenant_id, $invoice_id, $item['product_id'], $item['qty'], $item['price'], $lineTotal]);
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Draft invoice created.', 'invoice_id' => $invoice_id]);
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'post') {
        // Posts a draft invoice: Changes status to POSTED, deducts inventory
        $invoice_id = intval($input['invoice_id'] ?? 0);
        
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT status FROM sales_invoices WHERE id = ? AND tenant_id = ? FOR UPDATE");
            $stmt->execute([$invoice_id, $tenant_id]);
            $inv = $stmt->fetch();
            
            if (!$inv) throw new Exception("Invoice not found");
            if ($inv['status'] !== 'DRAFT') throw new Exception("Only DRAFT invoices can be posted.");

            // Update status
            $pdo->prepare("UPDATE sales_invoices SET status = 'POSTED' WHERE id = ?")->execute([$invoice_id]);

            // Get items
            $itemsStmt = $pdo->prepare("SELECT * FROM sales_invoice_items WHERE invoice_id = ?");
            $itemsStmt->execute([$invoice_id]);
            $items = $itemsStmt->fetchAll();

            // Create OUT stock movements
            $stockIns = $pdo->prepare("INSERT INTO stock_movements (tenant_id, product_id, type, qty, reference_id, reference_type) VALUES (?, ?, 'OUT', ?, ?, 'sales_invoice')");
            foreach ($items as $item) {
                $stockIns->execute([$tenant_id, $item['product_id'], $item['qty'], $invoice_id]);
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Invoice posted successfully. Stock updated.']);
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(400); echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'void') {
        // Voids a posted invoice: Changes status to VOID, reverses inventory with IN movements
        $invoice_id = intval($input['invoice_id'] ?? 0);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT status FROM sales_invoices WHERE id = ? AND tenant_id = ? FOR UPDATE");
            $stmt->execute([$invoice_id, $tenant_id]);
            $inv = $stmt->fetch();
            
            if (!$inv) throw new Exception("Invoice not found");
            if ($inv['status'] !== 'POSTED') throw new Exception("Only POSTED invoices can be voided.");

            $pdo->prepare("UPDATE sales_invoices SET status = 'VOID' WHERE id = ?")->execute([$invoice_id]);

            // Reversing Stock Movements securely using the original OUT quantities
            // Easiest is to select original items and do an 'IN'
            $itemsStmt = $pdo->prepare("SELECT * FROM sales_invoice_items WHERE invoice_id = ?");
            $itemsStmt->execute([$invoice_id]);
            $items = $itemsStmt->fetchAll();

            $stockIns = $pdo->prepare("INSERT INTO stock_movements (tenant_id, product_id, type, qty, reference_id, reference_type) VALUES (?, ?, 'IN', ?, ?, 'sales_invoice_void')");
            foreach ($items as $item) {
                $stockIns->execute([$tenant_id, $item['product_id'], $item['qty'], $invoice_id]);
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Invoice voided securely. Stock restored.']);
        } catch(Exception $e) {
            $pdo->rollBack();
            http_response_code(400); echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }
}

http_response_code(400);
echo json_encode(['error' => 'Invalid action.']);
