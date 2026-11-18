<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/db.php';

$pdo = db();
$column = $pdo->query("SHOW FULL COLUMNS FROM products LIKE 'description'")->fetch();
if (!$column) {
    echo "The products description column is already absent.\n";
    exit(0);
}

try {
    $pdo->exec('ALTER TABLE products DROP COLUMN description');
    echo "Removed products description column. Invoice item descriptions are unchanged.\n";
} catch (PDOException $exception) {
    fwrite(STDERR, "Could not remove products description. Check the database account's ALTER TABLE permission: {$exception->getMessage()}\n");
    exit(1);
}
