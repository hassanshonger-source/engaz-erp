<?php
// engaz_backend/core/Database.php

require_once __DIR__ . '/../config/config.php';

class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // Important for security (prevents SQL injection)
        ];
        try {
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (\PDOException $e) {
            // Log error internally in production, avoid exposing details
            die(json_encode(["error" => "Database connection failed."]));
        }
    }

    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->pdo;
    }

    /**
     * Executes a query with provided parameters.
     * Always use this to benefit from prepared statements.
     */
    public function query($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Helper specifically for tenant-scoped fetches to ensure
     * tenant_id is always mandated for multi-tenant tables.
     */
    public function fetchAllTenant($table, $tenant_id, $conditions = "", $params = []) {
        $sql = "SELECT * FROM `$table` WHERE tenant_id = ?";
        array_unshift($params, $tenant_id);
        
        if (!empty($conditions)) {
            $sql .= " AND " . $conditions;
        }
        
        return $this->query($sql, $params)->fetchAll();
    }
}
