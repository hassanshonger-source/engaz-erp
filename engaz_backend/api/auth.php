<?php
// engaz_backend/api/auth.php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Security.php';
require_once __DIR__ . '/../core/Database.php';

Auth::startSession();
Auth::setJsonHeader();

$action = $_GET['action'] ?? '';

// Generate CSRF Token for initial load
if ($action === 'csrf') {
    echo json_encode(['csrf_token' => Security::generateCSRFToken()]);
    exit;
}

// Ensure POST for other actions
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// Skip CSRF validation on strictly initial signup/login (if desired), 
// but it's better to enforce it. For this SaaS, let's assume the frontend fetches CSRF first.
if (!isset($input['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Missing CSRF Token.']);
    exit;
}
Security::validateCSRFToken($input['csrf_token']);

$dbInstance = Database::getInstance();
$pdo = $dbInstance->getConnection();

if ($action === 'signup') {
    $name = Security::sanitizeInput($input['name'] ?? '');
    $whatsapp = Security::sanitizeInput($input['whatsapp'] ?? '');
    $password = $input['password'] ?? '';
    $business_activity = Security::sanitizeInput($input['business_activity'] ?? '');
    $email = Security::sanitizeInput($input['email'] ?? '');

    if (empty($name) || empty($password) || empty($whatsapp)) {
        http_response_code(400);
        echo json_encode(['error' => 'Name, WhatsApp, and Password are required.']);
        exit;
    }

    // Generate strict 4-digit code (must be unique globally to simplify login)
    $code = "";
    $isUnique = false;
    while (!$isUnique) {
        $code = (string)random_int(1000, 9999);
        $stmt = $pdo->prepare("SELECT id FROM users WHERE code = ?");
        $stmt->execute([$code]);
        if (!$stmt->fetch()) {
            $isUnique = true;
        }
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    // Trial ends in 7 days
    $trial_ends_at = date('Y-m-d H:i:s', strtotime('+7 days'));

    try {
        $pdo->beginTransaction();

        // 1. Create Tenant
        $stmt = $pdo->prepare("INSERT INTO tenants (name, business_activity, status, trial_ends_at) VALUES (?, ?, 'trialing', ?)");
        $stmt->execute([$name, $business_activity, $trial_ends_at]);
        $tenant_id = $pdo->lastInsertId();

        // 2. Create User
        $stmt2 = $pdo->prepare("INSERT INTO users (tenant_id, name, code, password, whatsapp, email, role) VALUES (?, ?, ?, ?, ?, ?, 'owner')");
        $stmt2->execute([$tenant_id, $name, $code, $hashed_password, $whatsapp, $email]);
        $user_id = $pdo->lastInsertId();

        // 3. Create basic settings
        $stmt3 = $pdo->prepare("INSERT INTO company_settings (tenant_id, company_name, whatsapp) VALUES (?, ?, ?)");
        $stmt3->execute([$tenant_id, $name, $whatsapp]);
        
        $pdo->commit();

        // Auto login
        $_SESSION['user_id'] = $user_id;
        $_SESSION['tenant_id'] = $tenant_id;
        Security::regenerateSession();

        echo json_encode([
            'success' => true,
            'message' => 'Account created successfully.',
            'code' => $code // Crucial for user to see
        ]);
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Server error during signup.']);
        exit;
    }
}

if ($action === 'login') {
    $code = Security::sanitizeInput($input['code'] ?? '');
    $password = $input['password'] ?? '';
    $ip_address = $_SERVER['REMOTE_ADDR'];

    // 1. Validate rate limits BEFORE verifying DB code
    if (!Security::checkLoginRateLimit($ip_address, $code)) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many failed attempts. Try again later.']);
        exit;
    }

    if (empty($code) || empty($password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Code and Password are required.']);
        exit;
    }

    // 2. Verify identity securely
    $stmt = $pdo->prepare("SELECT * FROM users WHERE code = ?");
    $stmt->execute([$code]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        // Success
        Security::clearLoginAttempts($ip_address, $code);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['tenant_id'] = $user['tenant_id'];
        $_SESSION['role'] = $user['role'];
        Security::regenerateSession();

        // Record Audit log
        $audit = $pdo->prepare("INSERT INTO audit_logs (tenant_id, user_id, action, ip_address) VALUES (?, ?, 'login', ?)");
        $audit->execute([$user['tenant_id'], $user['id'], $ip_address]);

        echo json_encode(['success' => true, 'message' => 'Logged in successfully.']);
        exit;
    } else {
        // Fail
        Security::recordFailedLogin($ip_address, $code);
        http_response_code(401);
        echo json_encode(['error' => 'Invalid credentials.']); // Never confirm if code exists or not
        exit;
    }
}

if ($action === 'logout') {
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid action.']);
