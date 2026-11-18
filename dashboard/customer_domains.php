<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_auth();

$customerId = filter_var($_GET['customer_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($customerId === false) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Choose a valid customer.']);
    exit;
}

$customerQuery = db()->prepare("SELECT id,email,invoice_delivery FROM customers WHERE id=? AND status='active'");
$customerQuery->execute([$customerId]);
$customer = $customerQuery->fetch();
if (!$customer) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Customer not found.']);
    exit;
}

$domains = [];
if ($customer['invoice_delivery'] === 'domain') {
    $domainQuery = db()->prepare("SELECT id,domain_name,invoice_email,company_name,billing_address,phone_number FROM customer_domains
        WHERE customer_id=? AND status='active' ORDER BY domain_name");
    $domainQuery->execute([$customerId]);
    $domains = $domainQuery->fetchAll();
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'invoice_delivery' => $customer['invoice_delivery'] === 'domain' ? 'domain' : 'primary',
    'primary_email' => $customer['email'],
    'domains' => $domains,
], JSON_THROW_ON_ERROR);
