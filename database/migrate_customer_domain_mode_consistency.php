<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/db.php';

const DOMAIN_MODE_BACKUP_DOMAINS = 'customer_domains_mode_backup';
const DOMAIN_MODE_BACKUP_CUSTOMERS = 'customer_mode_fields_backup';
const DOMAIN_MODE_BACKUP_INVOICES = 'invoice_domain_links_mode_backup';

function customer_domain_mode_report(PDO $pdo): array
{
    $query = $pdo->query("SELECT c.id,c.name,c.invoice_delivery,
            (SELECT COUNT(*) FROM customer_domains d WHERE d.customer_id=c.id) leftover_domain_rows,
            CASE WHEN (c.primary_domain IS NOT NULL AND TRIM(c.primary_domain)<>'')
                       OR c.primary_domain_expiry IS NOT NULL THEN 1 ELSE 0 END leftover_primary_domain
        FROM customers c
        WHERE (c.invoice_delivery='primary' AND EXISTS (SELECT 1 FROM customer_domains d WHERE d.customer_id=c.id))
           OR (c.invoice_delivery='domain' AND ((c.primary_domain IS NOT NULL AND TRIM(c.primary_domain)<>'')
                                                OR c.primary_domain_expiry IS NOT NULL))
           OR (c.invoice_delivery IS NULL OR c.invoice_delivery NOT IN ('primary','domain'))
        ORDER BY c.id");
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function customer_domain_mode_report_text(array $rows, int $invoiceSnapshotBackfillCount): string
{
    $lines = [
        'Customer domain mode cleanup dry-run report',
        'Generated: ' . date(DATE_ATOM),
        'Customer ID | Customer name | Current mode | Leftover domain rows | Leftover primary domain',
        str_repeat('-', 100),
    ];
    foreach ($rows as $row) {
        $mode = $row['invoice_delivery'] === null ? 'NULL' : (string) $row['invoice_delivery'];
        $lines[] = implode(' | ', [
            (string) $row['id'],
            str_replace(['|', "\r", "\n"], ['/', ' ', ' '], (string) $row['name']),
            $mode,
            (string) $row['leftover_domain_rows'],
            (int) $row['leftover_primary_domain'] === 1 ? 'yes' : 'no',
        ]);
    }
    $lines[] = 'Linked invoices needing snapshot backfill: ' . $invoiceSnapshotBackfillCount;
    if ($rows === []) {
        $lines[] = 'No inconsistent or unknown-mode customers found.';
    }
    return implode(PHP_EOL, $lines) . PHP_EOL;
}

function save_customer_domain_mode_report(string $text): string
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'breax_customer_domain_mode_cleanup_' . date('Ymd_His') . '.log';
    if (file_put_contents($path, $text, LOCK_EX) === false) {
        throw new RuntimeException('Could not write the customer domain cleanup report.');
    }
    return $path;
}

function ensure_invoice_domain_set_null(PDO $pdo): void
{
    $column = $pdo->query("SHOW COLUMNS FROM invoices LIKE 'domain_id'")->fetch(PDO::FETCH_ASSOC);
    if (!$column) {
        throw new RuntimeException('The invoices.domain_id column is missing.');
    }
    if (strtoupper((string) $column['Null']) !== 'YES') {
        $pdo->exec('ALTER TABLE invoices MODIFY domain_id BIGINT UNSIGNED NULL');
    }

    $foreignKeysQuery = $pdo->prepare("SELECT CONSTRAINT_NAME,DELETE_RULE
        FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='invoices'
          AND CONSTRAINT_NAME IN (
              SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
              WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='domain_id'
                AND REFERENCED_TABLE_NAME='customer_domains'
          )");
    $foreignKeysQuery->execute();
    $foreignKeys = $foreignKeysQuery->fetchAll(PDO::FETCH_ASSOC);
    if (count($foreignKeys) === 1 && strtoupper((string) $foreignKeys[0]['DELETE_RULE']) === 'SET NULL') {
        return;
    }

    foreach ($foreignKeys as $foreignKey) {
        $constraintName = str_replace('`', '``', (string) $foreignKey['CONSTRAINT_NAME']);
        $pdo->exec('ALTER TABLE invoices DROP FOREIGN KEY `' . $constraintName . '`');
    }
    $pdo->exec('ALTER TABLE invoices ADD CONSTRAINT fk_invoice_domain FOREIGN KEY (domain_id) REFERENCES customer_domains(id) ON DELETE SET NULL');
}

function ensure_customer_domain_mode_backups(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . DOMAIN_MODE_BACKUP_DOMAINS . ' LIKE customer_domains');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . DOMAIN_MODE_BACKUP_CUSTOMERS . ' (
        customer_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        primary_domain VARCHAR(253) NULL,
        primary_domain_expiry DATE NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . DOMAIN_MODE_BACKUP_INVOICES . ' (
        invoice_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        domain_id BIGINT UNSIGNED NOT NULL,
        domain_name VARCHAR(253) NULL,
        recipient_email VARCHAR(190) NULL,
        company_name_snapshot VARCHAR(190) NULL,
        billing_address_snapshot TEXT NULL,
        phone_number_snapshot VARCHAR(20) NULL
    ) ENGINE=InnoDB');
}

