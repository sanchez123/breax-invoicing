<?php
declare(strict_types=1);

function normalize_customer_domain(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $value)) {
        $host = parse_url($value, PHP_URL_HOST);
        if (!is_string($host)) {
            throw new InvalidArgumentException('Enter a valid hostname.');
        }
        $value = $host;
    } else {
        $value = preg_split('~[/?#]~', $value, 2)[0] ?? '';
    }

    $domain = mb_strtolower(rtrim(trim($value), '.'));
    while (str_starts_with($domain, 'www.')) {
        $domain = substr($domain, 4);
    }
    if (strlen($domain) > 253 || substr_count($domain, '.') < 1) {
        throw new InvalidArgumentException('Enter a valid hostname such as example.co.ke.');
    }

    foreach (explode('.', $domain) as $label) {
        if (strlen($label) < 1 || strlen($label) > 63
            || !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label)) {
            throw new InvalidArgumentException('Enter a valid hostname such as example.co.ke.');
        }
    }
    $labels = explode('.', $domain);
    $topLevelDomain = end($labels);
    if (!preg_match('/^[a-z0-9-]{2,63}$/', $topLevelDomain)) {
        throw new InvalidArgumentException('Enter a valid hostname such as example.co.ke.');
    }

    return $domain;
}

function normalize_primary_customer_domain(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('~[/:?#\s]~', $value) || preg_match('/[^\x00-\x7F]/', $value)) {
        throw new InvalidArgumentException('Invalid primary customer domain.');
    }

    $domain = normalize_customer_domain($value);
    $labels = explode('.', $domain);
    $topLevelDomain = end($labels);
    if (strlen($domain) > 253 || count($labels) < 2 || !preg_match('/^[a-z]{2,63}$/', $topLevelDomain)) {
        throw new InvalidArgumentException('Invalid primary customer domain.');
    }
    return $domain;
}

function normalize_customer_phone(string $value): string
{
    $value = trim($value);
    if ($value === '' || !preg_match('/^\+?[0-9\s().-]+$/', $value)) {
        throw new InvalidArgumentException('Enter a valid phone number using digits, spaces, parentheses, dots, or hyphens.');
    }
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if (strlen($digits) < 7 || strlen($digits) > 15) {
        throw new InvalidArgumentException('Enter a phone number containing 7 to 15 digits.');
    }
    return $digits;
}

function validate_customer_domain_phone(string $value): string
{
    $value = trim($value);
    if ($value === '' || mb_strlen($value) > 20 || !preg_match('/^[0-9+\s()-]+$/', $value)) {
        throw new InvalidArgumentException('Enter a valid phone number');
    }
    $normalized = preg_replace('/[\s()-]+/', '', $value) ?? '';
    if (!preg_match('/^\+?\d{9,15}$/D', $normalized)) {
        throw new InvalidArgumentException('Enter a valid phone number');
    }
    // Keep the user's trimmed display format; separators are stripped only for validation.
    return $value;
}

function customer_domain_recipient(PDO $pdo, int $customerId, ?int $domainId, bool $requireDomain, bool $lockForUpdate = false): array
{
    $customerQuery = $pdo->prepare('SELECT id,name,email,billing_address,invoice_delivery FROM customers WHERE id=?' . ($lockForUpdate ? ' FOR UPDATE' : ''));
    $customerQuery->execute([$customerId]);
    $customer = $customerQuery->fetch();
    if (!$customer) {
        throw new InvalidArgumentException('Choose an active customer.');
    }

    $delivery = $customer['invoice_delivery'] === 'domain' ? 'domain' : 'primary';
    $domain = null;
    if ($domainId !== null) {
        $domainQuery = $pdo->prepare("SELECT id,domain_name,invoice_email,company_name,billing_address,phone_number FROM customer_domains
            WHERE id=? AND customer_id=? AND status='active'" . ($lockForUpdate ? ' FOR UPDATE' : ''));
        $domainQuery->execute([$domainId, $customerId]);
        $domain = $domainQuery->fetch() ?: null;
        if (!$domain) {
            throw new InvalidArgumentException('Choose an active domain for this customer.');
        }
    }

    if ($delivery === 'domain' && $requireDomain && $domain === null) {
        throw new InvalidArgumentException('Choose a domain with a valid invoice email before issuing this invoice.');
    }

    $recipient = $delivery === 'domain'
        ? trim((string) ($domain['invoice_email'] ?? ''))
        : trim((string) ($customer['email'] ?? ''));
    if ($delivery === 'domain' && $domain !== null && $requireDomain
        && ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL))) {
        throw new InvalidArgumentException('The selected domain needs a valid invoice email before this invoice can be issued.');
    }
    if ($delivery === 'domain' && $domain !== null) {
        $companyName = trim((string) ($domain['company_name'] ?? ''));
        $billingAddress = trim((string) ($domain['billing_address'] ?? ''));
        if (mb_strlen($companyName) < 2 || mb_strlen($companyName) > 150) {
            throw new InvalidArgumentException('The selected domain needs a valid company name before this invoice can be saved.');
        }
        if (mb_strlen($billingAddress) < 5 || mb_strlen($billingAddress) > 500) {
            throw new InvalidArgumentException('The selected domain needs a valid billing address before this invoice can be saved.');
        }
    } else {
        $companyName = null;
        $billingAddress = null;
    }
    if ($delivery === 'primary' && $requireDomain
        && ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL))) {
        throw new InvalidArgumentException('The customer needs a valid primary email before this invoice can be issued.');
    }

    return [
        'domain_id' => $domain === null ? null : (int) $domain['id'],
        'domain_name' => $domain['domain_name'] ?? null,
        'recipient_email' => $recipient !== '' ? $recipient : null,
        'delivery' => $delivery,
        'company_name_snapshot' => $companyName,
        'billing_address_snapshot' => $billingAddress,
        'phone_number_snapshot' => $delivery === 'domain' && $domain !== null
            ? trim((string) ($domain['phone_number'] ?? ''))
            : null,
    ];
}

function apply_invoice_party_snapshot(array &$invoice): void
{
    $companyName = trim((string) ($invoice['company_name_snapshot'] ?? ''));
    if ($companyName === '') {
        return;
    }

    $invoice['customer_name'] = $companyName;
    $invoice['billing_address'] = (string) ($invoice['billing_address_snapshot'] ?? '');
    $invoice['customer_email'] = (string) ($invoice['recipient_email'] ?? ($invoice['customer_email'] ?? ''));
    $invoice['phone'] = (string) ($invoice['phone_number_snapshot'] ?? '');
}
