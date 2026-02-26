<?php
// engaz_backend/config/config.php

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'engaz_erp');

// Application URLs (Adjust based on deployment environment)
define('APP_URL', 'http://localhost'); 
define('ADMIN_URL', 'http://admin.localhost'); 

// Secure Session Settings
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');
// ini_set('session.cookie_secure', 1); // Enable when running on HTTPS

// Error reporting (Disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Timezone
date_default_timezone_set('UTC');