function backup_customer_domain_mode_data(PDO $pdo): void
{
    $pdo->exec('INSERT IGNORE INTO ' . DOMAIN_MODE_BACKUP_DOMAINS . '
        SELECT d.* FROM customer_domains d JOIN customers c ON c.id=d.customer_id
        WHERE c.invoice_delivery=\'primary\'');
    $pdo->exec('INSERT IGNORE INTO ' . DOMAIN_MODE_BACKUP_CUSTOMERS . ' (customer_id,primary_domain,primary_domain_expiry)
        SELECT id,primary_domain,primary_domain_expiry FROM customers
        WHERE invoice_delivery=\'domain\' AND ((primary_domain IS NOT NULL AND TRIM(primary_domain)<>\'\') OR primary_domain_expiry IS NOT NULL)');
    $pdo->exec('INSERT IGNORE INTO ' . DOMAIN_MODE_BACKUP_INVOICES . ' (invoice_id,domain_id,domain_name,recipient_email,company_name_snapshot,billing_address_snapshot,phone_number_snapshot)
        SELECT i.id,i.domain_id,i.domain_name,i.recipient_email,i.company_name_snapshot,i.billing_address_snapshot,i.phone_number_snapshot
        FROM invoices i JOIN customer_domains d ON d.id=i.domain_id
        JOIN customers c ON c.id=d.customer_id WHERE c.invoice_delivery=\'primary\'');
}

