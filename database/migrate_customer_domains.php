<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/db.php';

$pdo = db();
$customerColumns = array_column($pdo->query('SHOW COLUMNS FROM customers')->fetchAll(), 'Field');
if (!in_array('invoice_delivery', $customerColumns, true)) {
    $pdo->exec("ALTER TABLE customers ADD COLUMN invoice_delivery ENUM('primary','domain') NOT NULL DEFAULT 'primary' AFTER email");
}

$pdo->exec("CREATE TABLE IF NOT EXISTS customer_domains (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    company_name VARCHAR(190) NULL,
    domain_name VARCHAR(253) NOT NULL,
    invoice_email VARCHAR(190) NULL,
    billing_address TEXT NULL,
    status ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_customer_domain_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    UNIQUE KEY uq_customer_domain_name (customer_id,domain_name),
    INDEX idx_customer_domain_status (customer_id,status,domain_name)
) ENGINE=InnoDB");
$duplicateDomains = $pdo->query("SELECT domain_name,GROUP_CONCAT(DISTINCT customer_id ORDER BY customer_id) customer_ids
    FROM customer_domains GROUP BY domain_name HAVING COUNT(DISTINCT customer_id)>1")->fetchAll();
if ($duplicateDomains !== []) {
    $conflicts = array_map(
        static fn(array $row): string => $row['domain_name'] . ' (customer IDs ' . $row['customer_ids'] . ')',
        $duplicateDomains
    );
    fwrite(STDERR, "Cannot enforce globally unique domains until duplicates are resolved: " . implode('; ', $conflicts) . PHP_EOL);
    exit(1);
}
$domainIndexes = $pdo->query('SHOW INDEX FROM customer_domains')->fetchAll();
$hasGlobalDomainIndex = false;
$hasCustomerDomainIndex = false;
foreach ($domainIndexes as $index) {
    if ($index['Key_name'] === 'uq_customer_domain_global') {
        $hasGlobalDomainIndex = true;
    }
    if ($index['Key_name'] === 'uq_customer_domain_name') {
        $hasCustomerDomainIndex = true;
    }
}
if (!$hasGlobalDomainIndex) {
    $pdo->exec('ALTER TABLE customer_domains ADD UNIQUE KEY uq_customer_domain_global (domain_name)');
}
if ($hasCustomerDomainIndex) {
    $pdo->exec('ALTER TABLE customer_domains DROP INDEX uq_customer_domain_name');
}
$duplicateEmails = $pdo->query("SELECT LOWER(TRIM(invoice_email)) invoice_email,
        GROUP_CONCAT(CONCAT(domain_name,' (customer ',customer_id,')') ORDER BY domain_name) domains
    FROM customer_domains WHERE invoice_email IS NOT NULL AND TRIM(invoice_email)<>''
    GROUP BY LOWER(TRIM(invoice_email)) HAVING COUNT(*)>1")->fetchAll();
if ($duplicateEmails !== []) {
    $conflicts = array_map(
        static fn(array $row): string => $row['invoice_email'] . ' on ' . $row['domains'],
        $duplicateEmails
    );
    fwrite(STDERR, "Cannot enforce unique domain invoice emails until duplicates are resolved: " . implode('; ', $conflicts) . PHP_EOL);
    exit(1);
}
$hasUniqueDomainEmail = false;
foreach ($pdo->query('SHOW INDEX FROM customer_domains')->fetchAll() as $index) {
    if ($index['Key_name'] === 'uq_customer_domain_invoice_email') {
        $hasUniqueDomainEmail = true;
        break;
    }
}
if (!$hasUniqueDomainEmail) {
    $pdo->exec('ALTER TABLE customer_domains ADD UNIQUE KEY uq_customer_domain_invoice_email (invoice_email)');
}
$pdo->exec("CREATE TABLE IF NOT EXISTS customer_contact_write_lock (
    lock_id TINYINT UNSIGNED PRIMARY KEY
) ENGINE=InnoDB");
$pdo->exec('INSERT IGNORE INTO customer_contact_write_lock (lock_id) VALUES (1)');
$domainColumns = array_column($pdo->query('SHOW COLUMNS FROM customer_domains')->fetchAll(), 'Field');
if (!in_array('company_name', $domainColumns, true)) {
    $pdo->exec('ALTER TABLE customer_domains ADD COLUMN company_name VARCHAR(190) NULL AFTER customer_id');
}
if (!in_array('billing_address', $domainColumns, true)) {
    $pdo->exec('ALTER TABLE customer_domains ADD COLUMN billing_address TEXT NULL AFTER invoice_email');
}
if (!in_array('expiry_date', $domainColumns, true)) {
    $pdo->exec('ALTER TABLE customer_domains ADD COLUMN expiry_date DATE NULL AFTER domain_name');
}
$pdo->exec("UPDATE customer_domains d JOIN customers c ON c.id=d.customer_id
    SET d.company_name=c.name WHERE d.company_name IS NULL OR TRIM(d.company_name)=''");
$pdo->exec("UPDATE customer_domains d JOIN customers c ON c.id=d.customer_id
    SET d.billing_address=c.billing_address
    WHERE (d.billing_address IS NULL OR TRIM(d.billing_address)='') AND c.billing_address IS NOT NULL AND TRIM(c.billing_address)<>''");

$invoiceColumns = array_column($pdo->query('SHOW COLUMNS FROM invoices')->fetchAll(), 'Field');
if (!in_array('domain_id', $invoiceColumns, true)) {
    $pdo->exec('ALTER TABLE invoices ADD COLUMN domain_id BIGINT UNSIGNED NULL');
}
if (!in_array('domain_name', $invoiceColumns, true)) {
    $pdo->exec('ALTER TABLE invoices ADD COLUMN domain_name VARCHAR(253) NULL');
}
if (!in_array('recipient_email', $invoiceColumns, true)) {
    $pdo->exec('ALTER TABLE invoices ADD COLUMN recipient_email VARCHAR(190) NULL');
}
if (!in_array('company_name_snapshot', $invoiceColumns, true)) {
    $pdo->exec('ALTER TABLE invoices ADD COLUMN company_name_snapshot VARCHAR(190) NULL AFTER recipient_email');
}
if (!in_array('billing_address_snapshot', $invoiceColumns, true)) {
    $pdo->exec('ALTER TABLE invoices ADD COLUMN billing_address_snapshot TEXT NULL AFTER company_name_snapshot');
}
$invoiceIndexes = array_column($pdo->query('SHOW INDEX FROM invoices')->fetchAll(), 'Key_name');
if (!in_array('idx_invoice_domain', $invoiceIndexes, true)) {
    $pdo->exec('ALTER TABLE invoices ADD INDEX idx_invoice_domain (domain_id)');
}
$foreignKeys = $pdo->prepare("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='domain_id'
      AND REFERENCED_TABLE_NAME='customer_domains'");
$foreignKeys->execute();
if (!$foreignKeys->fetchColumn()) {
    $pdo->exec('ALTER TABLE invoices ADD CONSTRAINT fk_invoice_domain FOREIGN KEY (domain_id) REFERENCES customer_domains(id)');
}

fwrite(STDOUT, "Customer domain and invoice recipient migration completed.\n");
