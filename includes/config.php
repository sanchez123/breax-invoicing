<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

$configuredEnvFile = getenv('APP_ENV_FILE');
if (($configuredEnvFile === false || trim($configuredEnvFile) === '') && is_string($_SERVER['APP_ENV_FILE'] ?? null)) {
    $configuredEnvFile = $_SERVER['APP_ENV_FILE'];
}
$envFile = is_string($configuredEnvFile) && trim($configuredEnvFile) !== ''
    ? trim($configuredEnvFile)
    : dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    if (!is_readable($envFile)) {
        throw new RuntimeException('The application environment file is not readable.');
    }
    $fileValues = parse_ini_file($envFile, false, INI_SCANNER_RAW);
    if (!is_array($fileValues)) {
        throw new RuntimeException('The application environment file is invalid.');
    }
    foreach ($fileValues as $key => $value) {
        if (getenv((string) $key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
} elseif (is_string($configuredEnvFile) && trim($configuredEnvFile) !== '') {
    throw new RuntimeException('The configured application environment file does not exist.');
}

date_default_timezone_set('Africa/Nairobi');

$environment = strtolower(trim((string) (getenv('APP_ENV') ?: 'development')));
if ($environment === 'production') {
    $publicBaseUrl = trim((string) (getenv('APP_BASE_URL') ?: getenv('APP_PUBLIC_BASE_URL')));
    $parts = $publicBaseUrl !== '' ? parse_url($publicBaseUrl) : false;
    if (
        $parts === false
        || !isset($parts['scheme'], $parts['host'])
        || strtolower($parts['scheme']) !== 'https'
        || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts)) !== []
        || filter_var($publicBaseUrl, FILTER_VALIDATE_URL) === false
    ) {
        throw new RuntimeException('Production requires APP_BASE_URL to be a valid HTTPS URL.');
    }
}

$scriptPath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
if (str_ends_with($scriptPath, '/dashboard')) {
    $scriptPath = dirname($scriptPath);
}
$scriptPath = rtrim(str_replace('\\', '/', $scriptPath), '/');
define('APP_BASE_URL', $scriptPath === '.' ? '' : $scriptPath);
define('APP_NAME', 'Breax Invoicing');
define('APP_CURRENCY', getenv('APP_CURRENCY') ?: 'KES');

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $isSecure,
        'samesite' => 'Lax',
        'path' => APP_BASE_URL !== '' ? APP_BASE_URL . '/' : '/',
    ]);
    session_start();
}
