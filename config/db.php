<?php
define('DB_HOST', getenv('DB_HOST') ?: 'sql8.freesqldatabase.com');
define('DB_NAME', getenv('DB_NAME') ?: 'sql8833107');
define('DB_USER', getenv('DB_USER') ?: 'sql8833107');
define('DB_PASS', getenv('DB_PASS') ?: '1kFcb6rrA2');

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
            exit;
        }
    }
    return $pdo;
}
