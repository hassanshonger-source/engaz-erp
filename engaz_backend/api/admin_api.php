<?php
// engaz_backend/api/admin_api.php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Security.php';
require_once __DIR__ . '/../core/Database.php';

Auth::startSession();
Auth::setJsonHeader();
Auth::requireAdmin(); // Enforce Admin Only Access

// Only Superadmins can change certain global things if we want, but for now any admin works.
$action = $_GET['action'] ?? '';
$dbInstance = Database::getInstance();
$pdo = $dbInstance->getConnection();

$method = $_SERVER['REQUEST_METHOD'];

if ($action === 'dashboard_stats' && $method === 'GET') {
    // Total Active Tenants
    $stmt1 = $pdo->query("SELECT COUNT(*) FROM tenants WHERE status = 'active'");
    $active = $stmt1->fetchColumn();

    // Trialing
    $stmt2 = $pdo->query("SELECT COUNT(*) FROM tenants WHERE status = 'trialing'");
    $trialing = $stmt2->fetchColumn();

    // Expired
    $stmt3 = $pdo->query("SELECT COUNT(*) FROM tenants WHERE status = 'expired'");
    $expired = $stmt3->fetchColumn();

    echo json_encode([
        'success' => true,
        'stats' => [
            'active' => $active,
            'trialing' => $trialing,
            'expired' => $expired
        ]
    ]);
    exit;
}

if ($action === 'tenants_list' && $method === 'GET') {
    $search = Security::sanitizeInput($_GET['search'] ?? '');
    
    $sql = "SELECT t.id, t.name, t.status, t.subscription_ends_at, t.trial_ends_at, t.created_at, u.id as owner_id 
            FROM tenants t 
            LEFT JOIN users u ON t.id = u.tenant_id AND u.role = 'owner'";
    
    $params = [];
    if (!empty($search)) {
        $sql .= " WHERE t.name LIKE ?";
        $params[] = "%$search%";
    }
    
    $sql .= " ORDER BY t.created_at DESC LIMIT 100";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tenants = $stmt->fetchAll();

    echo json_encode(['success' => true, 'tenants' => $tenants]);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Missing CSRF Token.']);
        exit;
    }
    Security::validateCSRFToken($input['csrf_token']);

    if ($action === 'manage_subscription') {
        $tenant_id = intval($input['tenant_id'] ?? 0);
        $duration = $input['duration'] ?? '';

        if (!$tenant_id || empty($duration)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid parameters.']);
            exit;
        }

        // Calculate end date based on duration
        // Options from form: 1 month, 3 months, 6 months, 1 year
        $stmt = $pdo->prepare("SELECT subscription_ends_at, status FROM tenants WHERE id = ?");
        $stmt->execute([$tenant_id]);
        $tenant = $stmt->fetch();

        if (!$tenant) {
            http_response_code(404);
            echo json_encode(['error' => 'Tenant not found.']);
            exit;
        }
        
        $baseDate = time();
        if ($tenant['status'] === 'active' && !empty($tenant['subscription_ends_at'])) {
            $baseDate = strtotime($tenant['subscription_ends_at']);
            if ($baseDate < time()) {
                $baseDate = time();
            }
        }

        $newEnd = strtotime("+" . $duration, $baseDate);
        $newEndStr = date('Y-m-d H:i:s', $newEnd);

        $update = $pdo->prepare("UPDATE tenants SET status = 'active', subscription_ends_at = ? WHERE id = ?");
        $update->execute([$newEndStr, $tenant_id]);
        
        // Audit
        $audit = $pdo->prepare("INSERT INTO audit_logs (user_id, action, model_type, model_id, meta_json, ip_address) VALUES (?, ?, 'tenant', ?, ?, ?)");
        $audit->execute([
            $_SESSION['admin_id'], 
            'subscription_updated', 
            $tenant_id, 
            json_encode(['old_end' => $tenant['subscription_ends_at'], 'new_end' => $newEndStr, 'added' => $duration]),
            $_SERVER['REMOTE_ADDR']
        ]);

        echo json_encode(['success' => true, 'message' => "Subscription extended by $duration. New expiry: $newEndStr"]);
        exit;
    }

    if ($action === 'generate_temp_password') {
        $tenant_id = intval($input['tenant_id'] ?? 0);
        
        // Let's generate it for the Owner of this tenant for simplicity
        $stmt = $pdo->prepare("SELECT id FROM users WHERE tenant_id = ? AND role = 'owner' LIMIT 1");
        $stmt->execute([$tenant_id]);
        $owner = $stmt->fetchColumn();

        if (!$owner) {
            http_response_code(404);
            echo json_encode(['error' => 'Tenant owner not found.']);
            exit;
        }

        // Generate strong random password
        $bytes = random_bytes(6);
        $tempPass = bin2hex($bytes); // 12 characters

        $hashed = password_hash($tempPass, PASSWORD_DEFAULT);
        
        $update = $pdo->prepare("UPDATE users SET password = ?, must_change_password = 1 WHERE id = ?");
        $update->execute([$hashed, $owner]);
        
        // Audit
        $audit = $pdo->prepare("INSERT INTO audit_logs (user_id, action, model_type, model_id, ip_address) VALUES (?, ?, 'user', ?, ?)");
        $audit->execute([
            $_SESSION['admin_id'], 
            'password_temp_generated', 
            $owner, 
            $_SERVER['REMOTE_ADDR']
        ]);

        echo json_encode([
            'success' => true, 
            'temp_password' => $tempPass,
            'message' => 'Generate successful. MUST physically give this password to the user.'
        ]);
        exit;
    }
}

http_response_code(400);
echo json_encode(['error' => 'Invalid action.']);
