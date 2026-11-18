<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/email.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!is_string($submittedToken) || !hash_equals(csrf_token(), $submittedToken)) {
    http_response_code(419);
    exit;
}

if (!can_send_invoice_emails((int) current_user()['id'])) {
    http_response_code(403);
    exit;
}

try {
    $result = runOverdueAdminCheck();
    if ($result['failed'] > 0) {
        error_log(sprintf(
            'Background overdue admin check completed with %d sent and %d failed.',
            $result['sent'],
            $result['failed']
        ));
    }
} catch (Throwable $exception) {
    error_log('Background overdue admin check failed: ' . safe_email_error($exception->getMessage()));
}

http_response_code(204);
