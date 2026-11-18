<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/db.php';

try {
    $pdo = db();
    $duplicates = $pdo->query(
        "SELECT invoice_id, COUNT(*) duplicate_count
         FROM payments
         WHERE reference IS NOT NULL
         GROUP BY invoice_id, reference
         HAVING COUNT(*) > 1
         LIMIT 10"
    )->fetchAll();
    if ($duplicates) {
        $invoiceIds = array_unique(array_map(static fn(array $row): string => (string) $row['invoice_id'], $duplicates));
        fwrite(
            STDERR,
            'Cannot add transaction-reference uniqueness: duplicate references exist on invoice(s) '
            . implode(', ', $invoiceIds)
            . ". Review and resolve those payment records, then rerun this migration.\n"
        );
        exit(1);
    }

    $indexNames = array_column($pdo->query('SHOW INDEX FROM payments')->fetchAll(), 'Key_name');
    if (!in_array('uq_payment_invoice_reference', $indexNames, true)) {
        $pdo->exec('ALTER TABLE payments ADD UNIQUE KEY uq_payment_invoice_reference (invoice_id, reference)');
    }
    fwrite(STDOUT, "Payment transaction references are now unique per invoice.\n");
} catch (Throwable $exception) {
    error_log('Payment-reference migration failed: ' . $exception->getMessage());
    fwrite(STDERR, "Payment-reference migration failed. Check the database configuration and migration permissions.\n");
    exit(1);
}
