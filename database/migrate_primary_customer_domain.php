<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/db.php';

function primary_domain_migration_columns(PDO $pdo): array
{
    return array_column($pdo->query('SHOW COLUMNS FROM customers')->fetchAll(), 'Field');
}

function primary_domain_migration_indexes(PDO $pdo): array
{
    return array_column($pdo->query('SHOW INDEX FROM customers')->fetchAll(), 'Key_name');
}

function migrate_primary_customer_domain(PDO $pdo, string $direction): void
{
    $columns = primary_domain_migration_columns($pdo);
    $indexes = primary_domain_migration_indexes($pdo);

    if ($direction === 'down') {
        if (in_array('idx_customers_primary_domain', $indexes, true)) {
            $pdo->exec('ALTER TABLE customers DROP INDEX idx_customers_primary_domain');
        }
        foreach (['primary_domain_expiry', 'primary_domain'] as $column) {
            if (in_array($column, $columns, true)) {
                $pdo->exec('ALTER TABLE customers DROP COLUMN ' . $column);
            }
        }
        return;
    }

    if (!in_array('primary_domain', $columns, true)) {
        $pdo->exec('ALTER TABLE customers ADD COLUMN primary_domain VARCHAR(253) NULL AFTER invoice_delivery');
    }
    if (!in_array('primary_domain_expiry', $columns, true)) {
        $pdo->exec('ALTER TABLE customers ADD COLUMN primary_domain_expiry DATE NULL AFTER primary_domain');
    }
    if (!in_array('idx_customers_primary_domain', $indexes, true)) {
        $pdo->exec('ALTER TABLE customers ADD INDEX idx_customers_primary_domain (primary_domain)');
    }

    $pdo->exec("UPDATE customers c
        JOIN (
            SELECT d.customer_id, d.domain_name, d.expiry_date
            FROM customer_domains d
            JOIN (
                SELECT customer_id, MIN(id) AS first_domain_id
                FROM customer_domains
                GROUP BY customer_id
            ) first_domain ON first_domain.first_domain_id=d.id
        ) first_domain ON first_domain.customer_id=c.id
        SET c.primary_domain=first_domain.domain_name,
            c.primary_domain_expiry=first_domain.expiry_date
        WHERE c.primary_domain IS NULL AND c.primary_domain_expiry IS NULL
          AND TRIM(first_domain.domain_name)<>''");
}

$direction = $argv[1] ?? 'up';
if (!in_array($direction, ['up', 'down'], true)) {
    fwrite(STDERR, "Usage: php database/migrate_primary_customer_domain.php [up|down]\n");
    exit(2);
}

try {
    migrate_primary_customer_domain(db(), $direction);
    fwrite(STDOUT, $direction === 'up'
        ? "Primary customer domain migration completed.\n"
        : "Primary customer domain migration rolled back.\n");
} catch (Throwable $exception) {
    error_log('Primary customer domain migration failed: ' . mb_substr(strip_tags($exception->getMessage()), 0, 500));
    fwrite(STDERR, "Primary customer domain migration failed. Check database configuration and permissions.\n");
    exit(1);
}
