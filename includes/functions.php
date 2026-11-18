<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

const PAYMENT_METHODS = ['Bank transfer', 'Cash', 'Mobile money', 'Cheque'];

function app_url(string $path = ''): string
{
    $path = ltrim($path, '/');
    $path = preg_replace('/\.php(?=([?#]|$))/i', '', $path) ?? $path;
    return APP_BASE_URL . '/' . $path;
}

function public_invoice_base_url(): string
{
    $baseUrl = trim((string) (getenv('APP_BASE_URL') ?: getenv('APP_PUBLIC_BASE_URL')));
    $parts = $baseUrl !== '' ? parse_url($baseUrl) : false;
    if (
        $parts === false
        || !isset($parts['scheme'], $parts['host'])
        || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
        || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts)) !== []
        || filter_var($baseUrl, FILTER_VALIDATE_URL) === false
    ) {
        throw new InvalidArgumentException('Set APP_PUBLIC_BASE_URL to the public HTTP or HTTPS application URL before issuing invoices.');
    }

    return rtrim($baseUrl, '/');
}

function public_invoice_url(string $token): string
{
    if (!preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token)) {
        throw new InvalidArgumentException('The public invoice token is invalid.');
    }

    return public_invoice_base_url() . '/i/' . $token;
}

function create_public_invoice_link(PDO $pdo, int $invoiceId): string
{
    $baseUrl = public_invoice_base_url();
    $invoiceQuery = $pdo->prepare('SELECT status,public_token FROM invoices WHERE id=? FOR UPDATE');
    $invoiceQuery->execute([$invoiceId]);
    $invoice = $invoiceQuery->fetch();
    if (!$invoice || $invoice['status'] === 'draft') {
        throw new InvalidArgumentException('A public link can only be created for an issued invoice.');
    }
    if (is_string($invoice['public_token']) && $invoice['public_token'] !== '') {
        return $baseUrl . '/i/' . $invoice['public_token'];
    }

    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $pdo->prepare('UPDATE invoices SET public_token=?,public_token_created_at=UTC_TIMESTAMP() WHERE id=?')->execute([$token, $invoiceId]);
    $pdo->prepare('INSERT INTO invoice_public_link_events (invoice_id,event_type) VALUES (?,?)')->execute([$invoiceId, 'created']);

    return $baseUrl . '/i/' . $token;
}

function regenerate_public_invoice_link(PDO $pdo, int $invoiceId): string
{
    $baseUrl = public_invoice_base_url();
    $invoiceQuery = $pdo->prepare('SELECT status,public_token FROM invoices WHERE id=? FOR UPDATE');
    $invoiceQuery->execute([$invoiceId]);
    $invoice = $invoiceQuery->fetch();
    if (!$invoice || $invoice['status'] === 'draft') {
        throw new InvalidArgumentException('A public link can only be created for an issued invoice.');
    }

    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $pdo->prepare('UPDATE invoices SET public_token=?,public_token_created_at=UTC_TIMESTAMP() WHERE id=?')->execute([$token, $invoiceId]);
    if (is_string($invoice['public_token']) && $invoice['public_token'] !== '') {
        $pdo->prepare("INSERT INTO invoice_public_link_events (invoice_id,event_type) VALUES (?, 'revoked')")->execute([$invoiceId]);
        $eventType = 'regenerated';
    } else {
        $eventType = 'created';
    }
    $pdo->prepare('INSERT INTO invoice_public_link_events (invoice_id,event_type) VALUES (?,?)')->execute([$invoiceId, $eventType]);

    return $baseUrl . '/i/' . $token;
}

