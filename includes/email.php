<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/customer_domains.php';

use PHPMailer\PHPMailer\PHPMailer;

const INVOICE_EMAIL_TYPES = ['invoice_issued', 'invoice_partial', 'invoice_overdue'];
const EMAIL_REQUIRED_PLACEHOLDERS = [
    'COMPANY_NAME', 'LOGO_URL', 'CLIENT_NAME', 'CLIENT_EMAIL', 'INVOICE_NUMBER', 'ISSUE_DATE',
    'DUE_DATE', 'TOTAL', 'AMOUNT_PAID', 'BALANCE', 'LAST_PAYMENT_DATE', 'DAYS_OVERDUE',
    'PUBLIC_INVOICE_URL', 'ATTACHMENT_NAME', 'SUPPORT_EMAIL', 'SENDER_ADDRESS', 'PAYMENT_DETAILS',
];
const EMAIL_ISSUED_PLACEHOLDERS = [
    'COMPANY_NAME', 'LOGO_URL', 'CLIENT_NAME', 'CLIENT_EMAIL', 'INVOICE_NUMBER',
    'ISSUE_DATE', 'DUE_DATE', 'TOTAL', 'PUBLIC_INVOICE_URL', 'ATTACHMENT_NAME',
    'SUPPORT_EMAIL', 'SENDER_ADDRESS', 'PAYMENT_DETAILS_BLOCK', 'EXCHANGE_RATE_BLOCK',
];
const EMAIL_PARTIAL_PLACEHOLDERS = [
    'COMPANY_NAME', 'LOGO_URL', 'CLIENT_NAME', 'CLIENT_EMAIL', 'INVOICE_NUMBER',
    'DUE_DATE', 'TOTAL', 'AMOUNT_PAID', 'BALANCE', 'LAST_PAYMENT_DATE',
    'PUBLIC_INVOICE_URL', 'ATTACHMENT_NAME', 'SUPPORT_EMAIL', 'SENDER_ADDRESS',
    'PAYMENT_DETAILS_BLOCK', 'TRANSACTIONS_HTML', 'EXCHANGE_RATE_BLOCK',
];
const EMAIL_PAID_PLACEHOLDERS = [
    'COMPANY_NAME', 'LOGO_URL', 'CLIENT_NAME', 'CLIENT_EMAIL', 'INVOICE_NUMBER',
    'DUE_DATE', 'TOTAL', 'AMOUNT_PAID', 'BALANCE', 'LAST_PAYMENT_DATE',
    'PUBLIC_INVOICE_URL', 'ATTACHMENT_NAME', 'SUPPORT_EMAIL', 'SENDER_ADDRESS',
    'TRANSACTIONS_HTML', 'EXCHANGE_RATE_BLOCK',
];
const EMAIL_OVERDUE_PLACEHOLDERS = [
    'COMPANY_NAME', 'LOGO_URL', 'CLIENT_NAME', 'CLIENT_EMAIL', 'INVOICE_NUMBER',
    'DUE_DATE', 'BALANCE', 'DAYS_OVERDUE', 'PUBLIC_INVOICE_URL', 'ATTACHMENT_NAME',
    'SUPPORT_EMAIL', 'SENDER_ADDRESS', 'PAYMENT_DETAILS_BLOCK', 'EXCHANGE_RATE_BLOCK',
];
const EMAIL_ADMIN_OVERDUE_PLACEHOLDERS = [
    'ADMIN_NAME', 'CLIENT_COMPANY', 'COMPANY_NAME', 'LOGO_URL', 'INVOICE_NUMBER',
    'DUE_DATE', 'DAYS_OVERDUE', 'BALANCE', 'ADMIN_INVOICE_URL', 'SENDER_ADDRESS', 'EXCHANGE_RATE_BLOCK',
];
const EMAIL_ADMIN_DOMAIN_EXPIRY_PLACEHOLDERS = [
    'ADMIN_NAME', 'COMPANY_NAME', 'LOGO_URL', 'AS_OF_DATE', 'DOMAIN_COUNT', 'DOMAIN_ROWS_HTML', 'ATTACHMENT_NAME', 'SENDER_ADDRESS',
];
const EMAIL_ADMIN_OVERDUE_INVOICE_REPORT_PLACEHOLDERS = [
    'ADMIN_NAME', 'COMPANY_NAME', 'LOGO_URL', 'AS_OF_DATE', 'INVOICE_COUNT', 'ATTACHMENT_NAME', 'SENDER_ADDRESS',
];
const EMAIL_ADMIN_GOOGLE_WORKSPACE_REPORT_PLACEHOLDERS = [
    'ADMIN_NAME', 'COMPANY_NAME', 'LOGO_URL', 'AS_OF_DATE', 'WORKSPACE_COUNT', 'ATTACHMENT_NAME', 'SENDER_ADDRESS',
];

function mail_setting(string $name, string $default = ''): string
{
    $value = getenv($name);
    $value = $value === false ? '' : trim((string) $value);
    return $value === '' ? $default : $value;
}

function safe_email_error(string $error): string
{
    foreach (['MAIL_PASSWORD', 'MAIL_USERNAME'] as $setting) {
        $secret = mail_setting($setting);
        if ($secret !== '') {
            $error = str_replace($secret, '[redacted]', $error);
        }
    }
    $error = preg_replace('~(/i/)[A-Za-z0-9_-]{43}~', '$1[redacted]', $error) ?? $error;
    $error = preg_replace('/\b(password|token|authorization)\s*[:=]\s*\S+/i', '$1=[redacted]', $error) ?? $error;
    $error = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $error) ?? $error;
    return mb_substr(trim(strip_tags($error)), 0, 1000);
}

function email_mode(): string
{
    if (strtolower(mail_setting('APP_ENV', 'development')) !== 'production') {
        throw new RuntimeException('Direct email delivery requires APP_ENV=production.');
    }
    return 'live';
}

function email_log_failure(?int $invoiceId, string $type, ?string $recipient, string $subject, string $triggeredBy, string $error, ?string $idempotencyKey = null): void
{
    $pdo = db();
    $insert = $pdo->prepare('INSERT INTO email_log (invoice_id,type,recipient,subject,status,error,triggered_by,created_at,idempotency_key) VALUES (?,?,?,?,\'failed\',?,?,UTC_TIMESTAMP(6),?)');
    $safeRecipient = $recipient !== null ? mb_substr(safe_email_error($recipient), 0, 254) : null;
    $safeSubject = mb_substr(safe_email_error($subject), 0, 255);
    $insert->execute([$invoiceId, $type, $safeRecipient, $safeSubject, safe_email_error($error), mb_substr($triggeredBy, 0, 190), $idempotencyKey]);
}

function clear_email_logs(PDO $pdo, ?int $invoiceId = null): int
{
    if ($invoiceId !== null && $invoiceId < 1) {
        throw new InvalidArgumentException('Invoice ID is invalid.');
    }

    $statement = $invoiceId === null
        ? $pdo->prepare('DELETE FROM email_log')
        : $pdo->prepare('DELETE FROM email_log WHERE invoice_id=?');
    $statement->execute($invoiceId === null ? [] : [$invoiceId]);
    return $statement->rowCount();
}

function render_email_template(array $values, string $templatePath = '', ?array $requiredPlaceholders = null): array
{
    $templatePath = $templatePath !== '' ? $templatePath : dirname(__DIR__) . '/emails/invoice.html';
    if (!is_file($templatePath) || !is_readable($templatePath)) {
        throw new RuntimeException('The email template is missing or unreadable.');
    }
    $html = file_get_contents($templatePath);
    if (!is_string($html)) {
        throw new RuntimeException('The email template could not be read.');
    }
    preg_match_all('/\{\{([A-Z0-9_]+)\}\}/', $html, $matches);
    $usedTokens = array_unique($matches[1]);
    $requiredPlaceholders ??= EMAIL_REQUIRED_PLACEHOLDERS;
    $missingFromTemplate = array_diff($requiredPlaceholders, $usedTokens);
    if ($missingFromTemplate !== []) {
        throw new RuntimeException('Email template is missing required placeholders: ' . implode(', ', $missingFromTemplate) . '.');
    }
    $missing = [];
    foreach ($usedTokens as $token) {
        if (!array_key_exists($token, $values)
            || (!in_array($token, ['EXCHANGE_RATE_BLOCK', 'PAYMENT_DETAILS_BLOCK'], true)
                && (string) $values[$token] === '')) {
            $missing[] = $token;
        }
    }
    if ($missing !== []) {
        throw new InvalidArgumentException('Email cannot be sent; configure or provide: ' . implode(', ', $missing) . '.');
    }
    if (in_array('EXCHANGE_RATE_BLOCK', $usedTokens, true)) {
        $exchangeRateBlock = (string) $values['EXCHANGE_RATE_BLOCK'];
        if ($exchangeRateBlock === '') {
            $html = preg_replace('/^[ \t]*\{\{EXCHANGE_RATE_BLOCK\}\}\r?\n/m', '', $html) ?? $html;
        } else {
            $html = str_replace('{{EXCHANGE_RATE_BLOCK}}', $exchangeRateBlock, $html);
        }
    }
    $html = preg_replace_callback('/\{\{([A-Z0-9_]+)\}\}/', static function (array $match) use ($values): string {
        if (!array_key_exists($match[1], $values)) {
            throw new InvalidArgumentException('Email cannot be sent; template value ' . $match[1] . ' is unavailable.');
        }
        return in_array($match[1], ['PAYMENT_DETAILS', 'PAYMENT_DETAILS_BLOCK', 'TRANSACTIONS_HTML', 'EXCHANGE_RATE_BLOCK', 'DOMAIN_ROWS_HTML'], true)
            ? (string) $values[$match[1]]
            : htmlspecialchars((string) $values[$match[1]], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }, $html);
    if (!is_string($html) || preg_match('/\{\{[A-Z0-9_]+\}\}/', $html)) {
        throw new RuntimeException('Email template contains an unresolved placeholder.');
    }
    $plain = preg_replace_callback('~<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~is', static function (array $match): string {
        return strip_tags($match[2]) . ' (' . html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') . ')';
    }, $html) ?? $html;
    $plain = preg_replace('~<br\s*/?>~i', "\n", $plain) ?? $plain;
    $plain = preg_replace('~</p\s*>~i', "\n\n", $plain) ?? $plain;
    $plain = html_entity_decode(strip_tags($plain), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return ['html' => $html, 'text' => trim(preg_replace("/[ \t]+\n/", "\n", $plain) ?? $plain)];
}

function payment_details_html(array $settings): string
{
    $fields = [
        'mpesa_phone' => 'M-Pesa phone number',
        'mpesa_name' => 'M-Pesa account name',
        'bank_name' => 'Bank name',
        'bank_account_number' => 'Bank account number',
        'bank_account_name' => 'Bank account name',
    ];
    foreach ($fields as $key => $label) {
        if (trim((string) ($settings[$key] ?? '')) === '') {
            throw new InvalidArgumentException('Email cannot be sent; set ' . $label . ' in Company Settings.');
        }
    }
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<b style="color:#141d38;">MPESA – Send Money:</b> ' . $escape((string) $settings['mpesa_phone'])
        . '<br><b style="color:#141d38;">Name:</b> ' . $escape((string) $settings['mpesa_name'])
        . '<br><br><b style="color:#141d38;">Bank Transfer:</b> ' . $escape((string) $settings['bank_name'])
        . '<br><b style="color:#141d38;">Account Number:</b> ' . $escape((string) $settings['bank_account_number'])
        . '<br><b style="color:#141d38;">Account Name:</b> ' . $escape((string) $settings['bank_account_name']);
}

function company_email_settings(PDO $pdo): array
{
    $rows = $pdo->query('SELECT setting_key,setting_value FROM company_settings')->fetchAll();
    $settings = [];
    foreach ($rows as $row) {
        $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
    }
    return $settings;
}

function email_flag_image_available(string $url): bool
{
    $cachePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'breax-email-flag-v2-' . hash('sha256', $url) . '.json';
    $cached = is_file($cachePath) ? json_decode((string) @file_get_contents($cachePath), true) : null;
    if (is_array($cached) && isset($cached['checked_at'], $cached['available'])
        && is_int($cached['checked_at']) && is_bool($cached['available'])
        && time() - $cached['checked_at'] < 86400) {
        return $cached['available'];
    }

    $available = false;
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 8,
            'ignore_errors' => true,
            'follow_location' => 0,
            'header' => "Accept: image/*\r\n",
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $stream = @fopen($url, 'rb', false, $context);
    if (is_resource($stream)) {
        $metadata = stream_get_meta_data($stream);
        $status = 0;
        $contentType = '';
        foreach ($metadata['wrapper_data'] ?? [] as $header) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~i', (string) $header, $statusMatch)) {
                $status = (int) $statusMatch[1];
            } elseif (stripos((string) $header, 'Content-Type:') === 0) {
                $contentType = strtolower(trim(explode(';', substr((string) $header, 13))[0]));
            }
        }
        $firstByte = fread($stream, 1);
        $available = $status === 200 && str_starts_with($contentType, 'image/')
            && $firstByte !== false && $firstByte !== '';
        fclose($stream);
    }
    if (@file_put_contents($cachePath, json_encode(['checked_at' => time(), 'available' => $available]), LOCK_EX) === false) {
        error_log('Email flag check result could not be cached for 24 hours.');
    }
    return $available;
}

