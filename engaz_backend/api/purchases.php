<?php
// engaz_backend/api/purchases.php

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
        SELECT i.*, s.name as supplier_name 
        FROM purchase_invoices i
        LEFT JOIN suppliers s ON i.supplier_id = s.id
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
        $supplier_id = intval($input['supplier_id'] ?? 0);
        $items = $input['items'] ?? []; 
        
        if (!$supplier_id || empty($items)) {
            http_response_code(400); echo json_encode(['error' => 'Supplier and Items required.']); exit;
        }

        $invoice_no = "PINV-" . time();
        $date = date('Y-m-d');
        
        $pdo->beginTransaction();
        try {
            $subtotal = 0;
            foreach ($items as $item) {
                $subtotal += floatval($item['qty']) * floatval($item['cost']);
            }
            $total = $subtotal;

            $stmt = $pdo->prepare("INSERT INTO purchase_invoices (tenant_id, supplier_id, invoice_no, date, subtotal, total, status) VALUES (?, ?, ?, ?, ?, ?, 'DRAFT')");
            $stmt->execute([$tenant_id, $supplier_id, $invoice_no, $date, $subtotal, $total]);
            $invoice_id = $pdo->lastInsertId();

            $insertItem = $pdo->prepare("INSERT INTO purchase_invoice_items (tenant_id, invoice_id, product_id, qty, unit_cost, total) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($items as $item) {
                $lineTotal = floatval($item['qty']) * floatval($item['cost']);
                $insertItem->execute([$tenant_id, $invoice_id, $item['product_id'], $item['qty'], $item['cost'], $lineTotal]);
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Draft purchase invoice created.', 'invoice_id' => $invoice_id]);
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'post') {
        $invoice_id = intval($input['invoice_id'] ?? 0);
        
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT status FROM purchase_invoices WHERE id = ? AND tenant_id = ? FOR UPDATE");
            $stmt->execute([$invoice_id, $tenant_id]);
            $inv = $stmt->fetch();
            
            if (!$inv) throw new Exception("Invoice not found");
            if ($inv['status'] !== 'DRAFT') throw new Exception("Only DRAFT invoices can be posted.");

            $pdo->prepare("UPDATE purchase_invoices SET status = 'POSTED' WHERE id = ?")->execute([$invoice_id]);

            $itemsStmt = $pdo->prepare("SELECT * FROM purchase_invoice_items WHERE invoice_id = ?");
            $itemsStmt->execute([$invoice_id]);
            $items = $itemsStmt->fetchAll();

            $stockIns = $pdo->prepare("INSERT INTO stock_movements (tenant_id, product_id, type, qty, reference_id, reference_type) VALUES (?, ?, 'IN', ?, ?, 'purchase_invoice')");
            
            // Note: Weighted average cost logic can be added here
            $updateCost = $pdo->prepare("UPDATE products SET avg_cost = ? WHERE id = ? AND tenant_id = ?");

            foreach ($items as $item) {
                $stockIns->execute([$tenant_id, $item['product_id'], $item['qty'], $invoice_id]);
                // Simplified avg_cost update (just sets it to latest cost to avoid complex running avg in this example)
                // In full implementation, avg_cost = ((old_qty * old_cost) + (new_qty * new_cost)) / (old_qty + new_qty)
                $updateCost->execute([$item['unit_cost'], $item['product_id'], $tenant_id]);
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Purchase posted. Stock increased.']);
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(400); echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'void') {
        $invoice_id = intval($input['invoice_id'] ?? 0);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT status FROM purchase_invoices WHERE id = ? AND tenant_id = ? FOR UPDATE");
            $stmt->execute([$invoice_id, $tenant_id]);
            $inv = $stmt->fetch();
            
            if (!$inv) throw new Exception("Invoice not found");
            if ($inv['status'] !== 'POSTED') throw new Exception("Only POSTED invoices can be voided.");

            $pdo->prepare("UPDATE purchase_invoices SET status = 'VOID' WHERE id = ?")->execute([$invoice_id]);

            $itemsStmt = $pdo->prepare("SELECT * FROM purchase_invoice_items WHERE invoice_id = ?");
            $itemsStmt->execute([$invoice_id]);
            $items = $itemsStmt->fetchAll();

            $stockIns = $pdo->prepare("INSERT INTO stock_movements (tenant_id, product_id, type, qty, reference_id, reference_type) VALUES (?, ?, 'OUT', ?, ?, 'purchase_invoice_void')");
            foreach ($items as $item) {
                // To reverse a POSTED purchase, we deduct (OUT) the qty.
                $stockIns->execute([$tenant_id, $item['product_id'], $item['qty'], $invoice_id]);
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Purchase voided securely. Stock decreased.']);
        } catch(Exception $e) {
            $pdo->rollBack();
            http_response_code(400); echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }
}

http_response_code(400);
echo json_encode(['error' => 'Invalid action.']);
