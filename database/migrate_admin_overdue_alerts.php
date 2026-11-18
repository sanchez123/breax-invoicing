<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/db.php';

$pdo = db();
try {
    $userColumns = array_column($pdo->query('SHOW COLUMNS FROM users')->fetchAll(), 'Field');
    if (!in_array('receive_overdue_alerts', $userColumns, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN receive_overdue_alerts TINYINT(1) NOT NULL DEFAULT 0 AFTER can_send_invoice_emails');
    }

    $pdo->exec("ALTER TABLE email_log MODIFY type ENUM('invoice_issued','invoice_partial','invoice_overdue','payment_received','admin_overdue_alert','admin_domain_expiry_report','admin_overdue_invoice_report','admin_google_workspace_report','template_test') NOT NULL");
    $pdo->exec('DROP TABLE IF EXISTS admin_overdue_alert_state');
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_overdue_alert_baseline (
        invoice_id BIGINT UNSIGNED PRIMARY KEY,
        due_date DATE NOT NULL,
        suppressed_at DATETIME NOT NULL,
        CONSTRAINT fk_admin_overdue_baseline_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_overdue_alert_job_config (
        config_key VARCHAR(40) PRIMARY KEY,
        config_date DATE NOT NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_overdue_alert_run_state (
        state_key VARCHAR(40) PRIMARY KEY,
        last_run_at DATETIME NOT NULL
    ) ENGINE=InnoDB");
    $pdo->exec("INSERT IGNORE INTO admin_overdue_alert_run_state (state_key,last_run_at)
        VALUES ('daily','1000-01-01 00:00:00')");

    $configExists = $pdo->prepare('SELECT config_date FROM admin_overdue_alert_job_config WHERE config_key=?');
    $configExists->execute(['first_deployment_date']);
    $deploymentDate = $configExists->fetchColumn();
    if ($deploymentDate === false) {
        $deploymentDate = (new DateTimeImmutable('today', new DateTimeZone('Africa/Nairobi')))->format('Y-m-d');
        $today = $deploymentDate;
        $pdo->beginTransaction();
        $lock = $pdo->prepare("SELECT config_date FROM admin_overdue_alert_job_config WHERE config_key='first_deployment_date' FOR UPDATE");
        $lock->execute();
        $existing = $lock->fetchColumn();
        if ($existing === false) {
            $overdue = $pdo->prepare("SELECT i.id,i.due_date
                FROM invoices i
                LEFT JOIN (SELECT invoice_id,SUM(amount) paid FROM payments GROUP BY invoice_id) p ON p.invoice_id=i.id
                WHERE i.status NOT IN ('paid','draft','cancelled','void')
                  AND i.due_date<?
                  AND GREATEST(i.total-COALESCE(p.paid,0),0)>0
                FOR UPDATE");
            $overdue->execute([$today]);
            $rows = $overdue->fetchAll();
            $insert = $pdo->prepare('INSERT IGNORE INTO admin_overdue_alert_baseline (invoice_id,due_date,suppressed_at) VALUES (?,?,UTC_TIMESTAMP())');
            foreach ($rows as $row) {
                $insert->execute([(int) $row['id'], $row['due_date']]);
            }
            $saveConfig = $pdo->prepare('INSERT INTO admin_overdue_alert_job_config (config_key,config_date) VALUES (?,?)');
            $saveConfig->execute(['first_deployment_date', $today]);
        } else {
            $deploymentDate = (string) $existing;
        }
        $pdo->commit();
    }
    $baselineCount = (int) $pdo->query('SELECT COUNT(*) FROM admin_overdue_alert_baseline')->fetchColumn();

    fwrite(STDOUT, "Admin overdue alert schema is ready.\n");
    fwrite(STDOUT, "First deployment date: {$deploymentDate}\n");
    fwrite(STDOUT, "Existing overdue invoices suppressed during first deployment: {$baselineCount}\n");
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Admin overdue alert migration failed: ' . $exception->getMessage());
    fwrite(STDERR, "Admin overdue alert migration failed. Check the database configuration and permissions.\n");
    exit(1);
}
