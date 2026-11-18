<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/db.php';

$direction = strtolower(trim($argv[1] ?? 'up'));
if (!in_array($direction, ['up', 'down'], true)) {
    fwrite(STDERR, "Usage: php database/migrate_domain_phone_snapshots.php [up|down]\n");
    exit(2);
}

$pdo = db();
$domainColumns = array_column($pdo->query('SHOW COLUMNS FROM customer_domains')->fetchAll(), 'Field');
$invoiceColumns = array_column($pdo->query('SHOW COLUMNS FROM invoices')->fetchAll(), 'Field');

if ($direction === 'down') {
    if (in_array('phone_number_snapshot', $invoiceColumns, true)) {
        $pdo->exec('ALTER TABLE invoices DROP COLUMN phone_number_snapshot');
    }
    if (in_array('phone_number', $domainColumns, true)) {
        $pdo->exec('ALTER TABLE customer_domains DROP COLUMN phone_number');
    }
    fwrite(STDOUT, "Domain phone snapshot migration rolled back.\n");
    exit(0);
}

if (!in_array('phone_number', $domainColumns, true)) {
    $pdo->exec('ALTER TABLE customer_domains ADD COLUMN phone_number VARCHAR(20) NULL AFTER company_name');
}
if (!in_array('phone_number_snapshot', $invoiceColumns, true)) {
    $pdo->exec('ALTER TABLE invoices ADD COLUMN phone_number_snapshot VARCHAR(20) NULL AFTER billing_address_snapshot');
}

$pdo->exec("UPDATE customer_domains d
    JOIN customers c ON c.id=d.customer_id
    SET d.phone_number=LEFT(TRIM(c.phone),20)
    WHERE (d.phone_number IS NULL OR TRIM(d.phone_number)='')
      AND c.phone IS NOT NULL AND TRIM(c.phone)<>''");

fwrite(STDOUT, "Domain phone and invoice phone snapshot migration completed.\n");
