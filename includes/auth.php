<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

function current_user(): ?array
{
    return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
}

function require_auth(): void
{
    if (current_user() === null) {
        redirect('/login.php');
    }
}

function can_send_invoice_emails(?int $userId = null): bool
{
    $userId ??= (int) (current_user()['id'] ?? 0);
    if ($userId < 1) {
        return false;
    }

    $query = db()->prepare('SELECT can_send_invoice_emails FROM users WHERE id=?');
    $query->execute([$userId]);
    return (int) $query->fetchColumn() === 1;
}

function require_invoice_email_permission(): void
{
    if (!can_send_invoice_emails()) {
        http_response_code(403);
        exit('You do not have permission to send invoice emails.');
    }
}

function redirect(string $path): void
{
    header('Location: ' . app_url($path));
    exit;
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'name' => (string) $user['name'],
        'email' => (string) $user['email'],
    ];
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}