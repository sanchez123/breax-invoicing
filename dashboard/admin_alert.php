<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/email.php';
require_auth();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$invoiceId = filter_var($_GET['id'] ?? $_POST['invoice_id'] ?? null, FILTER_VALIDATE_INT);
if (!$invoiceId || $invoiceId < 1) {
    http_response_code(400);
    echo json_encode(['message' => 'Invoice not found.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $latestQuery = db()->prepare("SELECT sent_at FROM email_log
            WHERE invoice_id=? AND type='admin_overdue_alert' AND status='sent' AND sent_at IS NOT NULL
            ORDER BY sent_at DESC,id DESC LIMIT 1");
        $latestQuery->execute([$invoiceId]);
        $sentAt = $latestQuery->fetchColumn();
        echo json_encode([
            'sent' => $sentAt !== false,
            'sentAt' => $sentAt !== false ? $sentAt : null,
        ], JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        error_log('Admin alert status lookup failed: ' . safe_email_error($exception->getMessage()));
        http_response_code(500);
        echo json_encode(['message' => 'The alert status could not be checked.']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['message' => 'GET or POST is required.']);
    exit;
}

if (!can_send_invoice_emails()) {
    http_response_code(403);
    echo json_encode(['message' => 'You do not have permission to send invoice emails.']);
    exit;
}

$submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!is_string($submittedToken) || !hash_equals(csrf_token(), $submittedToken)) {
    http_response_code(419);
    echo json_encode(['message' => 'Your session expired. Refresh the page and try again.']);
    exit;
}

try {
    if (($_POST['action'] ?? '') !== 'resend') {
        http_response_code(400);
        echo json_encode(['message' => 'Invalid admin alert action.']);
        exit;
    }

    $result = resendOverdueAdminAlert((int) $invoiceId);
    if ($result['failed'] > 0) {
        http_response_code(502);
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $exception) {
    http_response_code(409);
    echo json_encode(['message' => $exception->getMessage()]);
} catch (Throwable $exception) {
    error_log('Manual admin alert resend failed: ' . safe_email_error($exception->getMessage()));
    http_response_code(500);
    echo json_encode(['message' => 'The alert could not be sent. Check the invoice email log and settings.']);
}
