<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/db.php';

$pdo = db();
$down = in_array('--down', $argv, true);
try {
    if ($down) {
        $pdo->exec('DROP TABLE IF EXISTS renewal_reminder_log');
        fwrite(STDOUT, "Renewal reminder log table dropped if it existed. The additive email_log type is retained to preserve historical mail logs.\n");
        exit(0);
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS renewal_reminder_log (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        source_type VARCHAR(50) NOT NULL,
        source_id BIGINT UNSIGNED NULL,
        customer_id BIGINT UNSIGNED NOT NULL,
        domain VARCHAR(253) NOT NULL,
        expiry_date DATE NOT NULL,
        stage_days INT NOT NULL,
        sent_at DATETIME NOT NULL,
        recipients TEXT NULL,
        UNIQUE KEY uq_renewal_reminder_source_expiry_stage (source_type,source_id,expiry_date,stage_days),
        INDEX idx_renewal_reminder_customer (customer_id,expiry_date)
    ) ENGINE=InnoDB");

    $indexes = $pdo->query('SHOW INDEX FROM renewal_reminder_log')->fetchAll();
    $indexNames = array_values(array_unique(array_column($indexes, 'Key_name')));
    if (!in_array('uq_renewal_reminder_source_expiry_stage', $indexNames, true)) {
        $pdo->exec('ALTER TABLE renewal_reminder_log ADD UNIQUE KEY uq_renewal_reminder_source_expiry_stage (source_type,source_id,expiry_date,stage_days)');
    }
    if (!in_array('idx_renewal_reminder_customer', $indexNames, true)) {
        $pdo->exec('ALTER TABLE renewal_reminder_log ADD INDEX idx_renewal_reminder_customer (customer_id,expiry_date)');
    }

    $typeColumn = $pdo->query("SHOW COLUMNS FROM email_log LIKE 'type'")->fetch();
    if (!is_array($typeColumn) || !str_contains((string) $typeColumn['Type'], "'admin_renewal_reminder_digest'")) {
        $pdo->exec("ALTER TABLE email_log MODIFY type ENUM('invoice_issued','invoice_partial','invoice_overdue','payment_received','admin_overdue_alert','admin_domain_expiry_report','admin_overdue_invoice_report','admin_google_workspace_report','admin_renewal_reminder_digest','template_test') NOT NULL");
    }
    $ccColumn = $pdo->query("SHOW COLUMNS FROM email_log LIKE 'cc'")->fetch();
    if (is_array($ccColumn) && !preg_match('/\A(?:tinytext|text|mediumtext|longtext)\z/i', (string) $ccColumn['Type'])) {
        $pdo->exec('ALTER TABLE email_log MODIFY cc TEXT NULL');
    }
    fwrite(STDOUT, "Renewal reminder log migration is ready.\n");
} catch (Throwable $exception) {
    error_log('Renewal reminder migration failed: ' . $exception->getMessage());
    fwrite(STDERR, "Renewal reminder migration failed. Check database permissions and schema.\n");
    exit(1);
}