function invoice_email_flag_url(string $currency): ?string
{
    $currency = strtoupper($currency);
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        return null;
    }
    $setting = getenv($currency . '_FLAG_URL');
    if (is_string($setting) && trim($setting) !== '') {
        $url = trim($setting);
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            error_log('Email flag URL is invalid for currency ' . $currency . '.');
            return null;
        }
    } else {
        $imageDirectory = dirname(__DIR__) . '/assets/images';
        $imageName = null;
        if (is_dir($imageDirectory) && is_readable($imageDirectory)) {
            foreach (scandir($imageDirectory) ?: [] as $fileName) {
                if (strtolower($fileName) === strtolower($currency . '.png')) {
                    $imageName = $fileName;
                    break;
                }
            }
        }
        if ($imageName === null) {
            return null;
        }
        $baseUrl = trim((string) (getenv('APP_BASE_URL') ?: getenv('APP_PUBLIC_BASE_URL')));
        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false
            || strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME)) !== 'https') {
            error_log('Email flag fallback URL is unavailable for currency ' . $currency . '.');
            return null;
        }
        $url = rtrim($baseUrl, '/') . '/assets/images/' . rawurlencode($imageName);
    }

    if (!email_flag_image_available($url)) {
        error_log('Email flag image check failed for currency ' . $currency . '.');
        return null;
    }
    return $url;
}

function exchange_rate_email_content(array $invoice, string $type, int $amountCents, string $invoiceNumber): array
{
    $currency = strtoupper(invoice_currency($invoice));
    if ($currency === strtoupper(currency_code())) {
        return ['html' => '', 'text' => ''];
    }

    $rate = $invoice['kes_exchange_rate'] ?? null;
    $rateDate = (string) ($invoice['fx_rate_date'] ?? '');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $rateDate, new DateTimeZone('Africa/Nairobi'));
    if (!is_numeric($rate) || !is_finite((float) $rate) || (float) $rate <= 0
        || !$date || $date->format('Y-m-d') !== $rateDate) {
        $safeInvoiceNumber = preg_replace('/[\x00-\x1F\x7F]/u', '', $invoiceNumber) ?? '';
        error_log('Exchange rate block omitted for invoice ' . $safeInvoiceNumber . ' (' . $currency . '): invoice rate or its date is unavailable.');
        return ['html' => '', 'text' => ''];
    }

    $label = match ($type) {
        'invoice_partial' => 'Balance due',
        'payment_received' => $amountCents < money_to_cents($invoice['total']) ? 'Balance due' : 'Invoice total',
        'invoice_overdue' => 'Amount due',
        default => 'Invoice total',
    };
    $rateCaption = 'Rate as of ' . $date->format('j M Y');
    $exchangeRate = number_format((float) $rate, 2, '.', ',');
    $amount = $currency . ' ' . number_format($amountCents / 100, 2, '.', ',');
    $kesAmount = 'KES ' . number_format(round($amountCents * (float) $rate) / 100, 2, '.', ',');

    $partialPath = dirname(__DIR__) . '/emails/partials/exchange-rate-block.html';
    $html = is_file($partialPath) && is_readable($partialPath) ? file_get_contents($partialPath) : false;
    if (!is_string($html)) {
        error_log('Exchange rate email block could not be read for invoice ' . $invoiceNumber . ' (' . $currency . ').');
        return ['html' => '', 'text' => ''];
    }

    try {
        $invoiceFlag = invoice_email_flag_url($currency) ?? '';
    } catch (Throwable $exception) {
        error_log('Email flag lookup failed for currency ' . $currency . ': ' . safe_email_error($exception->getMessage()));
        $invoiceFlag = '';
    }
    try {
        $kesFlag = invoice_email_flag_url('KES') ?? '';
    } catch (Throwable $exception) {
        error_log('Email flag lookup failed for currency KES: ' . safe_email_error($exception->getMessage()));
        $kesFlag = '';
    }
    $tokens = [
        'RATE_CAPTION' => $rateCaption,
        'INVOICE_CURRENCY' => $currency,
        'EXCHANGE_RATE' => $exchangeRate,
        'FX_LABEL' => $label,
        'FX_AMOUNT' => $amount,
        'FX_AMOUNT_KES' => $kesAmount,
        'FLAG_URL_INVOICE' => $invoiceFlag,
        'FLAG_URL_KES' => $kesFlag,
    ];
    foreach ($tokens as $token => $value) {
        $html = str_replace('{{' . $token . '}}', htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $html);
    }
    $html = preg_replace('~<td style="padding-right:(?:8|6)px;"><img src=""[^>]*></td>~', '', $html) ?? $html;

    $text = implode("\n", [
        'Exchange Rate (' . $rateCaption . ')',
        '1 ' . $currency . ' = KES ' . $exchangeRate,
        $label . ': ' . $amount,
        'Equivalent in KES: ' . $kesAmount,
        '',
    ]);
    return ['html' => $html, 'text' => $text];
}

