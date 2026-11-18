<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $environment = strtolower(trim((string) (getenv('APP_ENV') ?: 'development')));
    $host = getenv('APP_DB_HOST') ?: '127.0.0.1';
    $name = getenv('APP_DB_NAME') ?: 'breax_invoicing';
    $user = getenv('APP_DB_USER') ?: 'root';
    $password = getenv('APP_DB_PASSWORD') ?: '';
    if ($environment === 'production' && (
        !is_string(getenv('APP_DB_HOST')) || trim((string) getenv('APP_DB_HOST')) === ''
        || !is_string(getenv('APP_DB_NAME')) || trim((string) getenv('APP_DB_NAME')) === ''
        || !is_string(getenv('APP_DB_USER')) || trim((string) getenv('APP_DB_USER')) === ''
    )) {
        error_log('Production database configuration is incomplete.');
        http_response_code(500);
        exit('The application database is not configured. Contact the site administrator.');
    }
    $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";

    try {
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $exception) {
        error_log('Database connection failed: ' . $exception->getMessage());
        http_response_code(500);
        exit('Database connection failed. Check the application database configuration.');
    }
    return $pdo;
}