<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

try {
    $pdo = db();
    $columns = array_column($pdo->query('SHOW COLUMNS FROM invoices')->fetchAll(), 'Field');
    if (!in_array('currency_code', $columns, true)) {
        $legacyCurrency = $pdo->query("SELECT setting_value FROM company_settings WHERE setting_key='currency'")->fetchColumn();
        $legacyCurrency = is_string($legacyCurrency) && preg_match('/^[A-Z]{3}$/', strtoupper($legacyCurrency))
            ? strtoupper($legacyCurrency)
            : 'KES';
        $pdo->exec("ALTER TABLE invoices ADD COLUMN currency_code CHAR(3) CHARACTER SET ascii NOT NULL DEFAULT 'KES' AFTER customer_id");
        $backfill = $pdo->prepare('UPDATE invoices SET currency_code=?');
        $backfill->execute([$legacyCurrency]);
    }

    $saveCurrencies = $pdo->prepare("INSERT IGNORE INTO company_settings (setting_key,setting_value) VALUES ('invoice_currencies',?)");
    $saveCurrencies->execute(['KES,USD,EUR']);
    fwrite(STDOUT, "Invoice currency storage and available-currency settings are ready.\n");
} catch (Throwable $exception) {
    error_log('Invoice currency migration failed: ' . $exception->getMessage());
    fwrite(STDERR, "Invoice currency migration failed. Check database configuration and migration permissions.\n");
    exit(1);
}
