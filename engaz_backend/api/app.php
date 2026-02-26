<?php
// engaz_backend/api/app.php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Security.php';
require_once __DIR__ . '/../core/Database.php';

Auth::startSession();
Auth::setJsonHeader();
Auth::requireLogin(); // Mandates user is logged in & subscription valid

$action = $_GET['action'] ?? '';
$dbInstance = Database::getInstance();
$pdo = $dbInstance->getConnection();
$tenant_id = $_SESSION['tenant_id'];
$user_id = $_SESSION['user_id'];

if ($action === 'init' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    // 1. Fetch User Info
    $stmt = $pdo->prepare("SELECT name, role, must_change_password FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if ($user['must_change_password']) {
        echo json_encode(['success' => true, 'must_change_password' => true]);
        exit;
    }

    // 2. Fetch Tenant & Settings
    $stmt2 = $pdo->prepare("SELECT t.name as tenant_name, t.status,
                                   s.language, s.theme, s.currency, s.company_name
                            FROM tenants t
                            LEFT JOIN company_settings s ON t.id = s.tenant_id
                            WHERE t.id = ?");
    $stmt2->execute([$tenant_id]);
    $tenant = $stmt2->fetch();

    // 3. Fetch KPI Stats
    $today = date('Y-m-d');
    $monthStart = date('Y-m-01');
    $monthEnd = date('Y-m-t');

    // Sales Today
    $s1 = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM sales_invoices WHERE tenant_id = ? AND status = 'POSTED' AND date = ?");
    $s1->execute([$tenant_id, $today]);
    $sales_today = $s1->fetchColumn();

    // Sales Month
    $s2 = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM sales_invoices WHERE tenant_id = ? AND status = 'POSTED' AND date BETWEEN ? AND ?");
    $s2->execute([$tenant_id, $monthStart, $monthEnd]);
    $sales_month = $s2->fetchColumn();

    // Expenses Month
    $s3 = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE tenant_id = ? AND date BETWEEN ? AND ?");
    $s3->execute([$tenant_id, $monthStart, $monthEnd]);
    $expenses_month = $s3->fetchColumn();

    echo json_encode([
        'success' => true,
        'user' => [
            'name' => $user['name'],
            'role' => $user['role']
        ],
        'tenant' => [
            'name' => $tenant['tenant_name'],
            'status' => $tenant['status'],
        ],
        'settings' => [
            'company_name' => $tenant['company_name'] ?? $tenant['tenant_name'],
            'language' => $tenant['language'] ?? 'en',
            'theme' => $tenant['theme'] ?? 'light',
            'currency' => $tenant['currency'] ?? 'USD',
        ],
        'kpi' => [
            'sales_today' => number_format((float)$sales_today, 2),
            'sales_month' => number_format((float)$sales_month, 2),
            'expenses_month' => number_format((float)$expenses_month, 2),
            'low_stock' => 0 // To be implemented with inventory logic
        ]
    ]);
    exit;
}

if ($action === 'change_force_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['csrf_token'])) { http_response_code(403); exit; }
    Security::validateCSRFToken($input['csrf_token']);

    $new_password = $input['new_password'] ?? '';
    if (strlen($new_password) < 6) {
        http_response_code(400); echo json_encode(['error' => 'Password too short']); exit;
    }

    $hashed = password_hash($new_password, PASSWORD_DEFAULT);
    $update = $pdo->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?");
    $update->execute([$hashed, $user_id]);

    // Audit
    $audit = $pdo->prepare("INSERT INTO audit_logs (tenant_id, user_id, action, ip_address) VALUES (?, ?, 'password_force_changed', ?)");
    $audit->execute([$tenant_id, $user_id, $_SERVER['REMOTE_ADDR']]);

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid action.']);
