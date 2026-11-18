<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/db.php';

$pdo = db();
$column = $pdo->query("SHOW FULL COLUMNS FROM products LIKE 'renewal_price'")->fetch();
if (!$column) {
    echo "The products renewal_price column is already absent.\n";
    exit(0);
}

try {
    $pdo->exec('ALTER TABLE products DROP COLUMN renewal_price');
    echo "Removed products renewal_price column.\n";
} catch (PDOException) {
    fwrite(STDERR, "Could not remove products renewal_price. Check the database account's ALTER TABLE permission.\n");
    exit(1);
}
