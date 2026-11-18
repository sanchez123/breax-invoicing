<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/customer_domains.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$pdo = db();
$remoteAddress = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '';
$ip = filter_var($remoteAddress, FILTER_VALIDATE_IP) !== false ? $remoteAddress : 'unknown';
$ipHash = hash('sha256', $ip);
$rateLimit = $pdo->prepare(
    'INSERT INTO public_invoice_rate_limits (ip_hash,window_started,request_count) VALUES (?,UTC_TIMESTAMP(),1)
     ON DUPLICATE KEY UPDATE
       request_count=IF(window_started < UTC_TIMESTAMP() - INTERVAL 1 MINUTE,1,LEAST(request_count+1,65535)),
       window_started=IF(window_started < UTC_TIMESTAMP() - INTERVAL 1 MINUTE,UTC_TIMESTAMP(),window_started)'
);
$rateLimit->execute([$ipHash]);
$countQuery = $pdo->prepare('SELECT request_count FROM public_invoice_rate_limits WHERE ip_hash=?');
$countQuery->execute([$ipHash]);
if ((int) $countQuery->fetchColumn() > 60) {
    http_response_code(429);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Too many requests.');
}
if (random_int(1, 100) === 1) {
    $pdo->exec('DELETE FROM public_invoice_rate_limits WHERE window_started < UTC_TIMESTAMP() - INTERVAL 2 DAY');
}

$token = $_GET['token'] ?? null;
$decodedToken = is_string($token) && preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token)
    ? base64_decode(strtr($token, '-_', '+/') . '=', true)
    : false;
if ($decodedToken === false || strlen($decodedToken) !== 32 || rtrim(strtr(base64_encode($decodedToken), '+/', '-_'), '=') !== $token) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found.');
}

$statement = $pdo->prepare(
    "SELECT i.id,i.invoice_number,i.status,i.issue_date,i.due_date,i.created_at,i.subtotal,i.discount_percent,
            i.tax_percent,i.tax_amount,i.total,i.notes,i.payment_terms,COALESCE(p.paid_amount,0) paid_amount,
            i.company_name_snapshot,i.billing_address_snapshot,i.phone_number_snapshot,i.recipient_email,
            c.name customer_name,c.email customer_email,c.phone,c.billing_address
       FROM invoices i
       JOIN customers c ON c.id=i.customer_id
       LEFT JOIN (SELECT invoice_id,SUM(amount) paid_amount FROM payments GROUP BY invoice_id) p ON p.invoice_id=i.id
      WHERE i.public_token=? AND i.status <> 'draft'
      LIMIT 1"
);
$statement->execute([$token]);
$invoice = $statement->fetch();
if (!$invoice) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found.');
}
apply_invoice_party_snapshot($invoice);

$itemsStatement = $pdo->prepare(
    'SELECT ii.description,ii.quantity,ii.line_total,p.name catalogue_name,p.category catalogue_category,p.billing_cycle catalogue_billing_cycle
       FROM invoice_items ii
       LEFT JOIN products p ON p.id=ii.product_id
      WHERE ii.invoice_id=?
      ORDER BY ii.id'
);
$itemsStatement->execute([$invoice['id']]);
$items = $itemsStatement->fetchAll();
$paymentsStatement = $pdo->prepare(
    'SELECT amount,payment_date,method,reference
       FROM payments
      WHERE invoice_id=?
      ORDER BY payment_date ASC,id ASC'
);
$paymentsStatement->execute([$invoice['id']]);
$payments = $paymentsStatement->fetchAll();
$hasPayments = count($payments) > 0;
$paymentsTotalCents = array_reduce(
    $payments,
    static fn(int $total, array $payment): int => $total + money_to_cents($payment['amount']),
    0
);
$balanceCents = max(0, money_to_cents($invoice['total']) - $paymentsTotalCents);

$company = [];
$companyQuery = $pdo->query(
    'SELECT setting_key,setting_value
       FROM company_settings
      WHERE setting_key IN ("business_name","business_email","business_phone","business_address","payment_instructions","payment_terms","company_logo")'
);
foreach ($companyQuery->fetchAll() as $row) {
    $company[$row['setting_key']] = $row['setting_value'];
}
$logoFilename = (string) ($company['company_logo'] ?? '');
$logoUrl = preg_match('/\Acompany-logo-[a-f0-9]{32}\.(?:png|jpg|webp)\z/', $logoFilename)
    ? app_url('/assets/uploads/' . rawurlencode($logoFilename))
    : '';
$status = invoice_status($invoice);
$statusKey = match ($status) {
    'draft', 'cancelled' => 'draft',
    'issued' => 'unpaid',
    'partially_paid' => 'partial',
    'paid' => 'paid',
    'overdue' => 'overdue',
    default => 'unpaid',
};
$statusLabel = match ($status) {
    'issued' => 'Unpaid',
    'partially_paid' => 'Partially paid',
    'paid' => 'Paid',
    default => status_label($status),
};
$publicInvoiceUrl = '';
try {
    $publicInvoiceUrl = public_invoice_url($token);
} catch (InvalidArgumentException) {
    error_log('Public invoice QR URL could not be constructed because APP_PUBLIC_BASE_URL is invalid.');
}
$paymentTerms = trim((string) ($invoice['payment_terms'] ?? ''));
if ($paymentTerms === 'Payment due within 30 days.') {
    $paymentTerms = '';
}
$invoicePaymentTerms = trim((string) ($company['payment_terms'] ?? ''));
if ($invoicePaymentTerms === '') {
    $invoicePaymentTerms = trim((string) ($invoice['notes'] ?? ''));
}
$discountCents = money_to_cents($invoice['subtotal']) - money_to_cents($invoice['total']) + money_to_cents($invoice['tax_amount']);
$pageTitle = $invoice['invoice_number'] ?: 'Invoice';
$isPublicInvoice = true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="referrer" content="no-referrer">
  <title><?= e($pageTitle) ?> · Breax Technologies</title>
  <link rel="icon" type="image/png" href="<?= e(app_url('/assets/images/favicon.png')) ?>">
  <link rel="stylesheet" href="<?= e(app_url('/assets/css/styles.css')) ?>">
</head>
<body>
<?php require __DIR__ . '/includes/invoice_document.php'; ?>
<?php if ($publicInvoiceUrl !== ''): require __DIR__ . '/includes/invoice_qr_script.php'; endif; ?>
</body>
</html>
