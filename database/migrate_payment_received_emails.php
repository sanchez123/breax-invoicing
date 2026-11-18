<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/db.php';

try {
    $pdo = db();
    $pdo->exec("ALTER TABLE email_log MODIFY type ENUM(
        'invoice_issued',
        'invoice_partial',
        'invoice_overdue',
        'payment_received',
        'admin_overdue_alert',
        'admin_domain_expiry_report',
        'admin_overdue_invoice_report',
        'admin_google_workspace_report',
        'template_test'
    ) NOT NULL");
    fwrite(STDOUT, "Automatic payment receipt email logging is ready.\n");
} catch (Throwable $exception) {
    error_log('Payment receipt email migration failed: ' . $exception->getMessage());
    fwrite(STDERR, "Payment receipt email migration failed. Check database configuration and migration permissions.\n");
    exit(1);
}
