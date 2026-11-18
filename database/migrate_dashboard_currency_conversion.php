<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/db.php';

try {
    $pdo = db();
    $columns = array_column($pdo->query('SHOW COLUMNS FROM invoices')->fetchAll(), 'Field');
    if (!in_array('kes_exchange_rate', $columns, true)) {
        $pdo->exec('ALTER TABLE invoices ADD COLUMN kes_exchange_rate DECIMAL(18,8) NULL AFTER currency_code');
    }
    if (!in_array('fx_rate_date', $columns, true)) {
        $pdo->exec('ALTER TABLE invoices ADD COLUMN fx_rate_date DATE NULL AFTER kes_exchange_rate');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS fx_daily_rates (
        rate_date DATE NOT NULL,
        currency_code CHAR(3) CHARACTER SET ascii NOT NULL,
        kes_per_unit DECIMAL(18,8) NOT NULL,
        PRIMARY KEY (rate_date,currency_code)
    ) ENGINE=InnoDB");
    $rateColumns = array_column($pdo->query('SHOW COLUMNS FROM fx_daily_rates')->fetchAll(), 'Field');
    if (!in_array('updated_at', $rateColumns, true)) {
        $pdo->exec('ALTER TABLE fx_daily_rates ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
        $pdo->exec('UPDATE fx_daily_rates SET updated_at=TIMESTAMP(rate_date)');
    }
    fwrite(STDOUT, "Dashboard currency conversion schema is ready.\n");
} catch (Throwable $exception) {
    error_log('Dashboard currency conversion migration failed: ' . $exception->getMessage());
    fwrite(STDERR, "Dashboard currency conversion migration failed. Check database configuration and migration permissions.\n");
    exit(1);
}
