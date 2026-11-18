<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/customer_domains.php';
require_auth();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$rawDomain = is_string($_GET['domain'] ?? null) ? trim($_GET['domain']) : '';
$rawEmail = is_string($_GET['email'] ?? null) ? trim($_GET['email']) : '';
$kind = is_string($_GET['kind'] ?? null) ? $_GET['kind'] : 'domain';
$rawDomainId = $_GET['domain_id'] ?? '';
$domainId = $rawDomainId === '' ? null : filter_var($rawDomainId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$rawCustomerId = $_GET['customer_id'] ?? '';
$customerId = $rawCustomerId === '' ? 0 : filter_var($rawCustomerId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
if ($domainId === false || $customerId === false || !in_array($kind, ['domain', 'google_workspace', 'primary_domain'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'The domain row is invalid.']);
    exit;
}

$result = ['domain_error' => '', 'email_error' => ''];
$pdo = db();
if ($rawDomain !== '') {
    try {
        $domain = $kind === 'primary_domain'
            ? normalize_primary_customer_domain($rawDomain)
            : normalize_customer_domain($rawDomain);
        if ($kind === 'google_workspace') {
            $query = $pdo->prepare("SELECT c.name FROM customer_google_workspaces d JOIN customers c ON c.id=d.customer_id WHERE d.domain_name=? AND d.id<>? AND d.status='active' LIMIT 1");
            $query->execute([$domain, $domainId ?? 0]);
        } elseif ($kind === 'primary_domain') {
            $query = $pdo->prepare("SELECT name FROM customers WHERE primary_domain=? AND id<>?
                UNION ALL
                SELECT c.name FROM customer_domains d JOIN customers c ON c.id=d.customer_id WHERE d.domain_name=? AND d.customer_id<>?
                LIMIT 1");
            $query->execute([$domain, $customerId, $domain, $customerId]);
        } else {
            $query = $pdo->prepare("SELECT c.name FROM customer_domains d JOIN customers c ON c.id=d.customer_id WHERE d.domain_name=? AND d.id<>? AND d.customer_id<>?
                UNION ALL
                SELECT name FROM customers WHERE primary_domain=? AND id<>?
                LIMIT 1");
            $query->execute([$domain, $domainId ?? 0, $customerId, $domain, $customerId]);
        }
        $owner = $query->fetchColumn();
        if (is_string($owner)) {
            $result['domain_error'] = 'This domain is already assigned to ' . $owner . '. Each domain can belong to only one customer.';
        }
    } catch (InvalidArgumentException $exception) {
        $result['domain_error'] = $exception->getMessage();
    }
}

if ($kind === 'domain' && $rawEmail !== '' && filter_var($rawEmail, FILTER_VALIDATE_EMAIL)) {
    $query = $pdo->prepare('SELECT c.name,d.domain_name FROM customer_domains d JOIN customers c ON c.id=d.customer_id WHERE LOWER(TRIM(d.invoice_email))=LOWER(TRIM(?)) AND d.id<>? LIMIT 1');
    $query->execute([$rawEmail, $domainId ?? 0]);
    $existing = $query->fetch();
    if ($existing) {
        $result['email_error'] = 'This invoice email is already assigned to ' . $existing['domain_name'] . ' for ' . $existing['name'] . '. Use a unique email address for this domain.';
    }
}

echo json_encode($result, JSON_THROW_ON_ERROR);
