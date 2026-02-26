<?php
// engaz_backend/core/Auth.php

require_once __DIR__ . '/Database.php';

class Auth {
    public static function startSession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Mandates that a valid tenant user is logged in.
     * Enforces subscription active/trialing fallback check.
     */
    public static function requireLogin() {
        self::startSession();
        
        if (empty($_SESSION['user_id']) || empty($_SESSION['tenant_id'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized. Please log in.']);
            exit;
        }
        
        // Enforce subscription fallback
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT status FROM tenants WHERE id = ?");
        $stmt->execute([$_SESSION['tenant_id']]);
        $status = $stmt->fetchColumn();
        
        if ($status !== 'active' && $status !== 'trialing') {
            http_response_code(403);
            echo json_encode([
                'error' => 'Subscription required.', 
                'code' => 'SUBSCRIPTION_REQUIRED'
            ]);
            exit;
        }
    }

    /**
     * Mandates that an admin user is logged in.
     */
    public static function requireAdmin() {
        self::startSession();
        
        if (empty($_SESSION['admin_id'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Admin Unauthorized.']);
            exit;
        }
    }

    /**
     * Provides JSON headers globally for APIs.
     */
    public static function setJsonHeader() {
        header('Content-Type: application/json; charset=utf-8');
    }
}