function invoice_email_context(PDO $pdo, int $invoiceId, string $emailType = ''): array
{
    $query = $pdo->prepare("SELECT i.*,c.name customer_name,c.contact_person,
        COALESCE(NULLIF(TRIM(i.recipient_email),''),c.email) customer_email
        FROM invoices i JOIN customers c ON c.id=i.customer_id WHERE i.id=?");
    $query->execute([$invoiceId]);
    $invoice = $query->fetch();
    if (!$invoice) {
        throw new InvalidArgumentException('Invoice not found.');
    }
    apply_invoice_party_snapshot($invoice);
    $settings = company_email_settings($pdo);
    $paymentsQuery = $pdo->prepare('SELECT amount,payment_date,method,reference FROM payments WHERE invoice_id=? ORDER BY payment_date DESC,id DESC');
    $paymentsQuery->execute([$invoiceId]);
    $payments = $paymentsQuery->fetchAll();
    $paidCents = array_reduce(
        $payments,
        static fn(int $total, array $payment): int => $total + money_to_cents($payment['amount']),
        0
    );
    $invoice['paid_amount'] = cents_to_decimal($paidCents);
    $totalCents = money_to_cents($invoice['total']);
    $balanceCents = max(0, $totalCents - $paidCents);
    $baseUrl = trim((string) (getenv('APP_BASE_URL') ?: getenv('APP_PUBLIC_BASE_URL')));
    if (!is_string($invoice['public_token']) || $invoice['public_token'] === '') {
        $publicUrl = '';
    } else {
        $publicUrl = public_invoice_url((string) $invoice['public_token']);
    }
    $logoUrl = mail_setting('LOGO_URL');
    if ($logoUrl === '' && !empty($settings['company_logo'])) {
        $logoUrl = rtrim($baseUrl, '/') . '/assets/uploads/' . rawurlencode((string) $settings['company_logo']);
    }
    if ($logoUrl !== '' && (!filter_var($logoUrl, FILTER_VALIDATE_URL) || strtolower((string) parse_url($logoUrl, PHP_URL_SCHEME)) !== 'https')) {
        throw new InvalidArgumentException('LOGO_URL must be a full public HTTPS URL.');
    }
    $contactName = trim((string) ($invoice['contact_person'] ?? ''));
    $clientName = $contactName !== '' ? $contactName : (string) $invoice['customer_name'];
    $supportEmail = mail_setting('SUPPORT_EMAIL', (string) ($settings['business_email'] ?? ''));
    $senderAddress = preg_replace('/\s+/u', ' ', trim((string) ($settings['business_address'] ?? '')));
    $issueDate = (string) $invoice['issue_date'];
    $dueDate = (string) $invoice['due_date'];
    $daysOverdue = (string) invoice_days_overdue($dueDate);
    $invoiceNumber = (string) ($invoice['invoice_number'] ?: ('Draft-' . $invoiceId));
    $transactionRows = [];
    $transactionTextRows = [];
    foreach (array_reverse($payments) as $payment) {
        $transactionDate = date('j F Y', strtotime((string) $payment['payment_date']));
        $transactionMethod = trim((string) ($payment['method'] ?? '')) ?: '—';
        $transactionReference = trim((string) ($payment['reference'] ?? '')) ?: '—';
        $transactionAmount = email_money(money_to_cents($payment['amount']), invoice_currency($invoice));
        $transactionRows[] = '<tr><td style="padding:10px 12px;border-bottom:1px solid #ece8f7;font:13px Arial,Helvetica,sans-serif;color:#5d6480;">'
            . htmlspecialchars($transactionDate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td><td style="padding:10px 12px;border-bottom:1px solid #ece8f7;font:13px Arial,Helvetica,sans-serif;color:#141d38;">'
            . htmlspecialchars($transactionMethod, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td><td style="padding:10px 12px;border-bottom:1px solid #ece8f7;font:13px Arial,Helvetica,sans-serif;color:#141d38;">'
            . htmlspecialchars($transactionReference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td><td align="right" style="padding:10px 12px;border-bottom:1px solid #ece8f7;font:13px Arial,Helvetica,sans-serif;font-weight:bold;color:#141d38;">'
            . htmlspecialchars($transactionAmount, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td></tr>';
        $transactionTextRows[] = $transactionDate . ' | ' . $transactionMethod . ' | ' . $transactionReference . ' | ' . $transactionAmount;
    }
    $transactionsHtml = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;"><thead><tr>'
        . '<th align="left" style="padding:10px 12px;background:#f1edfa;font:12px Arial,Helvetica,sans-serif;color:#5d6480;">Date</th>'
        . '<th align="left" style="padding:10px 12px;background:#f1edfa;font:12px Arial,Helvetica,sans-serif;color:#5d6480;">Method</th>'
        . '<th align="left" style="padding:10px 12px;background:#f1edfa;font:12px Arial,Helvetica,sans-serif;color:#5d6480;">Transaction reference</th>'
        . '<th align="right" style="padding:10px 12px;background:#f1edfa;font:12px Arial,Helvetica,sans-serif;color:#5d6480;">Amount</th>'
        . '</tr></thead><tbody>' . ($transactionRows !== [] ? implode('', $transactionRows) : '<tr><td colspan="4" style="padding:10px 12px;font:13px Arial,Helvetica,sans-serif;color:#5d6480;">No payments recorded.</td></tr>') . '</tbody></table>';
    $rateAmountCents = $emailType === 'invoice_partial'
        || ($emailType === 'payment_received' && $balanceCents > 0)
        || $emailType === 'invoice_overdue'
        ? $balanceCents
        : $totalCents;
    $exchangeRateContent = exchange_rate_email_content($invoice, $emailType, $rateAmountCents, $invoiceNumber);
    $paymentDetailsHtml = $balanceCents > 0 ? payment_details_html($settings) : '';
    $values = [
        'COMPANY_NAME' => (string) ($settings['business_name'] ?? ''),
        'LOGO_URL' => $logoUrl,
        'CLIENT_NAME' => $clientName,
        'CLIENT_EMAIL' => (string) ($invoice['customer_email'] ?? ''),
        'INVOICE_NUMBER' => $invoiceNumber,
        'ISSUE_DATE' => date('j F Y', strtotime($issueDate)),
        'DUE_DATE' => date('j F Y', strtotime($dueDate)),
        'TOTAL' => email_money($totalCents, invoice_currency($invoice)),
        'AMOUNT_PAID' => email_money($paidCents, invoice_currency($invoice)),
        'BALANCE' => email_money($balanceCents, invoice_currency($invoice)),
        'LAST_PAYMENT_DATE' => $payments ? date('j F Y', strtotime((string) $payments[0]['payment_date'])) : 'No payments recorded',
        'DAYS_OVERDUE' => $daysOverdue,
        'PUBLIC_INVOICE_URL' => $publicUrl,
        'ATTACHMENT_NAME' => 'Invoice-' . $invoiceNumber . '.pdf',
        'SUPPORT_EMAIL' => $supportEmail,
        'SENDER_ADDRESS' => is_string($senderAddress) ? $senderAddress : trim((string) ($settings['business_address'] ?? '')),
        'PAYMENT_DETAILS' => $paymentDetailsHtml,
        'PAYMENT_DETAILS_BLOCK' => $balanceCents > 0
            ? invoice_payment_details_html_block($paymentDetailsHtml)
            : '',
        'BALANCE_CENTS' => $balanceCents,
        'TRANSACTIONS_HTML' => $transactionsHtml,
        'TRANSACTIONS_TEXT' => $transactionTextRows !== [] ? implode("\n", $transactionTextRows) : 'No payments recorded.',
        'EXCHANGE_RATE_BLOCK' => $exchangeRateContent['html'],
        'EXCHANGE_RATE_TEXT' => $exchangeRateContent['text'],
    ];
    return [$invoice, $values];
}

function invoice_payment_details_html_block(string $paymentDetails): string
{
    return '<tr><td class="bx-pad" style="padding:24px 40px 8px 40px;">'
        . '<div style="font:bold 15px Arial,Helvetica,sans-serif;color:#664aae;border-bottom:2px solid #13a2db;padding-bottom:4px;margin-bottom:10px;">How to pay</div>'
        . '<div style="font:14px/1.6 Arial,Helvetica,sans-serif;color:#5d6480;">' . $paymentDetails . '</div></td></tr>';
}

function invoice_payment_details_text_lines(array $values): array
{
    if ((int) ($values['BALANCE_CENTS'] ?? 0) <= 0) {
        return [''];
    }
    $paymentDetails = preg_replace('~<br\s*/?>~i', "\n", (string) $values['PAYMENT_DETAILS']) ?? (string) $values['PAYMENT_DETAILS'];
    $paymentDetails = html_entity_decode(strip_tags($paymentDetails), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return ['', 'How to pay', $paymentDetails, ''];
}

function invoice_days_overdue(string $dueDate, ?DateTimeImmutable $today = null): int
{
    $timezone = new DateTimeZone('Africa/Nairobi');
    $today = ($today ?? new DateTimeImmutable('today', $timezone))->setTimezone($timezone)->setTime(0, 0);
    $due = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate, $timezone);
    if (!$due || $due->format('Y-m-d') !== $dueDate) {
        throw new InvalidArgumentException('Invoice due date must be a valid calendar date.');
    }

    return $due < $today ? $due->diff($today)->days : 0;
}

function invoice_email_eligibility(array $invoice, string $type, ?DateTimeImmutable $today = null): array
{
    if (!in_array($type, ['invoice_partial', 'invoice_overdue'], true)) {
        throw new InvalidArgumentException('Unsupported manual invoice email type.');
    }
    $storedStatus = (string) ($invoice['status'] ?? '');
    $status = invoice_status($invoice);
    $totalCents = money_to_cents($invoice['total'] ?? '0');
    $paidCents = money_to_cents($invoice['paid_amount'] ?? '0');
    $hasPublicLink = is_string($invoice['public_token'] ?? null) && $invoice['public_token'] !== '';
    $todayDate = ($today ?? new DateTimeImmutable('today', new DateTimeZone('Africa/Nairobi')))
        ->setTimezone(new DateTimeZone('Africa/Nairobi'))
        ->format('Y-m-d');
    $isVoided = in_array($storedStatus, ['cancelled', 'void', 'draft', 'paid'], true)
        || in_array($status, ['cancelled', 'void', 'draft', 'paid'], true)
        || $paidCents >= $totalCents;

    if ($type === 'invoice_partial') {
        if ($status !== 'partially_paid' || $paidCents <= 0 || $paidCents >= $totalCents) {
            return ['eligible' => false, 'reason' => 'Only for partially paid invoices'];
        }
        if (!$hasPublicLink) {
            return ['eligible' => false, 'reason' => 'This invoice has no active public link'];
        }
        return ['eligible' => true, 'reason' => ''];
    }

    if ($isVoided) {
        return ['eligible' => false, 'reason' => 'Not available for paid, draft, cancelled, or void invoices'];
    }
    if (($invoice['due_date'] ?? '') >= $todayDate) {
        return ['eligible' => false, 'reason' => 'This invoice is not overdue yet'];
    }
    if (!$hasPublicLink) {
        return ['eligible' => false, 'reason' => 'This invoice has no active public link'];
    }
    return ['eligible' => true, 'reason' => ''];
}

function admin_overdue_alert_context(array $invoice, array $admin): array
{
    $settings = company_email_settings(db());
    $invoiceNumber = trim((string) ($invoice['invoice_number'] ?? ''));
    $adminEmail = trim((string) ($admin['email'] ?? ''));
    $invoiceId = filter_var($invoice['id'] ?? null, FILTER_VALIDATE_INT);
    if ($invoiceNumber === '' || $invoiceId === false || $invoiceId === null || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Overdue admin alert data is incomplete.');
    }
    $dueDate = (string) ($invoice['due_date'] ?? '');
    $due = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate, new DateTimeZone('Africa/Nairobi'));
    if (!$due || $due->format('Y-m-d') !== $dueDate) {
        throw new InvalidArgumentException('Invoice due date must be a valid calendar date.');
    }
    $logoUrl = mail_setting('LOGO_URL');
    if ($logoUrl === '' && !empty($settings['company_logo'])) {
        $logoUrl = rtrim(public_invoice_base_url(), '/') . '/assets/uploads/' . rawurlencode((string) $settings['company_logo']);
    }
    if ($logoUrl === '' || !filter_var($logoUrl, FILTER_VALIDATE_URL) || strtolower((string) parse_url($logoUrl, PHP_URL_SCHEME)) !== 'https') {
        throw new InvalidArgumentException('LOGO_URL must be a full public HTTPS URL.');
    }
    $name = preg_split('/\s+/', trim((string) ($admin['name'] ?? '')));
    $adminName = is_array($name) && $name !== [] && $name[0] !== '' ? $name[0] : 'team';
    $baseUrl = public_invoice_base_url();
    $balanceCents = max(0, money_to_cents($invoice['total']) - money_to_cents($invoice['paid_amount'] ?? '0'));
    $invoiceNumber = (string) $invoiceNumber;
    $exchangeRateContent = exchange_rate_email_content($invoice, 'invoice_overdue', $balanceCents, $invoiceNumber);
    $values = [
        'ADMIN_NAME' => $adminName,
        'CLIENT_COMPANY' => (string) ($invoice['customer_name'] ?? ''),
        'COMPANY_NAME' => (string) ($settings['business_name'] ?? ''),
        'LOGO_URL' => $logoUrl,
        'INVOICE_NUMBER' => $invoiceNumber,
        'DUE_DATE' => $due->format('j F Y'),
        'DAYS_OVERDUE' => (string) invoice_days_overdue($dueDate),
        'BALANCE' => email_money($balanceCents, invoice_currency($invoice)),
        'ADMIN_INVOICE_URL' => $baseUrl . '/dashboard/invoice_view.php?id=' . (int) $invoiceId,
        'SENDER_ADDRESS' => preg_replace('/\s+/u', ' ', trim((string) ($settings['business_address'] ?? ''))) ?: '',
        'EXCHANGE_RATE_BLOCK' => $exchangeRateContent['html'],
        'EXCHANGE_RATE_TEXT' => $exchangeRateContent['text'],
    ];
    return [$values, $adminEmail];
}

function runOverdueAdminCheck(?DateTimeImmutable $now = null, bool $manual = false): array
{
    $pdo = db();
    $timezone = new DateTimeZone('Africa/Nairobi');
    $now = ($now ?? new DateTimeImmutable('now', $timezone))->setTimezone($timezone);
    $today = $now->format('Y-m-d');
    $repeatDays = filter_var(mail_setting('ADMIN_OVERDUE_REPEAT_DAYS', '0'), FILTER_VALIDATE_INT);
    $maxAlerts = filter_var(mail_setting('ADMIN_OVERDUE_MAX_ALERTS', '3'), FILTER_VALIDATE_INT);
    $alertTime = mail_setting('ADMIN_OVERDUE_ALERT_TIME', '09:00');
    if ($repeatDays === false || $repeatDays < 0 || $maxAlerts === false || $maxAlerts < 1
        || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $alertTime)) {
        throw new RuntimeException('Admin overdue alert time, repeat days, or maximum alerts configuration is invalid.');
    }
    if (!$manual && $now->format('H:i') < $alertTime) {
        return ['eligible' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'message' => 'The configured alert time has not arrived.'];
    }
    if ((int) $pdo->query("SELECT GET_LOCK('admin_overdue_alert_job', 0)")->fetchColumn() !== 1) {
        return ['eligible' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'message' => 'Another overdue alert check is already running.'];
    }

    $stats = ['eligible' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'message' => ''];
    $startedAt = hrtime(true);
    $hasMore = false;
    try {
        $deployment = $pdo->prepare("SELECT config_date FROM admin_overdue_alert_job_config WHERE config_key='first_deployment_date'");
        $deployment->execute();
        if ($deployment->fetchColumn() === false) {
            throw new RuntimeException('Run database/migrate_admin_overdue_alerts.php before enabling overdue alerts.');
        }
        if (!$manual) {
            $todayStartUtc = (new DateTimeImmutable($today . ' 00:00:00', $timezone))
                ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $claim = $pdo->prepare("UPDATE admin_overdue_alert_run_state SET last_run_at=UTC_TIMESTAMP()
                WHERE state_key='daily' AND last_run_at<?");
            $claim->execute([$todayStartUtc]);
            if ($claim->rowCount() !== 1) {
                $stats['message'] = 'The overdue check has already run today.';
                return $stats;
            }
        }

        $admins = $pdo->query("SELECT id,name,email FROM users
            WHERE receive_overdue_alerts=1 AND email<>'' ORDER BY id")->fetchAll();
        $uniqueAdmins = [];
        foreach ($admins as $admin) {
            $email = trim((string) $admin['email']);
            if ($email !== '') {
                $uniqueAdmins[mb_strtolower($email)] ??= $admin;
            }
        }
        $invoicesQuery = $pdo->prepare("SELECT i.id,i.invoice_number,i.status,i.due_date,i.total,i.currency_code,i.kes_exchange_rate,i.fx_rate_date,
                COALESCE(NULLIF(TRIM(i.company_name_snapshot),''),c.name) customer_name,
                COALESCE(p.paid_amount,0) paid_amount
            FROM invoices i
            JOIN customers c ON c.id=i.customer_id
            LEFT JOIN (SELECT invoice_id,SUM(amount) paid_amount FROM payments GROUP BY invoice_id) p ON p.invoice_id=i.id
            LEFT JOIN admin_overdue_alert_baseline b ON b.invoice_id=i.id
            WHERE i.status NOT IN ('paid','draft','cancelled','void')
              AND i.due_date<?
              AND GREATEST(i.total-COALESCE(p.paid_amount,0),0)>0
              AND (?=1 OR b.invoice_id IS NULL)
            ORDER BY i.due_date,i.id");
        $invoicesQuery->execute([$today, $manual ? 1 : 0]);
        $invoices = $invoicesQuery->fetchAll();
        $stats['eligible'] = count($invoices) * count($uniqueAdmins);

        foreach ($invoices as $invoice) {
            foreach ($uniqueAdmins as $admin) {
                if ($stats['sent'] + $stats['failed'] >= 20 || hrtime(true) - $startedAt >= 30_000_000_000) {
                    $stats['message'] = 'The check stopped at its 20-alert or 30-second run limit; remaining alerts will be considered on the next run.';
                    $hasMore = true;
                    break 2;
                }
                $email = trim((string) $admin['email']);
                $recipientHash = hash('sha256', mb_strtolower($email));
                $alertKey = null;
                $alertSubject = 'Overdue invoice alert';
                try {
                    $currentQuery = $pdo->prepare("SELECT i.id,i.invoice_number,i.status,i.due_date,i.total,i.currency_code,i.kes_exchange_rate,i.fx_rate_date,
                            COALESCE(NULLIF(TRIM(i.company_name_snapshot),''),c.name) customer_name,
                            COALESCE(p.paid_amount,0) paid_amount
                        FROM invoices i JOIN customers c ON c.id=i.customer_id
                        LEFT JOIN (SELECT invoice_id,SUM(amount) paid_amount FROM payments GROUP BY invoice_id) p ON p.invoice_id=i.id
                        WHERE i.id=?");
                    $currentQuery->execute([(int) $invoice['id']]);
                    $current = $currentQuery->fetch();
                    if (!$current || in_array(strtolower((string) $current['status']), ['paid', 'draft', 'cancelled', 'void'], true)
                        || (string) $current['due_date'] >= $today
                        || money_to_cents($current['total']) <= money_to_cents($current['paid_amount'])) {
                        $stats['skipped']++;
                        continue;
                    }

                    $alertKeyPrefix = 'admin-alert:' . (int) $current['id'] . ':' . $recipientHash . ':';
                    $historyQuery = $pdo->prepare("SELECT status,sent_at,created_at FROM email_log
                        WHERE invoice_id=? AND type='admin_overdue_alert' AND idempotency_key LIKE ?
                        ORDER BY created_at DESC,id DESC");
                    $historyQuery->execute([(int) $current['id'], $alertKeyPrefix . '%']);
                    $history = $historyQuery->fetchAll();
                    $sentHistory = array_values(array_filter($history, static fn(array $row): bool => $row['status'] === 'sent'));
                    if (!$manual && $history !== []) {
                        $latest = $history[0];
                        $lastSent = $sentHistory !== []
                            ? new DateTimeImmutable((string) $sentHistory[0]['sent_at'], new DateTimeZone('UTC'))
                            : null;
                        $repeatDue = $repeatDays > 0
                            && count($sentHistory) < $maxAlerts
                            && $latest['status'] === 'sent'
                            && $lastSent !== null
                            && $lastSent->setTimezone($timezone)->modify('+' . $repeatDays . ' days')->format('Y-m-d') <= $today;
                        if (!$repeatDue) {
                            $stats['skipped']++;
                            continue;
                        }
                    }

                    $alertKey = $alertKeyPrefix . (count($history) + 1);
                    [$values, $recipient] = admin_overdue_alert_context($current, $admin);
                    $rendered = render_email_template(
                        $values,
                        dirname(__DIR__) . '/emails/email-admin-overdue-alert.html',
                        EMAIL_ADMIN_OVERDUE_PLACEHOLDERS
                    );
                    $subject = 'Overdue invoice: ' . $values['INVOICE_NUMBER'] . ' (' . $values['CLIENT_COMPANY'] . '), ' . $values['BALANCE'] . ' unpaid';
                    $alertSubject = $subject;
                    $remainingSeconds = max(1, (int) floor((30_000_000_000 - (hrtime(true) - $startedAt)) / 1_000_000_000));
                    $result = sendEmail([
                        'to' => $recipient,
                        'subject' => $subject,
                        'html' => $rendered['html'],
                        'text' => admin_overdue_plain_text($values),
                        'attachments' => [[
                            'invoice_id' => (int) $current['id'],
                            'filename' => 'Invoice-' . (string) $current['invoice_number'] . '.pdf',
                        ]],
                        'timeout' => min(10, $remainingSeconds),
                        'idempotencyKey' => $alertKey,
                        'tags' => ['invoice_id' => (int) $current['id'], 'type' => 'admin_overdue_alert', 'triggered_by' => 'system'],
                    ]);
                    if (($result['status'] ?? '') === 'sent') {
                        $stats['sent']++;
                    } else {
                        $stats['failed']++;
                    }
                } catch (Throwable $exception) {
                    if ($alertKey !== null) {
                        $recorded = $pdo->prepare('SELECT 1 FROM email_log WHERE idempotency_key=?');
                        $recorded->execute([$alertKey]);
                        if (!$recorded->fetchColumn()) {
                            email_log_failure((int) $invoice['id'], 'admin_overdue_alert', $email, $alertSubject, 'system', $exception->getMessage(), $alertKey);
                        }
                    }
                    $stats['failed']++;
                    error_log('Admin overdue alert failed for invoice ' . (int) $invoice['id'] . ' and admin ' . (int) $admin['id'] . ': ' . safe_email_error($exception->getMessage()));
                }
            }
        }
        if ($hasMore) {
            $previousDayStartUtc = (new DateTimeImmutable($today . ' 00:00:00', $timezone))
                ->modify('-1 second')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $saveRun = $pdo->prepare("UPDATE admin_overdue_alert_run_state SET last_run_at=?
                WHERE state_key='daily'");
            $saveRun->execute([$previousDayStartUtc]);
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('admin_overdue_alert_job')");
    }
    return $stats;
}

function resendOverdueAdminAlert(int $invoiceId): array
{
    if ($invoiceId < 1) {
        throw new InvalidArgumentException('Invoice not found.');
    }

    $pdo = db();
    $lockName = 'admin_alert_resend_' . $invoiceId;
    if ((int) $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lockName) . ',0)')->fetchColumn() !== 1) {
        throw new RuntimeException('Another admin alert resend for this invoice is already running.');
    }

    try {
        $previousAlert = $pdo->prepare("SELECT 1 FROM email_log
            WHERE invoice_id=? AND type='admin_overdue_alert' AND status='sent' LIMIT 1");
        $previousAlert->execute([$invoiceId]);
        if (!$previousAlert->fetchColumn()) {
            throw new InvalidArgumentException('This alert has not been sent yet.');
        }

        $invoiceQuery = $pdo->prepare("SELECT i.id,i.invoice_number,i.status,i.due_date,i.total,i.currency_code,i.kes_exchange_rate,i.fx_rate_date,
                COALESCE(NULLIF(TRIM(i.company_name_snapshot),''),c.name) customer_name,
                COALESCE(p.paid_amount,0) paid_amount
            FROM invoices i
            JOIN customers c ON c.id=i.customer_id
            LEFT JOIN (SELECT invoice_id,SUM(amount) paid_amount FROM payments GROUP BY invoice_id) p ON p.invoice_id=i.id
            WHERE i.id=?");
        $invoiceQuery->execute([$invoiceId]);
        $invoice = $invoiceQuery->fetch();
        if (!$invoice) {
            throw new InvalidArgumentException('Invoice not found.');
        }

        $adminRows = $pdo->query("SELECT id,name,email FROM users
            WHERE receive_overdue_alerts=1 AND email<>'' ORDER BY id")->fetchAll();
        $admins = [];
        foreach ($adminRows as $admin) {
            $email = trim((string) $admin['email']);
            if ($email !== '') {
                $admins[mb_strtolower($email)] ??= $admin;
            }
        }
        if ($admins === []) {
            throw new RuntimeException('There are no opted-in administrators to receive this alert.');
        }

        $sent = 0;
        $failed = 0;
        foreach ($admins as $admin) {
            $email = trim((string) $admin['email']);
            $alertKey = 'admin-alert:' . $invoiceId . ':' . hash('sha256', mb_strtolower($email))
                . ':manual:' . bin2hex(random_bytes(8));
            $subject = 'Overdue invoice alert';
            try {
                [$values, $recipient] = admin_overdue_alert_context($invoice, $admin);
                $rendered = render_email_template(
                    $values,
                    dirname(__DIR__) . '/emails/email-admin-overdue-alert.html',
                    EMAIL_ADMIN_OVERDUE_PLACEHOLDERS
                );
                $subject = 'Overdue invoice: ' . $values['INVOICE_NUMBER'] . ' (' . $values['CLIENT_COMPANY'] . '), ' . $values['BALANCE'] . ' unpaid';
                $result = sendEmail([
                    'to' => $recipient,
                    'subject' => $subject,
                    'html' => $rendered['html'],
                    'text' => admin_overdue_plain_text($values),
                    'attachments' => [[
                        'invoice_id' => $invoiceId,
                        'filename' => 'Invoice-' . (string) $invoice['invoice_number'] . '.pdf',
                    ]],
                    'timeout' => 10,
                    'idempotencyKey' => $alertKey,
                    'tags' => ['invoice_id' => $invoiceId, 'type' => 'admin_overdue_alert', 'triggered_by' => 'system'],
                ]);
                if (($result['status'] ?? '') === 'sent') {
                    $sent++;
                } else {
                    $failed++;
                }
            } catch (Throwable $exception) {
                $recorded = $pdo->prepare('SELECT 1 FROM email_log WHERE idempotency_key=?');
                $recorded->execute([$alertKey]);
                if (!$recorded->fetchColumn()) {
                    email_log_failure($invoiceId, 'admin_overdue_alert', $email, $subject, 'system', $exception->getMessage(), $alertKey);
                }
                $failed++;
                error_log('Manual admin overdue alert failed for invoice ' . $invoiceId . ' and admin ' . (int) $admin['id'] . ': ' . safe_email_error($exception->getMessage()));
            }
        }

        return [
            'sent' => $sent,
            'failed' => $failed,
            'message' => $failed === 0
                ? 'Alert sent to all opted-in administrators.'
                : 'The alert could not be sent to all administrators. Check the invoice email log and settings.',
        ];
    } finally {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockName) . ')');
    }
}

function email_money(int $amountCents, ?string $currency = null): string
{
    $whole = intdiv($amountCents, 100);
    $fraction = $amountCents % 100;
    return ($currency ?? currency_code()) . ' ' . number_format($whole, 0, '.', ',') . ($fraction !== 0 ? '.' . str_pad((string) $fraction, 2, '0', STR_PAD_LEFT) : '');
}

function sendInvoiceEmail(int $invoiceId, string $type, string $triggeredBy, bool $manualResend = false): array
{
    if (in_array($type, ['invoice_partial', 'invoice_overdue'], true) && $triggeredBy === 'system') {
        throw new InvalidArgumentException('Partial-payment and overdue emails can only be sent by a staff action.');
    }
    if (in_array($type, ['invoice_partial', 'invoice_overdue'], true)) {
        $staff = current_user();
        if ($staff === null || !can_send_invoice_emails((int) $staff['id'])) {
            throw new InvalidArgumentException('You do not have permission to send invoice emails.');
        }
        $triggeredBy = (string) $staff['name'];
    }
    $pdo = db();
    if ($pdo->inTransaction()) {
        throw new LogicException('Invoice emails must be sent outside an existing database transaction.');
    }
    $deliveryLock = 'breax_invoice_email_' . $invoiceId;
    if ((int) $pdo->query('SELECT GET_LOCK(' . $pdo->quote($deliveryLock) . ',0)')->fetchColumn() !== 1) {
        return ['id' => null, 'status' => 'failed', 'message' => 'Another send for this invoice is in progress; this request was not sent.'];
    }
    try {
        $pdo->beginTransaction();
        $result = send_invoice_email_in_transaction($pdo, $invoiceId, $type, $triggeredBy, $manualResend);
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        if (!empty($result['_deliver_now']) && isset($result['id'], $result['_delivery_message'])) {
            $message = $result['_delivery_message'];
            unset($result['_deliver_now'], $result['_delivery_message']);
            return deliver_email_log_entry((int) $result['id'], $message);
        }
        return $result;
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        throw $exception;
    } finally {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($deliveryLock) . ')');
    }
}

function send_invoice_email_in_transaction(PDO $pdo, int $invoiceId, string $type, string $triggeredBy, bool $manualResend = false): array
{
    if (!in_array($type, array_merge(INVOICE_EMAIL_TYPES, ['payment_received']), true)) {
        throw new InvalidArgumentException('Unsupported invoice email type.');
    }
    if ($triggeredBy !== 'system' && trim($triggeredBy) === '') {
        throw new InvalidArgumentException('The staff user who triggered the email is required.');
    }
    $lock = $pdo->prepare('SELECT id FROM invoices WHERE id=? FOR UPDATE');
    $lock->execute([$invoiceId]);
    if (!$lock->fetchColumn()) {
        throw new InvalidArgumentException('Invoice not found.');
    }
    $invoiceQuery = $pdo->prepare("SELECT i.*,COALESCE(NULLIF(TRIM(i.recipient_email),''),c.email) invoice_recipient,
        COALESCE(p.paid_amount,0) paid_amount FROM invoices i JOIN customers c ON c.id=i.customer_id
        LEFT JOIN (SELECT invoice_id,SUM(amount) paid_amount FROM payments GROUP BY invoice_id) p ON p.invoice_id=i.id WHERE i.id=?");
    $invoiceQuery->execute([$invoiceId]);
    $invoice = $invoiceQuery->fetch();
    if (!$invoice) {
        throw new InvalidArgumentException('Invoice not found.');
    }
    if ($type === 'invoice_issued') {
        $existing = $pdo->prepare("SELECT id,status,recipient,error FROM email_log
            WHERE invoice_id=? AND type='invoice_issued' ORDER BY created_at DESC,id DESC LIMIT 1");
        $existing->execute([$invoiceId]);
        $previousEmail = $existing->fetch();
        if ($previousEmail && $previousEmail['status'] === 'sent' && !$manualResend) {
            return [
                'id' => (int) $previousEmail['id'],
                'status' => 'sent',
                'recipient' => (string) ($previousEmail['recipient'] ?? ''),
                'message' => 'This invoice email has already been sent.',
            ];
        }
        if ($previousEmail && !$manualResend) {
            return [
                'id' => (int) $previousEmail['id'],
                'status' => 'failed',
                'recipient' => (string) ($previousEmail['recipient'] ?? ''),
                'message' => (string) ($previousEmail['error'] ?: 'The previous email failed. Use Send again to make a new attempt.'),
            ];
        }
    }
    $idempotencyKey = null;
    $status = invoice_status($invoice);
    $today = (new DateTimeImmutable('today', new DateTimeZone('Africa/Nairobi')))->format('Y-m-d');
    if ($type === 'invoice_issued' && (in_array($status, ['draft', 'cancelled'], true) || empty($invoice['public_token']))) {
        throw new InvalidArgumentException('An issued invoice email requires an issued invoice and an active public link.');
    }
    if ($type === 'payment_received' && (
        in_array($status, ['draft', 'cancelled'], true) || empty($invoice['public_token'])
    )) {
        throw new InvalidArgumentException('A payment receipt email requires an issued invoice and an active public link.');
    }
    if ($type === 'invoice_partial' && (
        !invoice_email_eligibility($invoice, 'invoice_partial')['eligible']
    )) {
        $eligibility = invoice_email_eligibility($invoice, 'invoice_partial');
        throw new InvalidArgumentException($eligibility['reason'] !== ''
            ? $eligibility['reason']
            : 'A partial-payment email requires a partially paid invoice with an outstanding balance and an active public link.');
    }
    if ($type === 'invoice_overdue' && !invoice_email_eligibility($invoice, 'invoice_overdue')['eligible']) {
        $eligibility = invoice_email_eligibility($invoice, 'invoice_overdue');
        throw new InvalidArgumentException($eligibility['reason'] !== ''
            ? $eligibility['reason']
            : 'An overdue email requires an unpaid issued invoice, an active public link, and a due date before today.');
    }

    $recipient = trim((string) ($invoice['invoice_recipient'] ?? ''));
    $subject = match ($type) {
        'invoice_issued' => 'Invoice ' . $invoice['invoice_number'],
        'invoice_partial' => 'Payment received for invoice ' . $invoice['invoice_number'],
        'payment_received' => 'Payment received for invoice ' . $invoice['invoice_number'],
        'invoice_overdue' => 'Overdue: invoice ' . $invoice['invoice_number'],
        default => 'Invoice ' . $invoice['invoice_number'],
    };
    if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        email_log_failure($invoiceId, $type, $recipient !== '' ? $recipient : null, $subject, $triggeredBy, 'The invoice client billing email is missing or invalid.', $idempotencyKey);
        throw new InvalidArgumentException('The client billing email is missing or invalid; update the client record before sending.');
    }
    $blocked = $pdo->prepare('SELECT 1 FROM email_bounced_addresses WHERE address=?');
    $blocked->execute([mb_strtolower($recipient)]);
    if ($blocked->fetchColumn()) {
        email_log_failure($invoiceId, $type, $recipient, $subject, $triggeredBy, 'This address previously received a permanent SMTP rejection. Update the client email address before sending.', $idempotencyKey);
        throw new InvalidArgumentException('This billing address previously bounced. Update it before sending another email.');
    }
    try {
        [, $values] = invoice_email_context($pdo, $invoiceId, $type);
        if ($type === 'invoice_issued') {
            $templatePath = dirname(__DIR__) . '/emails/email-invoice-issued.html';
            $rendered = render_email_template($values, $templatePath, EMAIL_ISSUED_PLACEHOLDERS);
            $subject = 'Invoice ' . $values['INVOICE_NUMBER'] . ' from ' . $values['COMPANY_NAME']
                . ': ' . $values['TOTAL'] . ' due ' . $values['DUE_DATE'];
            $rendered['text'] = invoice_issued_plain_text($values);
        } elseif ($type === 'invoice_partial' || ($type === 'payment_received' && money_to_cents($invoice['paid_amount']) < money_to_cents($invoice['total']))) {
            $templatePath = dirname(__DIR__) . '/emails/email-invoice-partially-paid.html';
            $rendered = render_email_template($values, $templatePath, EMAIL_PARTIAL_PLACEHOLDERS);
            $subject = 'Payment received for invoice ' . $values['INVOICE_NUMBER'] . ': ' . $values['BALANCE'] . ' remaining';
            $rendered['text'] = invoice_partial_plain_text($values);
        } elseif ($type === 'payment_received') {
            $templatePath = dirname(__DIR__) . '/emails/email-invoice-paid.html';
            $rendered = render_email_template($values, $templatePath, EMAIL_PAID_PLACEHOLDERS);
            $subject = 'Invoice ' . $values['INVOICE_NUMBER'] . ' is paid in full';
            $rendered['text'] = invoice_paid_plain_text($values);
        } elseif ($type === 'invoice_overdue') {
            $templatePath = dirname(__DIR__) . '/emails/email-invoice-overdue.html';
            $rendered = render_email_template($values, $templatePath, EMAIL_OVERDUE_PLACEHOLDERS);
            $subject = 'Overdue: invoice ' . $values['INVOICE_NUMBER'] . ' was due ' . $values['DUE_DATE'];
            $rendered['text'] = invoice_overdue_plain_text($values);
        } else {
            $rendered = render_email_template($values);
        }
    } catch (Throwable $exception) {
        email_log_failure($invoiceId, $type, $recipient, $subject, $triggeredBy, $exception->getMessage(), $idempotencyKey);
        throw $exception;
    }

    $result = sendEmail([
        'to' => $recipient,
        'cc' => [],
        'subject' => $subject,
        'html' => $rendered['html'],
        'text' => $rendered['text'],
        'attachments' => [['invoice_id' => $invoiceId, 'filename' => 'Invoice-' . $invoice['invoice_number'] . '.pdf']],
        'idempotencyKey' => $idempotencyKey,
        'tags' => ['invoice_id' => $invoiceId, 'type' => $type, 'triggered_by' => $triggeredBy],
    ]);
    $result['recipient'] = $recipient;
    return $result;
}

function invoice_issued_email_notice(array $result): array
{
    $recipient = trim((string) ($result['recipient'] ?? ''));
    $status = (string) ($result['status'] ?? '');
    if ($status === 'sent' && $recipient !== '') {
        return [
            'message' => 'Invoice issued and an email with invoice details has been sent to ' . $recipient . '.',
            'type' => 'success',
        ];
    }
    $reason = trim((string) ($result['message'] ?? ''));
    return [
        'message' => 'Invoice issued, but its email was not sent' . ($recipient !== '' ? ' to ' . $recipient : '')
            . ($reason !== '' ? ': ' . $reason : '.'),
        'type' => 'error',
    ];
}

function payment_received_email_notice(array $result): array
{
    $recipient = trim((string) ($result['recipient'] ?? ''));
    if (($result['status'] ?? '') === 'sent' && $recipient !== '') {
        return [
            'message' => 'The customer payment confirmation email was sent to ' . $recipient . '.',
            'type' => 'success',
        ];
    }
    $reason = trim((string) ($result['message'] ?? ''));
    return [
        'message' => 'Payment was recorded, but the customer confirmation email was not sent'
            . ($recipient !== '' ? ' to ' . $recipient : '')
            . ($reason !== '' ? ': ' . $reason : '.'),
        'type' => 'error',
    ];
}

function invoice_issued_plain_text(array $values): string
{
    return implode("\n", [
        'Your invoice is ready',
        '',
        'Hello ' . $values['CLIENT_NAME'] . ', thank you for your business. Invoice ' . $values['INVOICE_NUMBER']
            . ' for ' . $values['TOTAL'] . ' is attached to this email as a PDF. You can also view it online at any time using the button below.',
        '',
        'Invoice number: ' . $values['INVOICE_NUMBER'],
        'Issue date: ' . $values['ISSUE_DATE'],
        'Due date: ' . $values['DUE_DATE'],
        'Total due: ' . $values['TOTAL'],
        '',
        ...exchange_rate_plain_text_lines($values),
        'View invoice online: ' . $values['PUBLIC_INVOICE_URL'],
        '',
        'Attached: ' . $values['ATTACHMENT_NAME'] . ' (PDF)',
        ...invoice_payment_details_text_lines($values),
        'Questions about this invoice? Reply to this email or contact us at ' . $values['SUPPORT_EMAIL'] . '.',
        '',
        'Kind regards,',
        $values['COMPANY_NAME'],
        '',
        $values['COMPANY_NAME'] . ' | ' . $values['SENDER_ADDRESS'],
        'You are receiving this email because an invoice was issued to ' . $values['CLIENT_EMAIL'] . '.',
    ]);
}

function invoice_partial_plain_text(array $values): string
{
    return implode("\n", [
        'Thank you for your payment',
        '',
        'Hello ' . $values['CLIENT_NAME'] . ', we received your payment on ' . $values['LAST_PAYMENT_DATE']
            . '. A balance of ' . $values['BALANCE'] . ' remains on invoice ' . $values['INVOICE_NUMBER']
            . ', due on ' . $values['DUE_DATE'] . '. A copy of the invoice showing your payments is attached.',
        '',
        'Invoice number: ' . $values['INVOICE_NUMBER'],
        'Due date: ' . $values['DUE_DATE'],
        'Invoice total: ' . $values['TOTAL'],
        'Amount paid: ' . $values['AMOUNT_PAID'],
        'Balance due: ' . $values['BALANCE'],
        '',
        'Transactions',
        $values['TRANSACTIONS_TEXT'],
        '',
        ...exchange_rate_plain_text_lines($values),
        'View invoice online: ' . $values['PUBLIC_INVOICE_URL'],
        '',
        'Attached: ' . $values['ATTACHMENT_NAME'] . ' (PDF)',
        ...invoice_payment_details_text_lines($values),
        'Questions about this invoice? Reply to this email or contact us at ' . $values['SUPPORT_EMAIL'] . '.',
        '',
        'Kind regards,',
        $values['COMPANY_NAME'],
        '',
        $values['COMPANY_NAME'] . ' | ' . $values['SENDER_ADDRESS'],
        'You are receiving this email because an invoice was issued to ' . $values['CLIENT_EMAIL'] . '.',
    ]);
}

function invoice_paid_plain_text(array $values): string
{
    return implode("\n", [
        'Thank you — your invoice is paid',
        '',
        'Hello ' . $values['CLIENT_NAME'] . ', we received your final payment on ' . $values['LAST_PAYMENT_DATE']
            . '. Invoice ' . $values['INVOICE_NUMBER'] . ' has been paid in full. Thank you for your business. '
            . 'A copy of the paid invoice is attached.',
        '',
        'Invoice number: ' . $values['INVOICE_NUMBER'],
        'Due date: ' . $values['DUE_DATE'],
        'Invoice total: ' . $values['TOTAL'],
        'Amount paid: ' . $values['AMOUNT_PAID'],
        'Balance due: ' . $values['BALANCE'],
        '',
        'Transactions',
        $values['TRANSACTIONS_TEXT'],
        '',
        ...exchange_rate_plain_text_lines($values),
        'View paid invoice online: ' . $values['PUBLIC_INVOICE_URL'],
        '',
        'Attached: ' . $values['ATTACHMENT_NAME'] . ' (PDF)',
        '',
        'Questions about this invoice? Reply to this email or contact us at ' . $values['SUPPORT_EMAIL'] . '.',
        '',
        'Kind regards,',
        $values['COMPANY_NAME'],
        '',
        $values['COMPANY_NAME'] . ' | ' . $values['SENDER_ADDRESS'],
        'You are receiving this email because invoice ' . $values['INVOICE_NUMBER'] . ' was paid to ' . $values['COMPANY_NAME'] . '.',
    ]);
}

function invoice_overdue_plain_text(array $values): string
{
    return implode("\n", [
        'Invoice ' . $values['INVOICE_NUMBER'] . ' is overdue',
        '',
        'Hello ' . $values['CLIENT_NAME'] . ', our records show that invoice ' . $values['INVOICE_NUMBER']
            . ' was due on ' . $values['DUE_DATE'] . ' and ' . $values['BALANCE'] . ' is still unpaid. '
            . 'If you have already paid, please ignore this message and accept our thanks. Otherwise, '
            . 'please arrange payment as soon as possible. The invoice is attached for your convenience.',
        '',
        'Invoice number: ' . $values['INVOICE_NUMBER'],
        'Original due date: ' . $values['DUE_DATE'],
        'Days overdue: ' . $values['DAYS_OVERDUE'],
        'Amount due: ' . $values['BALANCE'],
        '',
        ...exchange_rate_plain_text_lines($values),
        'View invoice online: ' . $values['PUBLIC_INVOICE_URL'],
        '',
        'Attached: ' . $values['ATTACHMENT_NAME'] . ' (PDF)',
        ...invoice_payment_details_text_lines($values),
        'Questions about this invoice? Reply to this email or contact us at ' . $values['SUPPORT_EMAIL'] . '.',
        '',
        'Kind regards,',
        $values['COMPANY_NAME'],
        '',
        $values['COMPANY_NAME'] . ' | ' . $values['SENDER_ADDRESS'],
        'You are receiving this email because an invoice was issued to ' . $values['CLIENT_EMAIL'] . '.',
    ]);
}

function exchange_rate_plain_text_lines(array $values): array
{
    $text = (string) ($values['EXCHANGE_RATE_TEXT'] ?? '');
    return $text === '' ? [] : explode("\n", $text);
}

function admin_overdue_plain_text(array $values): string
{
    return implode("\n", [
        'Invoice ' . $values['INVOICE_NUMBER'] . ' is now overdue',
        '',
        'Hello ' . $values['ADMIN_NAME'] . ', invoice ' . $values['INVOICE_NUMBER'] . ' for '
            . $values['CLIENT_COMPANY'] . ' was due on ' . $values['DUE_DATE'] . ' and is now overdue. '
            . $values['BALANCE'] . ' is still unpaid. Open the invoice to review it, and send the client an overdue reminder if you want to.',
        '',
        'Client: ' . $values['CLIENT_COMPANY'],
        'Invoice number: ' . $values['INVOICE_NUMBER'],
        'Due date: ' . $values['DUE_DATE'],
        'Days overdue: ' . $values['DAYS_OVERDUE'],
        'Amount due: ' . $values['BALANCE'],
        '',
        ...exchange_rate_plain_text_lines($values),
        'Open invoice: ' . $values['ADMIN_INVOICE_URL'],
        '',
        'This is an automatic alert for staff. No message has been sent to the client. To remind them, open the invoice and use the "Send overdue reminder" button.',
        '',
        $values['COMPANY_NAME'] . ' | ' . $values['SENDER_ADDRESS'],
        'You are receiving this alert because you are an admin of the invoicing system.',
    ]);
}

function renewal_reminder_recipients(PDO $pdo): array
{
    $setting = $pdo->prepare("SELECT setting_value FROM company_settings WHERE setting_key='renewal_reminder_recipients'");
    $setting->execute();
    $stored = $setting->fetchColumn();
    if (!is_string($stored) || trim($stored) === '') {
        $stored = (string) $pdo->query('SELECT email FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
    }
    $recipients = [];
    $seen = [];
    foreach (array_map('trim', explode(',', (string) $stored)) as $address) {
        if ($address === '' || isset($seen[strtolower($address)])) {
            continue;
        }
        $seen[strtolower($address)] = true;
        $recipients[] = $address;
    }
    if ($recipients === []) {
        throw new RuntimeException('Set at least one renewal reminder recipient in Company settings.');
    }
    foreach ($recipients as $recipient) {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A configured renewal reminder recipient address is invalid.');
        }
    }
    return $recipients;
}

function sendEmail(array $message): array
{
    $to = is_string($message['to'] ?? null) ? trim($message['to']) : '';
    $tags = is_array($message['tags'] ?? null) ? $message['tags'] : [];
    $adminReportTypes = ['admin_overdue_alert', 'admin_domain_expiry_report', 'admin_overdue_invoice_report', 'admin_google_workspace_report', 'admin_renewal_reminder_digest'];
    $allowedTypes = array_merge(INVOICE_EMAIL_TYPES, ['payment_received'], $adminReportTypes, ['template_test']);
    $type = in_array($tags['type'] ?? '', $allowedTypes, true) ? $tags['type'] : 'invoice_issued';
    $invoiceId = isset($tags['invoice_id']) ? (int) $tags['invoice_id'] : null;
    $adminRecipient = $to;
    $isAdminReport = in_array($type, $adminReportTypes, true);
    $triggeredBy = $isAdminReport
        ? 'system'
        : (is_string($tags['triggered_by'] ?? null) ? $tags['triggered_by'] : 'system');
    $subject = is_string($message['subject'] ?? null) ? trim($message['subject']) : '';
    $key = is_string($message['idempotencyKey'] ?? null) ? $message['idempotencyKey'] : null;
    email_mode();
    if ($isAdminReport) {
        $mailMode = strtolower(mail_setting('MAIL_MODE', 'live'));
        if (!in_array($mailMode, ['log', 'sandbox', 'live'], true)) {
            throw new RuntimeException('MAIL_MODE must be log, sandbox, or live.');
        }
        if ($mailMode === 'log') {
            email_log_failure($invoiceId, $type, $adminRecipient ?: null, $subject ?: 'Unspecified email', 'system', 'MAIL_MODE=log is enabled; this admin report was recorded but not sent.', $key);
            return ['id' => null, 'status' => 'failed', 'message' => 'MAIL_MODE=log is enabled; the report was recorded but not sent.'];
        }
        if ($mailMode === 'sandbox' && $type === 'admin_overdue_alert') {
            $sandboxRecipient = mail_setting('MAIL_TEST_ADDRESS');
            if (!filter_var($sandboxRecipient, FILTER_VALIDATE_EMAIL)) {
                email_log_failure($invoiceId, $type, $adminRecipient ?: null, $subject ?: 'Unspecified email', 'system', 'MAIL_MODE=sandbox requires a valid MAIL_TEST_ADDRESS.', $key);
                return ['id' => null, 'status' => 'failed', 'message' => 'MAIL_MODE=sandbox requires a valid MAIL_TEST_ADDRESS.'];
            }
            $to = $sandboxRecipient;
        } elseif ($mailMode === 'sandbox' && in_array($type, ['admin_domain_expiry_report', 'admin_overdue_invoice_report', 'admin_google_workspace_report', 'admin_renewal_reminder_digest'], true)) {
            email_log_failure($invoiceId, $type, $adminRecipient ?: null, $subject ?: 'Unspecified email', 'system', 'Admin reports require MAIL_MODE=live to keep delivery limited to opted-in administrators.', $key);
            return ['id' => null, 'status' => 'failed', 'message' => 'Admin reports require MAIL_MODE=live to keep delivery limited to opted-in administrators.'];
        }
    }
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        email_log_failure($invoiceId, $type, $to ?: null, $subject ?: 'Unspecified email', $triggeredBy, 'The recipient address is missing or invalid.', $key);
        throw new InvalidArgumentException('Enter a valid email recipient.');
    }
    $pdo = db();
    if ($type === 'admin_renewal_reminder_digest') {
        $configuredRecipients = renewal_reminder_recipients($pdo);
        $rawCc = is_array($message['cc'] ?? null)
            ? $message['cc']
            : (is_string($message['cc'] ?? null) && trim($message['cc']) !== '' ? [trim($message['cc'])] : []);
        $requestedRecipients = array_merge([$adminRecipient], array_map(
            static fn(mixed $address): string => is_string($address) ? trim($address) : '',
            $rawCc
        ));
        if (array_map('strtolower', $requestedRecipients) !== array_map('strtolower', $configuredRecipients)) {
            email_log_failure($invoiceId, $type, $to, $subject ?: 'Unspecified email', 'system', 'Renewal reminders may only be sent to the configured administrator recipients.', $key);
            throw new InvalidArgumentException('Renewal reminders may only be sent to the configured administrator recipients.');
        }
    } elseif ($isAdminReport) {
        $eligibleRecipient = $pdo->prepare('SELECT 1 FROM users WHERE receive_overdue_alerts=1 AND LOWER(email)=LOWER(?) LIMIT 1');
        $eligibleRecipient->execute([$adminRecipient]);
        if (($type === 'admin_overdue_alert' && $invoiceId === null) || !$eligibleRecipient->fetchColumn()) {
            email_log_failure($invoiceId, $type, $to, $subject ?: 'Unspecified email', 'system', 'Admin reports may only be sent to an opted-in admin account.', $key);
            throw new InvalidArgumentException('Admin reports may only be sent to an opted-in admin account.');
        }
    }
    $blocked = $pdo->prepare('SELECT 1 FROM email_bounced_addresses WHERE address=?');
    $blocked->execute([mb_strtolower($to)]);
    if ($blocked->fetchColumn()) {
        email_log_failure($invoiceId, $type, $to, $subject ?: 'Unspecified email', $triggeredBy, 'This address previously received a permanent SMTP rejection.', $key);
        throw new InvalidArgumentException('This recipient address previously bounced and is blocked.');
    }
    if ($subject === '' || preg_match('/[\r\n]/', $subject) || !is_string($message['html'] ?? null) || !is_string($message['text'] ?? null)) {
        email_log_failure($invoiceId, $type, $to, $subject ?: 'Unspecified email', $triggeredBy, 'Email subject and rendered HTML and text bodies are required.', $key);
        throw new InvalidArgumentException('Email subject and rendered HTML and text bodies are required.');
    }
    $attachments = is_array($message['attachments'] ?? null) ? $message['attachments'] : [];
    if ($type === 'admin_overdue_alert'
        && ($invoiceId === null || count($attachments) !== 1 || !is_array($attachments[0] ?? null)
            || (int) ($attachments[0]['invoice_id'] ?? 0) !== $invoiceId)) {
        email_log_failure($invoiceId, $type, $to, $subject, 'system', 'Admin overdue alerts require the matching invoice PDF attachment.', $key);
        throw new InvalidArgumentException('Admin overdue alerts require the matching invoice PDF attachment.');
    }
    if (in_array($type, ['admin_domain_expiry_report', 'admin_overdue_invoice_report', 'admin_google_workspace_report'], true)) {
        $reportAttachment = $attachments[0] ?? null;
        $validReportAttachment = count($attachments) === 1
            && is_array($reportAttachment)
            && is_string($reportAttachment['filename'] ?? null)
            && str_ends_with(strtolower($reportAttachment['filename']), '.pdf')
            && ($reportAttachment['mime'] ?? null) === 'application/pdf'
            && is_string($reportAttachment['content'] ?? null)
            && str_starts_with($reportAttachment['content'], '%PDF-');
        if (!$validReportAttachment) {
            $error = match ($type) {
                'admin_domain_expiry_report' => 'Domain renewal reports require exactly one valid PDF attachment.',
                'admin_google_workspace_report' => 'Google Workspace renewal reports require exactly one valid PDF attachment.',
                default => 'Overdue invoice reports require exactly one valid consolidated PDF attachment.',
            };
            email_log_failure($invoiceId, $type, $to, $subject, 'system', $error, $key);
            throw new InvalidArgumentException($error);
        }
    }
    validate_email_attachments($attachments, strlen($message['html']) + strlen($message['text']));
    $cc = [];
    $configuredCc = $isAdminReport ? '' : mail_setting('MAIL_CC');
    $messageCc = $type === 'admin_renewal_reminder_digest'
        ? (is_array($message['cc'] ?? null)
            ? $message['cc']
            : (is_string($message['cc'] ?? null) && trim($message['cc']) !== '' ? [trim($message['cc'])] : []))
        : ($isAdminReport ? [] : (is_array($message['cc'] ?? null)
            ? $message['cc']
            : (is_string($message['cc'] ?? null) && trim($message['cc']) !== '' ? [trim($message['cc'])] : [])));
    foreach (array_merge($messageCc, $configuredCc !== '' ? [$configuredCc] : []) as $address) {
        if (!is_string($address) || !filter_var(trim($address), FILTER_VALIDATE_EMAIL)) {
            email_log_failure($invoiceId, $type, $to, $subject, $triggeredBy, 'A configured email CC address is invalid.', $key);
            throw new InvalidArgumentException('A configured email CC address is invalid.');
        }
        $cc[] = trim($address);
    }
    $cc = array_values(array_unique($cc));
    foreach ($cc as $ccAddress) {
        $blockedCc = $pdo->prepare('SELECT 1 FROM email_bounced_addresses WHERE address=?');
        $blockedCc->execute([mb_strtolower($ccAddress)]);
        if ($blockedCc->fetchColumn()) {
            email_log_failure($invoiceId, $type, $to, $subject ?: 'Unspecified email', $triggeredBy, 'A configured CC address previously received a permanent SMTP rejection.', $key);
            throw new InvalidArgumentException('A configured CC address previously bounced and is blocked.');
        }
    }
    $previewHtml = preg_replace('~(/i/)[A-Za-z0-9_-]{43}~', '$1[redacted]', $message['html']) ?? $message['html'];
    $previewText = preg_replace('~(/i/)[A-Za-z0-9_-]{43}~', '$1[redacted]', $message['text']) ?? $message['text'];
    $createdAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    $insert = $pdo->prepare("INSERT INTO email_log
        (invoice_id,type,recipient,subject,status,error,triggered_by,created_at,cc,html_body,text_body,idempotency_key)
        VALUES (?,?,?,?, 'failed','Delivery did not complete.',?,?,?,?,?,?)");
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $insert->execute([
            $invoiceId,
            $type,
            $to,
            mb_substr($subject, 0, 255),
            mb_substr($triggeredBy, 0, 190),
            $createdAt,
            implode(',', $cc) ?: null,
            $previewHtml,
            $previewText,
            $key,
        ]);
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    $entryId = (int) $pdo->lastInsertId();
    if ($ownsTransaction) {
        $pdo->commit();
    }
    if ($ownsTransaction) {
        return deliver_email_log_entry($entryId, $message);
    }
    return ['id' => $entryId, 'status' => 'failed', 'recipient' => $to, '_deliver_now' => true, '_delivery_message' => $message];
}

function deliver_email_log_entry(int $entryId, array $message): array
{
    $pdo = db();
    $query = $pdo->prepare('SELECT id,invoice_id,type,recipient,subject,triggered_by,cc,html_body,text_body FROM email_log WHERE id=?');
    $query->execute([$entryId]);
    $entry = $query->fetch();
    if (!$entry) {
        throw new RuntimeException('The email log entry disappeared before delivery.');
    }
    $delivery = deliver_email_message($pdo, $entry, $message);
    return [
        'id' => $entryId,
        'status' => $delivery['status'],
        'recipient' => (string) $entry['recipient'],
        'message' => $delivery['message'],
    ];
}

function validate_email_attachments(array $attachments, int $bodyBytes): void
{
    $attachmentBytes = 0;
    foreach ($attachments as $attachment) {
        if (!is_array($attachment) || isset($attachment['invoice_id']) || !empty($attachment['sample_invoice'])) {
            continue;
        }
        if (!isset($attachment['filename'], $attachment['content']) || !is_string($attachment['filename']) || !is_string($attachment['content'])) {
            throw new InvalidArgumentException('Each attachment must include a filename and string content.');
        }
        if ($attachment['filename'] === '' || preg_match('/[\x00-\x1F\/\\\\]/', $attachment['filename'])) {
            throw new InvalidArgumentException('An attachment filename is invalid.');
        }
        $mime = is_string($attachment['mime'] ?? null) ? $attachment['mime'] : 'application/octet-stream';
        if (!preg_match('~\A[a-z0-9.+-]+/[a-z0-9.+-]+\z~i', $mime)) {
            throw new InvalidArgumentException('An attachment MIME type is invalid.');
        }
        $attachmentBytes += strlen($attachment['content']);
    }
    if ($attachmentBytes > 24 * 1024 * 1024 || $bodyBytes + (int) ceil($attachmentBytes * 1.34) > 25 * 1024 * 1024) {
        throw new InvalidArgumentException('Email content and attachments exceed the 25 MB message limit.');
    }
}

function generateInvoicePdf(int $invoiceId): array
{
    $pdo = db();
    $query = $pdo->prepare("SELECT i.*,c.name customer_name,c.contact_person,c.email customer_email,c.phone,c.billing_address,COALESCE(p.paid_amount,0) paid_amount FROM invoices i JOIN customers c ON c.id=i.customer_id LEFT JOIN (SELECT invoice_id,SUM(amount) paid_amount FROM payments GROUP BY invoice_id) p ON p.invoice_id=i.id WHERE i.id=?");
    $query->execute([$invoiceId]);
    $invoice = $query->fetch();
    if (!$invoice) {
        throw new RuntimeException('Invoice PDF could not be generated because the invoice no longer exists.');
    }
    apply_invoice_party_snapshot($invoice);
    $itemsQuery = $pdo->prepare('SELECT ii.description,ii.quantity,ii.unit_price,ii.line_total,p.name catalogue_name,p.category catalogue_category,p.billing_cycle catalogue_billing_cycle FROM invoice_items ii LEFT JOIN products p ON p.id=ii.product_id WHERE ii.invoice_id=? ORDER BY ii.id');
    $itemsQuery->execute([$invoiceId]);
    $items = $itemsQuery->fetchAll();
    $paymentsQuery = $pdo->prepare('SELECT amount,payment_date,method,reference FROM payments WHERE invoice_id=? ORDER BY payment_date ASC,id ASC');
    $paymentsQuery->execute([$invoiceId]);
    $payments = $paymentsQuery->fetchAll();
    $hasPayments = count($payments) > 0;
    $paymentsTotalCents = array_reduce($payments, static fn(int $total, array $payment): int => $total + money_to_cents($payment['amount']), 0);
    $balanceCents = max(0, money_to_cents($invoice['total']) - $paymentsTotalCents);
    $company = company_email_settings($pdo);
    $logoFilename = (string) ($company['company_logo'] ?? '');
    $logoUrl = invoice_pdf_logo_data_uri($company);
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
    if (!empty($invoice['public_token'])) {
        $publicInvoiceUrl = public_invoice_url((string) $invoice['public_token']);
    }
    $paymentTerms = trim((string) ($invoice['payment_terms'] ?? ''));
    if ($paymentTerms === 'Payment due within 30 days.') {
        $paymentTerms = '';
    }
    $invoicePaymentTerms = trim((string) ($invoice['notes'] ?? ''));
    if ($invoicePaymentTerms === '') {
        $invoicePaymentTerms = trim((string) ($company['payment_terms'] ?? ''));
    }
    $discountCents = money_to_cents($invoice['subtotal']) - money_to_cents($invoice['total']) + money_to_cents($invoice['tax_amount']);
    return render_invoice_pdf_document(
        $invoice, $items, $payments, $hasPayments, $paymentsTotalCents, $balanceCents,
        $company, $logoUrl, $status, $statusKey, $statusLabel, $publicInvoiceUrl,
        $paymentTerms, $invoicePaymentTerms, $discountCents
    );
}

function create_sample_invoice_pdf(
    string $invoiceNumber = 'INV-SAMPLE-0001',
    array $payments = [],
    ?string $issueDate = null,
    ?string $dueDate = null,
    string $clientName = 'Sample Client',
    string $currency = 'KES',
    ?string $kesExchangeRate = null,
    ?string $fxRateDate = null
): array
{
    if (!preg_match('/\A[A-Za-z0-9_-]{1,80}\z/', $invoiceNumber)) {
        throw new InvalidArgumentException('Sample invoice number is invalid.');
    }
    $currency = strtoupper($currency);
    if (!in_array($currency, supported_invoice_currencies(), true)) {
        throw new InvalidArgumentException('Sample invoice currency is not supported.');
    }
    if ($currency !== 'KES' && (!is_numeric($kesExchangeRate) || (float) $kesExchangeRate <= 0
        || !is_string($fxRateDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fxRateDate))) {
        throw new InvalidArgumentException('Sample invoice exchange rate is invalid.');
    }
    $today = (new DateTimeImmutable('today', new DateTimeZone('Africa/Nairobi')))->format('Y-m-d');
    $company = company_email_settings(db());
    $paymentsTotalCents = array_reduce(
        $payments,
        static fn(int $total, array $payment): int => $total + money_to_cents($payment['amount']),
        0
    );
    $totalCents = $currency === 'KES' ? 43848000 : 340000;
    $balanceCents = max(0, $totalCents - $paymentsTotalCents);
    $hasPayments = $payments !== [];
    $invoice = [
        'id' => 0, 'invoice_number' => $invoiceNumber, 'customer_name' => $clientName,
        'contact_person' => $clientName, 'customer_email' => 'sample@example.invalid',
        'phone' => '', 'billing_address' => '', 'status' => 'issued',
        'issue_date' => $issueDate ?? $today,
        'due_date' => $dueDate ?? (new DateTimeImmutable($today))->modify('+30 days')->format('Y-m-d'),
        'currency_code' => $currency,
        'kes_exchange_rate' => $kesExchangeRate,
        'fx_rate_date' => $fxRateDate,
        'subtotal' => cents_to_decimal($totalCents), 'discount_percent' => '0.00', 'tax_percent' => '0.00',
        'tax_amount' => '0.00', 'total' => cents_to_decimal($totalCents), 'notes' => '',
        'payment_terms' => '', 'created_at' => $today . ' 00:00:00', 'paid_amount' => cents_to_decimal($paymentsTotalCents),
    ];
    $items = [[
        'description' => 'Sample invoice item', 'quantity' => '1.00', 'unit_price' => cents_to_decimal($totalCents),
        'line_total' => cents_to_decimal($totalCents), 'catalogue_name' => '', 'catalogue_category' => '', 'catalogue_billing_cycle' => '',
    ]];
    $logoFilename = (string) ($company['company_logo'] ?? '');
    $logoUrl = invoice_pdf_logo_data_uri($company);
    $baseUrl = trim((string) (getenv('APP_BASE_URL') ?: getenv('APP_PUBLIC_BASE_URL')));
    $publicInvoiceUrl = rtrim($baseUrl, '/') . '/i/sample';
    if (!filter_var($publicInvoiceUrl, FILTER_VALIDATE_URL)) {
        $publicInvoiceUrl = 'https://example.invalid/i/sample';
    }
    $invoice['status'] = $balanceCents === 0
        ? 'paid'
        : ($invoice['due_date'] < $today
            ? 'overdue'
            : ($hasPayments ? 'partially_paid' : 'issued'));
    $sampleStatusKey = match ($invoice['status']) {
        'partially_paid' => 'partial',
        'paid' => 'paid',
        'overdue' => 'overdue',
        default => 'unpaid',
    };
    $sampleStatusLabel = match ($invoice['status']) {
        'partially_paid' => 'Partially paid',
        'paid' => 'Paid',
        'overdue' => 'Overdue',
        default => 'Unpaid',
    };
    return render_invoice_pdf_document(
        $invoice, $items, $payments, $hasPayments, $paymentsTotalCents, $balanceCents,
        $company, $logoUrl, $invoice['status'], $sampleStatusKey, $sampleStatusLabel, $publicInvoiceUrl,
        '', trim((string) ($company['payment_terms'] ?? '')), 0
    );
}

function render_invoice_pdf_document(
    array $invoice,
    array $items,
    array $payments,
    bool $hasPayments,
    int $paymentsTotalCents,
    int $balanceCents,
    array $company,
    string $logoUrl,
    string $status,
    string $statusKey,
    string $statusLabel,
    string $publicInvoiceUrl,
    string $paymentTerms,
    string $invoicePaymentTerms,
    int $discountCents
): array {
    $invoicePdfDocument = true;
    $invoicePdfQrPlaceholder = $publicInvoiceUrl !== '';
    $companyName = trim((string) ($company['business_name'] ?? '')) ?: 'Breax Technologies';
    $generatedOn = date('l, F jS Y', strtotime((string) $invoice['created_at']));
    ob_start();
    require dirname(__DIR__) . '/includes/invoice_document.php';
    $document = (string) ob_get_clean();
    $css = file_get_contents(dirname(__DIR__) . '/assets/css/styles.css');
    if (!is_string($css)) {
        throw new RuntimeException('Invoice print styles are unavailable.');
    }
    $invoicePdfCss = <<<'CSS'
@page { size: A4; margin: 0; }
.invoice-pdf { margin: 0; padding: 0; background: #fff; }
.invoice-pdf.invoice-page-surface { margin: 0; padding: 0; background: #fff; }
.invoice-pdf .invoice-paper {
  max-width: none; margin: 0; overflow: visible; border-radius: 0; box-shadow: none;
  /* The titlebar cancels this added padding on page 1; cloned pages keep the clearance. */
  padding-top: 134px; -webkit-box-decoration-break: clone; box-decoration-break: clone;
}
.invoice-pdf .invoice-paper__head {
  position: fixed; top: 0; right: 0; left: 0; z-index: 2; width: 100%; min-height: 112px;
}
.invoice-pdf .invoice-paper__titlebar { align-items: flex-start; margin-top: -22px; }
.invoice-pdf .status-box { padding-top: 12px; }
.invoice-pdf .qr { position: absolute; top: 52px; right: 0; height: auto; }
.invoice-pdf .invoice-paper__main {
  padding-bottom: 76px; -webkit-box-decoration-break: clone; box-decoration-break: clone;
}
.invoice-pdf .invoice-paper__footer-container { position: fixed; right: 0; bottom: 0; left: 0; z-index: 3; }
.invoice-pdf .invoice-paper__footer { padding: 8px 40px; border-radius: 0; }
.invoice-pdf .invoice-table-wrap { overflow: visible; }
.invoice-pdf .invoice-table { min-width: 0; }
.invoice-pdf .invoice-table thead, .invoice-pdf .tx thead { display: table-header-group; }
.invoice-pdf .invoice-table tfoot { display: table-row-group; }
.invoice-pdf .invoice-table tr, .invoice-pdf .tx tr,
.invoice-pdf .invoice-table tfoot, .invoice-pdf .invoice-parties,
.invoice-pdf .invoice-dates, .invoice-pdf .transactions, .invoice-pdf .invoice-notes {
  break-inside: avoid; page-break-inside: avoid;
}
CSS;
    $html = '<!doctype html><html lang="en" data-theme="light"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="color-scheme" content="light"><style>' . $css . $invoicePdfCss . '</style></head>'
        . '<body class="page-dashboard">' . $document . '</body></html>';
    $bytes = render_invoice_pdf_in_chromium($html, $publicInvoiceUrl);
    if (strlen($bytes) === 0 || strlen($bytes) > 18 * 1024 * 1024) {
        throw new RuntimeException('Invoice PDF is empty or exceeds the email attachment size limit.');
    }
    return ['filename' => 'Invoice-' . (string) $invoice['invoice_number'] . '.pdf', 'content' => $bytes];
}

function invoice_pdf_logo_data_uri(array $company): string
{
    $logoFilename = (string) ($company['company_logo'] ?? '');
    if ($logoFilename === '' || !preg_match('/\Acompany-logo-[a-f0-9]{32}\.(?:png|jpg|webp)\z/', $logoFilename)) {
        return '';
    }
    $logoPath = dirname(__DIR__) . '/assets/uploads/' . $logoFilename;
    if (!is_file($logoPath) || !is_readable($logoPath)) {
        throw new RuntimeException('The configured company logo is missing or unreadable; the invoice PDF cannot be generated.');
    }
    $mime = function_exists('mime_content_type') ? mime_content_type($logoPath) : false;
    if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
        throw new RuntimeException('The configured company logo has an unsupported image type.');
    }
    $content = file_get_contents($logoPath);
    if (!is_string($content) || $content === '') {
        throw new RuntimeException('The configured company logo could not be read.');
    }
    return 'data:' . $mime . ';base64,' . base64_encode($content);
}

function render_invoice_pdf_in_chromium(string $html, string $qrUrl): string
{
    $scriptPath = dirname(__DIR__) . '/bin/render_invoice_pdf.js';
    if (!is_file($scriptPath)) {
        throw new RuntimeException('The Chromium invoice PDF renderer is missing.');
    }
    $inputPath = tempnam(sys_get_temp_dir(), 'bx-invoice-pdf-input-');
    $outputPath = tempnam(sys_get_temp_dir(), 'bx-invoice-pdf-output-');
    $stdoutPath = tempnam(sys_get_temp_dir(), 'bx-invoice-pdf-stdout-');
    $stderrPath = tempnam(sys_get_temp_dir(), 'bx-invoice-pdf-stderr-');
    if ($inputPath === false || $outputPath === false || $stdoutPath === false || $stderrPath === false) {
        foreach ([$inputPath, $outputPath, $stdoutPath, $stderrPath] as $path) {
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
        throw new RuntimeException('Temporary files for Chromium PDF generation could not be created.');
    }
    try {
        $payload = json_encode(['html' => $html, 'qrUrl' => $qrUrl], JSON_THROW_ON_ERROR);
        if (file_put_contents($inputPath, $payload, LOCK_EX) !== strlen($payload)) {
            throw new RuntimeException('The Chromium invoice PDF input could not be written.');
        }
        $nodeBinary = mail_setting('INVOICE_PDF_NODE', 'node');
        $process = proc_open(
            [$nodeBinary, $scriptPath, $inputPath, $outputPath],
            [0 => ['pipe', 'r'], 1 => ['file', $stdoutPath, 'w'], 2 => ['file', $stderrPath, 'w']],
            $pipes,
            dirname(__DIR__),
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new RuntimeException('The Chromium invoice PDF renderer could not be started.');
        }
        fclose($pipes[0]);
        $exitCode = proc_close($process);
        $errorOutput = file_get_contents($stderrPath);
        if ($exitCode !== 0) {
            $diagnostic = is_string($errorOutput) ? mb_substr(trim($errorOutput), -900) : 'Renderer exited unsuccessfully.';
            throw new RuntimeException('Chromium invoice PDF generation failed: ' . safe_email_error($diagnostic));
        }
        $bytes = file_get_contents($outputPath);
        if (!is_string($bytes) || $bytes === '') {
            throw new RuntimeException('Chromium invoice PDF generation returned an empty file.');
        }
        return $bytes;
    } finally {
        foreach ([$inputPath, $outputPath, $stdoutPath, $stderrPath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}

function smtp_mailer(): PHPMailer
{
    $host = mail_setting('MAIL_HOST', 'smtp.gmail.com');
    $port = (int) mail_setting('MAIL_PORT', '465');
    $encryption = strtolower(mail_setting('MAIL_ENCRYPTION', 'ssl'));
    if ($host === '' || $port < 1 || $port > 65535 || !in_array($encryption, ['ssl', 'tls'], true)) {
        throw new RuntimeException('SMTP host, port, or encryption setting is invalid.');
    }
    $username = mail_setting('MAIL_USERNAME');
    $password = mail_setting('MAIL_PASSWORD');
    if ($username === '' || $password === '') {
        throw new RuntimeException('SMTP username and password are not configured. Gmail requires a Google app password.');
    }
    if (stripos($host, 'gmail') !== false) {
        $password = preg_replace('/\s+/', '', $password) ?? $password;
    }
    $mailer = new PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host = $host;
    $mailer->Port = $port;
    $mailer->SMTPAuth = true;
    $mailer->Username = $username;
    $mailer->Password = $password;
    $mailer->SMTPSecure = $encryption === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mailer->SMTPAutoTLS = false;
    $mailer->Timeout = 30;
    $mailer->Timelimit = 45;
    $mailer->CharSet = PHPMailer::CHARSET_UTF8;
    $mailer->SMTPKeepAlive = false;
    $from = mail_setting('MAIL_FROM_ADDRESS', $username);
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('MAIL_FROM_ADDRESS must be a valid email address.');
    }
    $companyName = (string) (company_email_settings(db())['business_name'] ?? '');
    $mailer->setFrom($from, mail_setting('MAIL_FROM_NAME', $companyName !== '' ? $companyName : 'Breax Invoicing'));
    $replyTo = mail_setting('MAIL_REPLY_TO');
    if ($replyTo !== '') {
        if (!filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('MAIL_REPLY_TO must be a valid email address.');
        }
        $mailer->addReplyTo($replyTo);
    }
    return $mailer;
}

function test_smtp_connection(): string
{
    $mailer = smtp_mailer();
    try {
        if (!$mailer->smtpConnect()) {
            throw new RuntimeException('SMTP connection or login was rejected. Gmail requires a Google app password.');
        }
        $mailer->smtpClose();
        return 'SMTP connection and login succeeded. No email was sent.';
    } catch (Throwable $exception) {
        $mailer->smtpClose();
        $error = safe_email_error($exception->getMessage());
        $gmail = stripos(mail_setting('MAIL_HOST', 'smtp.gmail.com'), 'gmail') !== false;
        $guidance = $gmail
            ? 'Verify the Gmail username and that MAIL_PASSWORD is a Google app password with 2-Step Verification enabled.'
            : 'Check the SMTP host, port, encryption, username, and password against the provider settings.';
        throw new RuntimeException('SMTP connection test failed: ' . ($error !== '' ? $error . ' ' : '') . $guidance);
    }
}

function deliver_email_message(PDO $pdo, array $entry, array $message): array
{
    set_time_limit(300);
    email_mode();
    $recipient = (string) $entry['recipient'];
    $rateLockAcquired = false;
    try {
        $maxPerDay = filter_var(mail_setting('MAIL_MAX_PER_DAY', '400'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($maxPerDay === false) {
            throw new RuntimeException('MAIL_MAX_PER_DAY must be a positive integer.');
        }
        $lock = $pdo->query("SELECT GET_LOCK('breax_email_send_rate',0)")->fetchColumn();
        if ((int) $lock !== 1) {
            throw new RuntimeException('Another email is being sent. This email was not sent; submit it again manually.');
        }
        $rateLockAcquired = true;
        $startOfDayUtc = (new DateTimeImmutable('today', new DateTimeZone('Africa/Nairobi')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $daily = $pdo->prepare("SELECT COUNT(*) FROM email_log WHERE status='sent' AND sent_at>=?");
        $daily->execute([$startOfDayUtc]);
        if ((int) $daily->fetchColumn() >= $maxPerDay) {
            $error = 'The daily email limit has been reached. Try sending again after midnight Africa/Nairobi.';
            $defer = $pdo->prepare("UPDATE email_log SET status='failed',error=? WHERE id=?");
            $defer->execute([$error, (int) $entry['id']]);
            return ['status' => 'failed', 'message' => $error];
        }
        $mailer = smtp_mailer();
        if (isset($message['timeout'])) {
            $timeout = filter_var($message['timeout'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 30]]);
            if ($timeout === false) {
                throw new RuntimeException('Email timeout must be between 1 and 30 seconds.');
            }
            $mailer->Timeout = $timeout;
            $mailer->Timelimit = $timeout;
        }
        $mailer->addAddress($recipient);
        foreach (array_filter(array_map('trim', explode(',', (string) $entry['cc']))) as $cc) {
            $mailer->addCC($cc);
        }
        $mailer->Subject = (string) $entry['subject'];
        $mailer->isHTML(true);
        $mailer->Body = $message['html'];
        $mailer->AltBody = $message['text'];
        $attachmentWireBytes = 0;
        $invoicePdfAttached = false;
        $attachments = is_array($message['attachments'] ?? null) ? $message['attachments'] : [];
        foreach ($attachments as $attachment) {
            if (isset($attachment['invoice_id'])) {
                if (isset($message['tags']['invoice_id']) && (int) $attachment['invoice_id'] !== (int) $message['tags']['invoice_id']) {
                    throw new RuntimeException('Invoice email references a mismatched PDF attachment.');
                }
                $pdf = generateInvoicePdf((int) $attachment['invoice_id']);
                $attachmentWireBytes += (int) ceil(strlen($pdf['content']) * 1.34);
                if ($attachmentWireBytes + strlen($mailer->Body) + strlen($mailer->AltBody) > 25 * 1024 * 1024) {
                    throw new RuntimeException('Invoice PDF and email content exceed the 25 MB message limit.');
                }
                $mailer->addStringAttachment($pdf['content'], (string) ($attachment['filename'] ?? $pdf['filename']), PHPMailer::ENCODING_BASE64, 'application/pdf');
                $invoicePdfAttached = true;
                continue;
            }
            if (!empty($attachment['sample_invoice'])) {
                $sampleInvoiceNumber = is_string($attachment['invoice_number'] ?? null)
                    ? $attachment['invoice_number']
                    : 'INV-SAMPLE-0001';
                $samplePayments = is_array($attachment['payments'] ?? null) ? $attachment['payments'] : [];
                $pdf = create_sample_invoice_pdf(
                    $sampleInvoiceNumber,
                    $samplePayments,
                    is_string($attachment['issue_date'] ?? null) ? $attachment['issue_date'] : null,
                    is_string($attachment['due_date'] ?? null) ? $attachment['due_date'] : null,
                    is_string($attachment['client_name'] ?? null) ? $attachment['client_name'] : 'Sample Client',
                    is_string($attachment['currency_code'] ?? null) ? $attachment['currency_code'] : 'KES',
                    is_string($attachment['kes_exchange_rate'] ?? null) ? $attachment['kes_exchange_rate'] : null,
                    is_string($attachment['fx_rate_date'] ?? null) ? $attachment['fx_rate_date'] : null
                );
                $attachmentWireBytes += (int) ceil(strlen($pdf['content']) * 1.34);
                if ($attachmentWireBytes + strlen($mailer->Body) + strlen($mailer->AltBody) > 25 * 1024 * 1024) {
                    throw new RuntimeException('Sample PDF and email content exceed the 25 MB message limit.');
                }
                $mailer->addStringAttachment($pdf['content'], (string) ($attachment['filename'] ?? $pdf['filename']), PHPMailer::ENCODING_BASE64, 'application/pdf');
                continue;
            }
            if (!isset($attachment['filename'], $attachment['content']) || !is_string($attachment['content'])) {
                throw new RuntimeException('An email attachment is malformed.');
            }
            $content = $attachment['content'];
            $attachmentWireBytes += (int) ceil(strlen($content) * 1.34);
            if ($attachmentWireBytes + strlen($mailer->Body) + strlen($mailer->AltBody) > 25 * 1024 * 1024) {
                throw new RuntimeException('Email content and attachments exceed the 25 MB message limit.');
            }
            $mailer->addStringAttachment($content, (string) $attachment['filename'], PHPMailer::ENCODING_BASE64, (string) ($attachment['mime'] ?? 'application/octet-stream'));
        }
        if (
            ($message['tags']['invoice_id'] ?? null) !== null
            && !in_array(($message['tags']['type'] ?? ''), ['admin_overdue_alert', 'admin_domain_expiry_report', 'admin_overdue_invoice_report', 'admin_google_workspace_report', 'admin_renewal_reminder_digest'], true)
            && !$invoicePdfAttached
        ) {
            throw new RuntimeException('Invoice email is missing its required PDF attachment.');
        }
        if (!$mailer->send()) {
            throw new RuntimeException($mailer->ErrorInfo ?: 'SMTP server did not accept the email.');
        }
        $pdo->beginTransaction();
        $update = $pdo->prepare("UPDATE email_log
            SET status='sent',sent_at=UTC_TIMESTAMP(6),smtp_message_id=?,error=NULL
            WHERE id=?");
        $update->execute([mb_substr((string) $mailer->getLastMessageID(), 0, 255), $entry['id']]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('The email log entry could not be marked as sent.');
        }
        $pdo->commit();
        return ['status' => 'sent', 'message' => 'Email sent successfully.'];
    } catch (Throwable $exception) {
        $error = safe_email_error($exception->getMessage());
        $smtpError = isset($mailer) ? $mailer->getSMTPInstance()->getError() : [];
        $smtpCode = isset($smtpError['smtp_code']) ? (int) $smtpError['smtp_code'] : (int) $exception->getCode();
        $recipientFailure = $smtpCode >= 500
            && $smtpCode < 600
            && preg_match('/recipient|address|mailbox|user/i', $error) === 1
            && stripos($error, (string) $entry['recipient']) !== false;
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $status = $recipientFailure ? 'bounced' : 'failed';
        $update = $pdo->prepare("UPDATE email_log
            SET status=?,error=?
            WHERE id=?");
        $update->execute([
            $status,
            $error,
            $entry['id'],
        ]);
        if ($status === 'bounced' && is_string($entry['recipient']) && $entry['recipient'] !== '') {
            $block = $pdo->prepare('INSERT INTO email_bounced_addresses (address,first_bounced_at,last_bounced_at,reason) VALUES (?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),?) ON DUPLICATE KEY UPDATE last_bounced_at=UTC_TIMESTAMP(),reason=VALUES(reason)');
            $block->execute([mb_strtolower($entry['recipient']), mb_substr($error, 0, 500)]);
        }
        return ['status' => $status, 'message' => $error !== '' ? $error : 'Email delivery failed.'];
    } finally {
        if ($rateLockAcquired) {
            $pdo->query("SELECT RELEASE_LOCK('breax_email_send_rate')");
        }
    }
}
