<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$pdo = db();
$columnNames = array_column($pdo->query('SHOW COLUMNS FROM invoices')->fetchAll(), 'Field');
if (!in_array('public_token', $columnNames, true)) {
    $pdo->exec('ALTER TABLE invoices ADD COLUMN public_token CHAR(43) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER payment_terms');
}
if (!in_array('public_token_created_at', $columnNames, true)) {
    $pdo->exec('ALTER TABLE invoices ADD COLUMN public_token_created_at DATETIME NULL AFTER public_token');
}
$tokenColumn = $pdo->query("SHOW FULL COLUMNS FROM invoices LIKE 'public_token'")->fetch();
if ($tokenColumn && $tokenColumn['Collation'] !== 'ascii_bin') {
    $pdo->exec('ALTER TABLE invoices MODIFY public_token CHAR(43) CHARACTER SET ascii COLLATE ascii_bin NULL');
}

$indexNames = array_column($pdo->query('SHOW INDEX FROM invoices')->fetchAll(), 'Key_name');
if (!in_array('uq_invoice_public_token', $indexNames, true)) {
    $pdo->exec('ALTER TABLE invoices ADD UNIQUE KEY uq_invoice_public_token (public_token)');
}

$pdo->exec("CREATE TABLE IF NOT EXISTS invoice_public_link_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id BIGINT UNSIGNED NOT NULL,
    event_type ENUM('created','revoked','regenerated') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_public_link_event_invoice (invoice_id, created_at)
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE IF NOT EXISTS public_invoice_rate_limits (
    ip_hash CHAR(64) PRIMARY KEY,
    window_started DATETIME NOT NULL,
    request_count SMALLINT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB");

$pdo->beginTransaction();
try {
    $invoices = $pdo->query("SELECT id FROM invoices WHERE status <> 'draft' AND public_token IS NULL FOR UPDATE")->fetchAll();
    $update = $pdo->prepare('UPDATE invoices SET public_token=?,public_token_created_at=UTC_TIMESTAMP() WHERE id=? AND public_token IS NULL');
    $event = $pdo->prepare("INSERT INTO invoice_public_link_events (invoice_id,event_type) VALUES (?, 'created')");
    foreach ($invoices as $invoice) {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $update->execute([$token, $invoice['id']]);
        if ($update->rowCount() === 1) {
            $event->execute([$invoice['id']]);
        }
    }
    $pdo->commit();
    printf("Public invoice links backfilled for %d issued invoice(s).\n", count($invoices));
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Public invoice link migration failed. Check the database configuration and migration permissions." . PHP_EOL);
    exit(1);
}