function apply_customer_domain_mode_cleanup(PDO $pdo): array
{
    ensure_customer_domain_mode_backups($pdo);
    ensure_invoice_domain_set_null($pdo);

    $pdo->beginTransaction();
    try {
        $pdo->query('SELECT lock_id FROM customer_contact_write_lock WHERE lock_id=1 FOR UPDATE')->fetchColumn();
        $pdo->query('SELECT id FROM customers FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
        backup_customer_domain_mode_data($pdo);

        $pdo->exec("UPDATE invoices i JOIN customer_domains d ON d.id=i.domain_id
            JOIN customers c ON c.id=d.customer_id
            SET i.domain_name=COALESCE(NULLIF(TRIM(i.domain_name),''),d.domain_name),
                i.recipient_email=COALESCE(NULLIF(TRIM(i.recipient_email),''),d.invoice_email),
                i.company_name_snapshot=COALESCE(NULLIF(TRIM(i.company_name_snapshot),''),d.company_name),
                i.billing_address_snapshot=COALESCE(NULLIF(TRIM(i.billing_address_snapshot),''),d.billing_address),
                i.phone_number_snapshot=COALESCE(NULLIF(TRIM(i.phone_number_snapshot),''),d.phone_number)
            WHERE c.invoice_delivery='primary'");
        $snapshotBackfilled = $pdo->query('SELECT ROW_COUNT()')->fetchColumn();

        $pdo->exec("UPDATE customers c
            SET c.primary_domain=(SELECT d.domain_name FROM customer_domains d WHERE d.customer_id=c.id ORDER BY d.id LIMIT 1),
                c.primary_domain_expiry=(SELECT d.expiry_date FROM customer_domains d WHERE d.customer_id=c.id ORDER BY d.id LIMIT 1)
            WHERE c.invoice_delivery='primary' AND (c.primary_domain IS NULL OR TRIM(c.primary_domain)='')
              AND EXISTS (SELECT 1 FROM customer_domains d WHERE d.customer_id=c.id)");
        $backfilled = $pdo->query('SELECT ROW_COUNT()')->fetchColumn();

        $deleted = $pdo->exec("DELETE d FROM customer_domains d JOIN customers c ON c.id=d.customer_id
            WHERE c.invoice_delivery='primary'");
        $cleared = $pdo->exec("UPDATE customers SET primary_domain=NULL,primary_domain_expiry=NULL
            WHERE invoice_delivery='domain' AND ((primary_domain IS NOT NULL AND TRIM(primary_domain)<>'') OR primary_domain_expiry IS NOT NULL)");
        $pdo->commit();

        return ['backfilled' => (int) $backfilled, 'snapshots' => (int) $snapshotBackfilled, 'deleted' => $deleted, 'cleared' => $cleared];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function rollback_customer_domain_mode_cleanup(PDO $pdo): array
{
    $domainColumns = array_column($pdo->query('SHOW COLUMNS FROM customer_domains')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $quotedColumns = implode(',', array_map(static fn(string $column): string => '`' . str_replace('`', '``', $column) . '`', $domainColumns));
    $placeholders = implode(',', array_fill(0, count($domainColumns), '?'));

    $pdo->beginTransaction();
    try {
        $rows = $pdo->query('SELECT ' . $quotedColumns . ' FROM ' . DOMAIN_MODE_BACKUP_DOMAINS)->fetchAll(PDO::FETCH_ASSOC);
        $exists = $pdo->prepare('SELECT id FROM customer_domains WHERE id=?');
        $insert = $pdo->prepare('INSERT INTO customer_domains (' . $quotedColumns . ') VALUES (' . $placeholders . ')');
        $restoredDomains = 0;
        foreach ($rows as $row) {
            $exists->execute([$row['id']]);
            if ($exists->fetchColumn()) {
                continue;
            }
            $insert->execute(array_values($row));
            $restoredDomains++;
        }

        $restoredCustomers = $pdo->exec('UPDATE customers c JOIN ' . DOMAIN_MODE_BACKUP_CUSTOMERS . ' b ON b.customer_id=c.id
            SET c.primary_domain=b.primary_domain,c.primary_domain_expiry=b.primary_domain_expiry');
        $restoredInvoiceLinks = $pdo->exec('UPDATE invoices i JOIN ' . DOMAIN_MODE_BACKUP_INVOICES . ' b ON b.invoice_id=i.id
            SET i.domain_id=IF(i.domain_id IS NULL,b.domain_id,i.domain_id),
                i.domain_name=b.domain_name,i.recipient_email=b.recipient_email,
                i.company_name_snapshot=b.company_name_snapshot,i.billing_address_snapshot=b.billing_address_snapshot,
                i.phone_number_snapshot=b.phone_number_snapshot');
        $pdo->commit();
        return ['domains' => $restoredDomains, 'customers' => $restoredCustomers, 'invoice_links' => $restoredInvoiceLinks];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

$mode = $argv[1] ?? 'dry-run';
if (!in_array($mode, ['dry-run', 'up', 'down'], true)) {
    fwrite(STDERR, "Usage: php database/migrate_customer_domain_mode_consistency.php [dry-run|up|down]\n");
    exit(2);
}

try {
    $pdo = db();
    if ($mode === 'down') {
        $restored = rollback_customer_domain_mode_cleanup($pdo);
        fwrite(STDOUT, sprintf(
            "Restored %d domain rows, %d primary-domain records, and %d invoice domain links. The ON DELETE SET NULL foreign key remains enabled.\n",
            $restored['domains'],
            $restored['customers'],
            $restored['invoice_links']
        ));
        exit(0);
    }

    $report = customer_domain_mode_report($pdo);
    $snapshotQuery = $pdo->query("SELECT COUNT(*) FROM invoices i JOIN customer_domains d ON d.id=i.domain_id
        JOIN customers c ON c.id=d.customer_id WHERE c.invoice_delivery='primary' AND (
            i.domain_name IS NULL OR TRIM(i.domain_name)='' OR i.recipient_email IS NULL OR TRIM(i.recipient_email)=''
            OR i.company_name_snapshot IS NULL OR TRIM(i.company_name_snapshot)=''
            OR i.billing_address_snapshot IS NULL OR TRIM(i.billing_address_snapshot)=''
            OR i.phone_number_snapshot IS NULL OR TRIM(i.phone_number_snapshot)='')");
    $invoiceSnapshotBackfillCount = (int) $snapshotQuery->fetchColumn();
    $reportText = customer_domain_mode_report_text($report, $invoiceSnapshotBackfillCount);
    $reportPath = save_customer_domain_mode_report($reportText);
    fwrite(STDOUT, $reportText);
    fwrite(STDOUT, 'Report log: ' . $reportPath . PHP_EOL);
    if ($mode === 'dry-run') {
        fwrite(STDOUT, 'Dry run complete; no database rows were changed.' . PHP_EOL);
        exit(0);
    }

    $result = apply_customer_domain_mode_cleanup($pdo);
    fwrite(STDOUT, sprintf(
        "Cleanup complete: backfilled %d primary domains, preserved snapshots on %d linked invoices, deleted %d domain rows, and cleared primary data for %d Domain email customers. Unknown modes were left unchanged.\n",
        $result['backfilled'],
        $result['snapshots'],
        $result['deleted'],
        $result['cleared']
    ));
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Customer domain mode migration failed: ' . mb_substr(strip_tags($exception->getMessage()), 0, 500));
    fwrite(STDERR, 'Customer domain mode migration failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
