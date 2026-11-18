<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/db.php';

$pdo = db();
$column = $pdo->query("SHOW FULL COLUMNS FROM customers LIKE 'tax_id'")->fetch();
if (!$column) {
    echo "The customers tax_id column is already absent.\n";
    exit(0);
}

try {
    $pdo->exec('ALTER TABLE customers DROP COLUMN tax_id');
    echo "Removed customers tax_id column.\n";
} catch (PDOException) {
    fwrite(STDERR, "Could not remove customers tax_id. Check the database account's ALTER TABLE permission.\n");
    exit(1);
}
