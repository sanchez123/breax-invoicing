<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/email.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'POST is required.']);
    exit;
}

$expectedToken = mail_setting('OVERDUE_CHECK_TOKEN');
$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$providedToken = is_string($authorization) && preg_match('/\ABearer\s+(.+)\z/i', $authorization, $matches)
    ? trim($matches[1])
    : '';
if ($expectedToken === '' || $providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden.']);
    exit;
}

try {
    $result = runOverdueAdminCheck(null, true);
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log('Secured overdue admin check failed: ' . safe_email_error($exception->getMessage()));
    http_response_code(500);
    echo json_encode(['error' => 'The overdue check failed.']);
}
