<?php
// engaz_backend/core/Security.php

require_once __DIR__ . '/Database.php';

class Security {

    /**
     * Regenerates the session ID to prevent session fixation.
     * Should be called upon successful login.
     */
    public static function regenerateSession() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * Generates a CSRF token and stores it in the session.
     */
    public static function generateCSRFToken() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Validates an incoming CSRF token.
     */
    public static function validateCSRFToken($token) {
        if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid CSRF token.']);
            exit;
        }
    }

    /**
     * XSS Output Escaping
     */
    public static function escape($html) {
        return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    }

    /**
     * Sanitizes general string input
     */
    public static function sanitizeInput($data) {
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
        return $data;
    }

    /**
     * Evaluates IP and code login attempts.
     * Requirements: 
     * - Max 5 attempts per 10 mins.
     * - Lock 15 mins after 5 attempts on same code.
     * - Lock 60 mins after 10 attempts.
     * 
     * Returns true if allowed, false if locked.
     */
    public static function checkLoginRateLimit($ip_address, $code) {
        $db = Database::getInstance();
        $pdo = $db->getConnection();

        // Cleanup expired locks strictly older than 60 mins
        // (Just a healthy cleanup, optional)
        
        // Fetch attempt record for this IP + Code combination
        $stmt = $pdo->prepare("SELECT * FROM login_attempts WHERE ip_address = ? AND code = ?");
        $stmt->execute([$ip_address, $code]);
        $attempt = $stmt->fetch();

        if ($attempt) {
            if ($attempt['locked_until'] !== null) {
                if (strtotime($attempt['locked_until']) > time()) {
                    // Still locked
                    return false;
                } else {
                    // Lock expired, reset to 0
                    $pdo->prepare("UPDATE login_attempts SET attempts = 0, locked_until = NULL WHERE id = ?")->execute([$attempt['id']]);
                    return true;
                }
            }
        }
        return true;
    }

    /**
     * Records a failed login attempt.
     */
    public static function recordFailedLogin($ip_address, $code) {
        $db = Database::getInstance();
        $pdo = $db->getConnection();

        $stmt = $pdo->prepare("SELECT * FROM login_attempts WHERE ip_address = ? AND code = ?");
        $stmt->execute([$ip_address, $code]);
        $attempt = $stmt->fetch();

        if ($attempt) {
            $attempts = $attempt['attempts'] + 1;
            $locked_until = null;

            if ($attempts >= 10) {
                $locked_until = date('Y-m-d H:i:s', strtotime('+60 minutes'));
            } else if ($attempts >= 5) {
                $locked_until = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            }

            $update = $pdo->prepare("UPDATE login_attempts SET attempts = ?, locked_until = ?, updated_at = NOW() WHERE id = ?");
            $update->execute([$attempts, $locked_until, $attempt['id']]);
        } else {
            $insert = $pdo->prepare("INSERT INTO login_attempts (ip_address, code, attempts) VALUES (?, ?, 1)");
            $insert->execute([$ip_address, $code]);
        }
    }

    /**
     * Resets failed login attempts after a successful login.
     */
    public static function clearLoginAttempts($ip_address, $code) {
        $db = Database::getInstance();
        $db->query("DELETE FROM login_attempts WHERE ip_address = ? AND code = ?", [$ip_address, $code]);
    }
}
