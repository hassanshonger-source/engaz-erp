<?php
// engaz_backend/api/admin_auth.php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Security.php';
require_once __DIR__ . '/../core/Database.php';

Auth::startSession();
Auth::setJsonHeader();

$action = $_GET['action'] ?? '';

if ($action === 'csrf') {
    echo json_encode(['csrf_token' => Security::generateCSRFToken()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Missing CSRF Token.']);
    exit;
}
Security::validateCSRFToken($input['csrf_token']);

$dbInstance = Database::getInstance();
$pdo = $dbInstance->getConnection();

if ($action === 'login') {
    $username = Security::sanitizeInput($input['username'] ?? '');
    $password = $input['password'] ?? '';
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $identifier = 'ADMIN_' . $username;

    if (!Security::checkLoginRateLimit($ip_address, $identifier)) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many failed attempts. Try again later.']);
        exit;
    }

    if (empty($username) || empty($password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Username and Password are required.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ?");
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($password, $admin['password'])) {
        Security::clearLoginAttempts($ip_address, $identifier);
        
        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['is_superadmin'] = $admin['is_superadmin'];
        $_SESSION['must_change_password'] = $admin['must_change_password'];
        Security::regenerateSession();

        echo json_encode([
            'success' => true, 
            'must_change_password' => (bool)$admin['must_change_password']
        ]);
        exit;
    } else {
        Security::recordFailedLogin($ip_address, $identifier);
        http_response_code(401);
        echo json_encode(['error' => 'Invalid credentials.']);
        exit;
    }
}

if ($action === 'change_default_password') {
    // Only accessible if logged in but must_change_password = 1
    if (empty($_SESSION['admin_id'])) {
        http_response_code(401);
        exit;
    }

    $new_password = $input['new_password'] ?? '';
    if (strlen($new_password) < 8) {
        http_response_code(400);
        echo json_encode(['error' => 'Password must be at least 8 characters long.']);
        exit;
    }

    $hashed = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE admin_users SET password = ?, must_change_password = 0 WHERE id = ?");
    $stmt->execute([$hashed, $_SESSION['admin_id']]);

    $_SESSION['must_change_password'] = 0;

    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'logout') {
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid action.']);
