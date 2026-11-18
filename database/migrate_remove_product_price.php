<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/db.php';

$pdo = db();
$column = $pdo->query("SHOW FULL COLUMNS FROM products LIKE 'price'")->fetch();
if (!$column) {
    fwrite(STDOUT, "The products price column is already absent.\n");
    exit(0);
}

try {
    $pdo->exec('ALTER TABLE products DROP COLUMN price');
    fwrite(STDOUT, "Removed products price column.\n");
} catch (PDOException $exception) {
    error_log('Product price migration failed: ' . $exception->getMessage());
    fwrite(STDERR, "Could not remove products price. Check the database account's ALTER TABLE permission.\n");
    exit(1);
}
