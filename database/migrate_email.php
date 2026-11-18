<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/db.php';

$migration = file_get_contents(__DIR__ . '/migrate_email.sql');
if (!is_string($migration)) {
    fwrite(STDERR, "Could not read the email migration SQL.\n");
    exit(1);
}

try {
    $pdo = db();
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $migration) ?: [] as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec($statement);
        }
    }

    $legacyRows = $pdo->query("SELECT id,invoice_id,type,recipient,status,created_at
        FROM email_log WHERE status NOT IN ('sent','failed','bounced')
        ORDER BY created_at,id")->fetchAll(PDO::FETCH_ASSOC);
    if ($legacyRows !== []) {
        fwrite(STDERR, "Email schema migration paused: these historical email records need a decision before statuses can be changed:\n");
        foreach ($legacyRows as $row) {
            fwrite(STDERR, sprintf(
                "ID %s | invoice %s | %s | %s | status %s | %s\n",
                $row['id'],
                $row['invoice_id'] ?? '—',
                $row['type'],
                $row['recipient'] ?? '—',
                $row['status'] === '' ? '(empty)' : $row['status'],
                $row['created_at']
            ));
        }
        exit(2);
    }

    $pdo->exec("ALTER TABLE email_log MODIFY status ENUM('sent','failed','bounced') NOT NULL");
    $pdo->exec("ALTER TABLE email_log MODIFY type ENUM('invoice_issued','invoice_partial','invoice_overdue','payment_received','admin_overdue_alert','admin_domain_expiry_report','admin_overdue_invoice_report','admin_google_workspace_report','template_test') NOT NULL");
    $pdo->exec('ALTER TABLE email_log MODIFY created_at DATETIME(6) NOT NULL, MODIFY sent_at DATETIME(6) NULL, MODIFY html_body LONGTEXT NULL, MODIFY text_body LONGTEXT NULL');

    $indexes = $pdo->query("SELECT INDEX_NAME FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='email_log'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['idx_email_log_queue'] as $index) {
        if (in_array($index, $indexes, true)) {
            $pdo->exec('ALTER TABLE email_log DROP INDEX `' . $index . '`');
        }
    }

    $columns = array_column($pdo->query('SHOW COLUMNS FROM email_log')->fetchAll(), 'Field');
    foreach (['attempts', 'locked_at', 'payload', 'next_attempt_at'] as $column) {
        if (in_array($column, $columns, true)) {
            $pdo->exec('ALTER TABLE email_log DROP COLUMN `' . $column . '`');
        }
    }
    $pdo->exec('DROP TABLE IF EXISTS email_send_state');
    $pdo->exec('DROP TABLE IF EXISTS admin_overdue_alert_state');
    fwrite(STDOUT, "Direct email schema is ready. Email statuses are sent, failed, or bounced; no queued messages were changed or sent.\n");
} catch (Throwable $exception) {
    error_log('Email database migration failed: ' . $exception->getMessage());
    fwrite(STDERR, "Email database migration failed. Check the database configuration and permissions.\n");
    exit(1);
}
