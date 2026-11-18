<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/db.php';

try {
    db()->exec("CREATE TABLE IF NOT EXISTS customer_google_workspaces (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id BIGINT UNSIGNED NOT NULL,
        domain_name VARCHAR(253) NOT NULL,
        expiry_date DATE NOT NULL,
        status ENUM('active','archived') NOT NULL DEFAULT 'active',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_customer_google_workspace_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
        UNIQUE KEY uq_customer_google_workspace_domain (domain_name),
        INDEX idx_customer_google_workspace_expiry (status,expiry_date,domain_name),
        INDEX idx_customer_google_workspace_customer (customer_id,status,domain_name)
    ) ENGINE=InnoDB");
    fwrite(STDOUT, "Google Workspace customer tracking is ready.\n");
} catch (Throwable $exception) {
    error_log('Google Workspace migration failed: ' . mb_substr(strip_tags($exception->getMessage()), 0, 500));
    fwrite(STDERR, "Google Workspace migration failed. Check database configuration and permissions.\n");
    exit(1);
}
