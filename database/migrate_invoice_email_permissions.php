<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/db.php';

try {
    $columns = array_column(db()->query('SHOW COLUMNS FROM users')->fetchAll(), 'Field');
    if (!in_array('can_send_invoice_emails', $columns, true)) {
        db()->exec('ALTER TABLE users ADD COLUMN can_send_invoice_emails TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash');
        db()->exec('UPDATE users SET can_send_invoice_emails=1');
        fwrite(STDOUT, "Invoice email permission added; existing staff retain send access.\n");
    } else {
        fwrite(STDOUT, "Invoice email permission is already present.\n");
    }
} catch (Throwable $exception) {
    error_log('Invoice email permission migration failed: ' . $exception->getMessage());
    fwrite(STDERR, "Invoice email permission migration failed. Check the database configuration and permissions.\n");
    exit(1);
}