function revoke_public_invoice_link(PDO $pdo, int $invoiceId): void
{
    $invoiceQuery = $pdo->prepare('SELECT status,public_token FROM invoices WHERE id=? FOR UPDATE');
    $invoiceQuery->execute([$invoiceId]);
    $invoice = $invoiceQuery->fetch();
    if (!$invoice || $invoice['status'] === 'draft') {
        throw new InvalidArgumentException('Only issued invoices can have a public link revoked.');
    }
    if (!is_string($invoice['public_token']) || $invoice['public_token'] === '') {
        throw new InvalidArgumentException('This invoice has no active public link.');
    }

    $pdo->prepare('UPDATE invoices SET public_token=NULL,public_token_created_at=NULL WHERE id=?')->execute([$invoiceId]);
    $pdo->prepare("INSERT INTO invoice_public_link_events (invoice_id,event_type) VALUES (?, 'revoked')")->execute([$invoiceId]);
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function format_invoice_payment_instructions(string $instructions): string
{
    $lines = preg_split('/\r\n|\r|\n/', e($instructions));
    if (!is_array($lines)) {
        throw new RuntimeException('Invoice payment instructions could not be formatted.');
    }
    foreach ($lines as &$line) {
        $line = preg_replace(
            '/^(\s*)(MPESA\s*[–-]\s*Send Money|Bank Transfer|Account Number|Account Name|Name):/iu',
            '$1<strong>$2:</strong>',
            $line
        ) ?? throw new RuntimeException('Invoice payment instruction labels could not be formatted.');
    }
    unset($line);
    return implode("<br>\n", $lines);
}

function delete_selected_invoices(PDO $pdo, array $invoiceIds): int
{
    if ($pdo->inTransaction()) {
        throw new LogicException('Selected invoices must be deleted outside an existing database transaction.');
    }
    if ($invoiceIds === [] || count($invoiceIds) > 250) {
        throw new InvalidArgumentException('Select between 1 and 250 invoices to delete.');
    }

    $invoiceIds = array_values(array_unique($invoiceIds));
    foreach ($invoiceIds as $invoiceId) {
        if (!is_int($invoiceId) || $invoiceId < 1) {
            throw new InvalidArgumentException('The selected invoice list is invalid.');
        }
    }

    $lockSuffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $invoiceQuery = $pdo->prepare('SELECT status FROM invoices WHERE id=?' . $lockSuffix);
    $deleteQuery = $pdo->prepare('DELETE FROM invoices WHERE id=?');
    $deletePaymentsQuery = $pdo->prepare('DELETE FROM payments WHERE invoice_id=?');
    $deletePublicLinkEventsQuery = $pdo->prepare('DELETE FROM invoice_public_link_events WHERE invoice_id=?');
    $pdo->beginTransaction();
    try {
        foreach ($invoiceIds as $invoiceId) {
            $invoiceQuery->execute([$invoiceId]);
            if ($invoiceQuery->fetchColumn() === false) {
                throw new InvalidArgumentException('A selected invoice no longer exists. No selected invoices were deleted.');
            }
        }
        foreach ($invoiceIds as $invoiceId) {
            $deletePaymentsQuery->execute([$invoiceId]);
            $deletePublicLinkEventsQuery->execute([$invoiceId]);
            $deleteQuery->execute([$invoiceId]);
            if ($deleteQuery->rowCount() !== 1) {
                throw new RuntimeException('A selected invoice could not be deleted.');
            }
        }
        $pdo->commit();
        return count($invoiceIds);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $submitted = $_POST['csrf_token'] ?? '';
    if (!is_string($submitted) || !hash_equals(csrf_token(), $submitted)) {
        http_response_code(419);
        exit('Your session expired. Reload the page and try again.');
    }
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function take_flash(): ?array
{
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($message) ? $message : null;
}

function money(int|float|string $amount, ?string $currency = null): string
{
    $formattedAmount = number_format((float) $amount, 2, '.', ',');
    $formattedAmount = rtrim(rtrim($formattedAmount, '0'), '.');
    return e($currency ?? currency_code()) . ' ' . $formattedAmount;
}

function currency_code(): string
{
    static $currency;
    if ($currency !== null) {
        return $currency;
    }
    $currency = APP_CURRENCY;
    if (function_exists('db')) {
        $statement = db()->prepare("SELECT setting_value FROM company_settings WHERE setting_key='currency'");
        $statement->execute();
        $stored = $statement->fetchColumn();
        if (is_string($stored) && preg_match('/^[A-Z]{3}$/', $stored)) {
            $currency = $stored;
        }
    }
    return $currency;
}

function supported_invoice_currencies(): array
{
    return ['KES', 'USD', 'GBP', 'EUR'];
}

function currency_flag_code(string $currency): ?string
{
    $flagCodes = ['KES' => 'ke', 'USD' => 'us', 'GBP' => 'gb', 'EUR' => 'eu'];
    return $flagCodes[$currency] ?? null;
}

function currency_flag_url(string $currency): ?string
{
    $flagCode = currency_flag_code($currency);
    $currencyFlagUrl = trim((string) (getenv($currency . '_FLAG_URL') ?: ''));
    $configuredUrl = trim((string) (getenv('PUBLIC_FLAG_BASE_URL') ?: ''));
    if ($flagCode === null) {
        return null;
    }

    if ($currencyFlagUrl !== '') {
        return $currencyFlagUrl;
    }
    if ($configuredUrl === '') {
        return null;
    }

    $configuredUrl = rtrim($configuredUrl, '/');
    if (str_contains($configuredUrl, '{code}')) {
        return str_replace('{code}', $flagCode, $configuredUrl);
    }

    return $configuredUrl . '/' . $flagCode . '.svg';
}

function parse_invoice_currencies(mixed $value): array
{
    if (!is_string($value)) {
        return supported_invoice_currencies();
    }
    $currencies = array_values(array_intersect(
        supported_invoice_currencies(),
        array_map('strtoupper', array_map('trim', explode(',', $value)))
    ));
    return $currencies !== [] ? $currencies : supported_invoice_currencies();
}

function invoice_currency(array $invoice): string
{
    $currency = strtoupper((string) ($invoice['currency_code'] ?? ''));
    return in_array($currency, supported_invoice_currencies(), true) ? $currency : currency_code();
}

function money_to_cents(mixed $value): int
{
    if (!is_scalar($value) || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', (string) $value)) {
        throw new InvalidArgumentException('Enter a valid non-negative amount with up to two decimal places.');
    }
    [$whole, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '');
    return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
}

function cents_to_decimal(int $cents): string
{
    return number_format($cents / 100, 2, '.', '');
}

function invoice_line_cents(int $unitPriceCents, int $quantityHundredths): int
{
    if ($unitPriceCents <= 0 || $unitPriceCents > 9999999999 || $quantityHundredths <= 0 || $quantityHundredths > 10000000) {
        throw new InvalidArgumentException('Invoice line values are outside the supported range.');
    }
    return intdiv($unitPriceCents * $quantityHundredths + 50, 100);
}

function percentage_cents(int $amountCents, int $basisPoints): int
{
    if ($amountCents < 0 || $basisPoints < 0 || $basisPoints > 10000) {
        throw new InvalidArgumentException('Percentage calculation is outside the supported range.');
    }
    return intdiv($amountCents, 10000) * $basisPoints + intdiv(($amountCents % 10000) * $basisPoints + 5000, 10000);
}

function post_string(string $key, int $maxLength = 255, bool $required = false): string
{
    $raw = $_POST[$key] ?? '';
    if (!is_string($raw) && !is_numeric($raw)) {
        throw new InvalidArgumentException('One of the submitted fields has an invalid format.');
    }
    $value = trim((string) $raw);
    if (($required && $value === '') || mb_strlen($value) > $maxLength) {
        throw new InvalidArgumentException($required ? 'Complete all required fields.' : 'One of the fields is too long.');
    }
    return $value;
}

function invoice_status(array $invoice): string
{
    if ($invoice['status'] === 'draft' || $invoice['status'] === 'cancelled') {
        return (string) $invoice['status'];
    }
    $balance = money_to_cents($invoice['total']) - money_to_cents($invoice['paid_amount'] ?? '0.00');
    if ($balance <= 0) {
        return 'paid';
    }
    if (($invoice['due_date'] ?? '') < date('Y-m-d')) {
        return 'overdue';
    }
    return money_to_cents($invoice['paid_amount'] ?? '0.00') > 0 ? 'partially_paid' : 'issued';
}

function status_label(string $status): string
{
    return ucwords(str_replace('_', ' ', $status));
}