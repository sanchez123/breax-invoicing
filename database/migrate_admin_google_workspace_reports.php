<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/db.php';

try {
    $pdo = db();
    $column = $pdo->query("SHOW COLUMNS FROM email_log LIKE 'type'")->fetch();
    if (!$column || !is_string($column['Type'] ?? null)) {
        throw new RuntimeException('The email_log type column could not be inspected.');
    }
    if (!str_contains($column['Type'], "'admin_google_workspace_report'")) {
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
    }
    fwrite(STDOUT, "Google Workspace report email logging is ready.\n");
} catch (Throwable $exception) {
    error_log('Google Workspace report migration failed: ' . mb_substr(strip_tags($exception->getMessage()), 0, 500));
    fwrite(STDERR, "Google Workspace report migration failed. Check database configuration and permissions.\n");
    exit(1);
}
