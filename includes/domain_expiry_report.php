<?php
declare(strict_types=1);

require_once __DIR__ . '/email.php';
require_once __DIR__ . '/overdue_services.php';

function overdue_report_customers(array $items): array
{
    $customers = [];
    foreach ($items as $item) {
        $customerId = (int) ($item['customer_id'] ?? 0);
        if (!isset($customers[$customerId])) {
            $customers[$customerId] = [
                'customer_id' => $customerId,
                'customer_name' => $item['customer_name'],
                'customer_email' => $item['customer_email'],
                'customer_phone' => $item['customer_phone'],
                'customer_address' => $item['customer_address'],
                'relevant_count' => 0,
            ];
        }
        $customers[$customerId]['relevant_count']++;
    }
    return array_values($customers);
}

function overdue_domain_report_data(?PDO $pdo = null): array
{
    $services = overdue_service_data($pdo ?? db());
    $domains = array_map(static fn(array $row): array => [
        'domain_id' => (int) $row['domain_id'],
        'domain_name' => (string) $row['domain_name'],
        'expiry_date' => (string) $row['expiry_date'],
        'days_remaining' => -(int) $row['days_overdue'],
        'customer_id' => (int) ($row['customer_id'] ?? 0),
        'customer_name' => trim((string) ($row['customer_name'] ?? '')) ?: 'Customer details unavailable',
        'customer_email' => trim((string) ($row['customer_email'] ?? '')),
        'customer_phone' => trim((string) ($row['customer_phone'] ?? '')),
        'customer_address' => trim((string) ($row['customer_address'] ?? '')),
    ], $services['domains']);
    return [
        'as_of_date' => $services['as_of_date'],
        'domains' => $domains,
        'customers' => overdue_report_customers($domains),
        'domain_count' => $services['counts']['domains'],
    ];
}

function overdue_google_workspace_report_data(?PDO $pdo = null): array
{
    $services = overdue_service_data($pdo ?? db());
    $workspaces = array_map(static fn(array $row): array => [
        'workspace_id' => (int) $row['workspace_id'],
        'domain_name' => (string) $row['domain_name'],
        'expiry_date' => (string) $row['expiry_date'],
        'days_remaining' => -(int) $row['days_overdue'],
        'customer_id' => (int) ($row['customer_id'] ?? 0),
        'customer_name' => trim((string) ($row['customer_name'] ?? '')) ?: 'Customer details unavailable',
        'customer_email' => trim((string) ($row['customer_email'] ?? '')),
        'customer_phone' => trim((string) ($row['customer_phone'] ?? '')),
        'customer_address' => trim((string) ($row['customer_address'] ?? '')),
    ], $services['workspaces']);
    return [
        'as_of_date' => $services['as_of_date'],
        'workspaces' => $workspaces,
        'customers' => overdue_report_customers($workspaces),
        'workspace_count' => $services['counts']['workspaces'],
    ];
}

function overdue_domain_report_email_rows(array $domains): string
{
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $rows = '';
    foreach ($domains as $index => $domain) {
        $rows .= '<tr>'
            . '<td style="padding:9px 10px;border-bottom:1px solid #e7e4ef;color:#141d38;">' . $escape((string) ($index + 1)) . '</td>'
            . '<td style="padding:9px 10px;border-bottom:1px solid #e7e4ef;color:#141d38;overflow-wrap:anywhere;">' . $escape((string) $domain['domain_name']) . '</td>'
            . '<td style="padding:9px 10px;border-bottom:1px solid #e7e4ef;color:#141d38;">' . $escape(date('j F Y', strtotime((string) $domain['expiry_date']))) . '</td>'
            . '<td style="padding:9px 10px;border-bottom:1px solid #e7e4ef;color:#141d38;">' . $escape((string) $domain['days_remaining']) . '</td>'
            . '</tr>';
    }
    return $rows;
}

function overdue_invoice_report_data(?PDO $pdo = null): array
{
    $services = overdue_service_data($pdo ?? db());
    $invoices = [];
    foreach ($services['invoices'] as $row) {
        $paidCents = money_to_cents($row['paid_amount']);
        $balanceCents = max(0, money_to_cents($row['total']) - $paidCents);
        $invoices[] = [
            'id' => (int) $row['id'],
            'customer_id' => (int) ($row['customer_id'] ?? 0),
            'invoice_number' => trim((string) ($row['invoice_number'] ?? '')) ?: 'Unnumbered',
            'customer_name' => trim((string) ($row['customer_name'] ?? '')) ?: 'Customer details unavailable',
            'customer_phone' => trim((string) ($row['customer_phone'] ?? '')),
            'customer_email' => trim((string) ($row['customer_email'] ?? '')),
            'customer_address' => trim((string) ($row['customer_address'] ?? '')),
            'status' => $balanceCents <= 0 ? 'Paid' : ($paidCents > 0 ? 'Partially Paid' : 'Unpaid'),
        ];
    }
    $customers = [];
    foreach ($invoices as $invoice) {
        if (!isset($customers[$invoice['customer_id']])) {
            $customers[$invoice['customer_id']] = [
                'customer_id' => $invoice['customer_id'],
                'customer_name' => $invoice['customer_name'],
                'customer_phone' => $invoice['customer_phone'],
                'customer_email' => $invoice['customer_email'],
                'customer_address' => $invoice['customer_address'],
                'relevant_count' => 0,
            ];
        }
        $customers[$invoice['customer_id']]['relevant_count']++;
    }
    return [
        'as_of_date' => $services['as_of_date'],
        'invoices' => $invoices,
        'customers' => array_values($customers),
        'invoice_count' => $services['counts']['invoices'],
    ];
}

function generate_overdue_invoice_report_pdf(array $report): array
{
    if (!isset($report['invoices'], $report['customers'], $report['as_of_date'], $report['invoice_count'])
        || !is_array($report['invoices']) || !is_array($report['customers'])
        || (int) $report['invoice_count'] !== count($report['invoices'])) {
        throw new InvalidArgumentException('A consistent overdue invoice report is required to generate a PDF.');
    }
    $company = company_email_settings(db());
    $companyName = trim((string) ($company['business_name'] ?? '')) ?: 'Breax Technologies';
    $logoUrl = invoice_pdf_logo_data_uri($company);
    $generatedOn = (new DateTimeImmutable($report['as_of_date'], new DateTimeZone('Africa/Nairobi')))->format('l, F jS Y');
    ob_start();
    require __DIR__ . '/overdue_invoice_report_document.php';
    $document = (string) ob_get_clean();
    $css = file_get_contents(dirname(__DIR__) . '/assets/css/styles.css');
    if (!is_string($css)) {
        throw new RuntimeException('Invoice print styles are unavailable for the overdue invoice report.');
    }
    $reportCss = <<<'CSS'
@page { size: A4; margin: 0; }
.overdue-invoice-report { margin: 0; padding: 0; background: #fff; }
.overdue-invoice-report .invoice-paper {
  max-width: none; margin: 0; overflow: visible; border-radius: 0; box-shadow: none;
  padding-top: 112px; -webkit-box-decoration-break: clone; box-decoration-break: clone;
}
.overdue-invoice-report .invoice-paper__head {
  position: fixed; top: 0; right: 0; left: 0; z-index: 2; width: 100%; min-height: 112px;
}
.overdue-invoice-report .invoice-paper__main { padding-bottom: 76px; -webkit-box-decoration-break: clone; box-decoration-break: clone; }
.overdue-invoice-report .invoice-paper__titlebar { align-items: flex-start; }
.overdue-invoice-report .invoice-paper__titlebar h2 { font-size: 1.65rem; }
.overdue-invoice-report .invoice-paper__footer-container { position: fixed !important; border-radius: 0; }
.overdue-invoice-report .invoice-paper__footer { padding: 8px 40px; border-radius: 0; }
.overdue-invoice-report .report-period { color: var(--invoice-muted); font-size: .95rem; }
.overdue-invoice-report .report-section { margin-top: 22px; }
.overdue-invoice-report .report-section h3 { margin: 0 0 10px; padding-bottom: 5px; border-bottom: 2px solid var(--invoice-secondary); color: var(--invoice-primary); font-family: Georgia, "Times New Roman", serif; font-size: 1rem; }
.overdue-invoice-report .report-table { width: 100%; min-width: 0; table-layout: fixed; border-collapse: collapse; font-family: Arial, Helvetica, sans-serif; font-size: .78rem; }
.overdue-invoice-report .report-table thead { display: table-header-group; }
.overdue-invoice-report .report-table tr { break-inside: avoid; page-break-inside: avoid; }
.overdue-invoice-report .report-table th { padding: 9px 7px; color: #fff; background: var(--invoice-primary); font-size: .68rem; font-weight: 600; text-align: left; white-space: nowrap; }
.overdue-invoice-report .report-table td { padding: 8px 7px; border-bottom: 1px solid var(--invoice-line); color: var(--invoice-ink); vertical-align: top; overflow-wrap: anywhere; }
.overdue-invoice-report .report-table tbody tr:nth-child(odd) td { background: var(--invoice-row-a); }
.overdue-invoice-report .report-table tbody tr:nth-child(even) td { background: var(--invoice-row-b); }
.overdue-invoice-report .report-customer-table th:nth-child(1) { width: 24%; }
.overdue-invoice-report .report-customer-table th:nth-child(2) { width: 22%; }
.overdue-invoice-report .report-customer-table th:nth-child(3) { width: 16%; }
.overdue-invoice-report .report-customer-table th:nth-child(4) { width: 38%; }
.overdue-invoice-report .report-summary { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-top: 22px; padding: 12px 16px; border-top: 2px solid var(--invoice-primary); color: var(--invoice-accent); background: var(--invoice-total); font-size: .95rem; }
.overdue-invoice-report .report-summary strong { color: var(--invoice-primary); font-size: 1.5rem; font-weight: 800; }
.overdue-invoice-report .report-invoice-table { margin-top: 18px; }
.overdue-invoice-report .report-customer-name { white-space: nowrap; font-size: .72rem; }
.overdue-invoice-report .report-status { display: inline-block; padding: 3px 7px; border-radius: 999px; font-size: .68rem; font-weight: 700; line-height: 1.2; white-space: nowrap; }
.overdue-invoice-report .report-status--paid { color: #176b43; background: #e5f5ec; }
.overdue-invoice-report .report-status--partial { color: #805500; background: #fff2cc; }
.overdue-invoice-report .report-status--unpaid { color: #a42632; background: #fde8e9; }
.overdue-invoice-report .report-invoice-table th:nth-child(1) { width: 7%; }
.overdue-invoice-report .report-invoice-table th:nth-child(2) { width: 17%; }
.overdue-invoice-report .report-invoice-table th:nth-child(3) { width: 20%; }
.overdue-invoice-report .report-invoice-table th:nth-child(4) { width: 13%; }
.overdue-invoice-report .report-invoice-table th:nth-child(5) { width: 25%; }
.overdue-invoice-report .report-invoice-table th:nth-child(6) { width: 18%; }
CSS;
    $html = '<!doctype html><html lang="en" data-theme="light"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="color-scheme" content="light"><style>' . $css . $reportCss . '</style></head>'
        . '<body class="page-dashboard"><div class="invoice-page-surface overdue-invoice-report">' . $document . '</div></body></html>';
    $content = render_invoice_pdf_in_chromium($html, '');
    if (strlen($content) < 5 || !str_starts_with($content, '%PDF-') || strlen($content) > 24 * 1024 * 1024) {
        throw new RuntimeException('Overdue invoice PDF is invalid, empty, or exceeds the attachment size limit.');
    }
    return [
        'filename' => 'overdue-invoices-report.pdf',
        'content' => $content,
    ];
}

function generate_overdue_domain_pdf(array $report): array
{
    if (!isset($report['domains'], $report['customers'], $report['as_of_date'], $report['domain_count'])
        || !is_array($report['domains']) || !is_array($report['customers'])
        || (int) $report['domain_count'] !== count($report['domains'])) {
        throw new InvalidArgumentException('A consistent overdue domain report is required to generate a PDF.');
    }
    $company = company_email_settings(db());
    $companyName = trim((string) ($company['business_name'] ?? '')) ?: 'Breax Technologies';
    $logoUrl = invoice_pdf_logo_data_uri($company);
    $generatedOn = (new DateTimeImmutable($report['as_of_date'], new DateTimeZone('Africa/Nairobi')))->format('l, F jS Y');
    ob_start();
    require __DIR__ . '/domain_expiry_report_document.php';
    $document = (string) ob_get_clean();
    $css = file_get_contents(dirname(__DIR__) . '/assets/css/styles.css');
    if (!is_string($css)) {
        throw new RuntimeException('Invoice print styles are unavailable for the domain expiry report.');
    }
    $reportCss = <<<'CSS'
@page { size: A4; margin: 0; }
.domain-expiry-report { margin: 0; padding: 0; background: #fff; }
.domain-expiry-report .invoice-paper {
  max-width: none; margin: 0; overflow: visible; border-radius: 0; box-shadow: none;
  padding-top: 112px; -webkit-box-decoration-break: clone; box-decoration-break: clone;
}
.domain-expiry-report .invoice-paper__head {
  position: fixed; top: 0; right: 0; left: 0; z-index: 2; width: 100%; min-height: 112px;
}
.domain-expiry-report .invoice-paper__main { padding-bottom: 76px; -webkit-box-decoration-break: clone; box-decoration-break: clone; }
.domain-expiry-report .invoice-paper__titlebar { align-items: flex-start; }
.domain-expiry-report .invoice-paper__titlebar h2 { font-size: 1.65rem; }
.domain-expiry-report .invoice-paper__footer-container { position: fixed !important; border-radius: 0; }
.domain-expiry-report .invoice-paper__footer { padding: 8px 40px; border-radius: 0; }
.domain-expiry-report .domain-report-period { color: var(--invoice-muted); font-size: .95rem; }
.domain-expiry-report .domain-report-section { margin-top: 22px; }
.domain-expiry-report .domain-report-section h3 { margin: 0 0 10px; padding-bottom: 5px; border-bottom: 2px solid var(--invoice-secondary); color: var(--invoice-primary); font-family: Georgia, "Times New Roman", serif; font-size: 1rem; }
.domain-expiry-report .domain-report-note { margin: 7px 0 0; color: var(--invoice-muted); font-size: .72rem; }
.domain-expiry-report .domain-report-table { width: 100%; min-width: 0; table-layout: fixed; border-collapse: collapse; font-family: Arial, Helvetica, sans-serif; font-size: .78rem; }
.domain-expiry-report .domain-report-table thead { display: table-header-group; }
.domain-expiry-report .domain-report-table tr { break-inside: avoid; page-break-inside: avoid; }
.domain-expiry-report .domain-report-table th { padding: 9px 8px; color: #fff; background: var(--invoice-primary); font-size: .7rem; font-weight: 600; text-align: left; white-space: nowrap; }
.domain-expiry-report .domain-report-table td { padding: 8px; border-bottom: 1px solid var(--invoice-line); color: var(--invoice-ink); vertical-align: top; overflow-wrap: anywhere; }
.domain-expiry-report .domain-report-table tbody tr:nth-child(odd) td { background: var(--invoice-row-a); }
.domain-expiry-report .domain-report-table tbody tr:nth-child(even) td { background: var(--invoice-row-b); }
.domain-expiry-report .report-customer-name { white-space: nowrap; font-size: .72rem; }
.domain-expiry-report .report-days-remaining { display: inline-block; padding: 3px 7px; border-radius: 999px; font-weight: 700; line-height: 1.2; white-space: nowrap; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
.domain-expiry-report .report-days-remaining--danger { color: #fff !important; background: #dc2626 !important; background-color: #dc2626 !important; }
.domain-expiry-report .report-days-remaining--success { color: #fff !important; background: #16a34a !important; background-color: #16a34a !important; }
.domain-expiry-report .domain-summary { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 12px 16px; border-top: 2px solid var(--invoice-primary); color: var(--invoice-accent); background: var(--invoice-total); font-size: .95rem; }
.domain-expiry-report .domain-summary strong { font-size: 1.15rem; }
.domain-expiry-report .domain-customer-table th:nth-child(1) { width: 24%; }
.domain-expiry-report .domain-customer-table th:nth-child(2) { width: 22%; }
.domain-expiry-report .domain-customer-table th:nth-child(3) { width: 16%; }
.domain-expiry-report .domain-customer-table th:nth-child(4) { width: 38%; }
.domain-expiry-report .domain-list-table th:nth-child(1) { width: 7%; }
.domain-expiry-report .domain-list-table th:nth-child(2) { width: 24%; }
.domain-expiry-report .domain-list-table th:nth-child(3) { width: 17%; }
.domain-expiry-report .domain-list-table th:nth-child(4) { width: 29%; }
.domain-expiry-report .domain-list-table th:nth-child(5) { width: 23%; white-space: nowrap; font-size: .68rem; }
CSS;
    $html = '<!doctype html><html lang="en" data-theme="light"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="color-scheme" content="light"><style>' . $css . $reportCss . '</style></head>'
        . '<body class="page-dashboard"><div class="invoice-page-surface domain-expiry-report">' . $document . '</div></body></html>';
    $content = render_invoice_pdf_in_chromium($html, '');
    if (strlen($content) < 5 || !str_starts_with($content, '%PDF-') || strlen($content) > 24 * 1024 * 1024) {
        throw new RuntimeException('Overdue domain PDF is invalid, empty, or exceeds the attachment size limit.');
    }
    return [
        'filename' => 'domain-expiry-report.pdf',
        'content' => $content,
    ];
}

function overdue_report_email_logo_url(array $company): string
{
    $logoUrl = mail_setting('LOGO_URL');
    if ($logoUrl !== '' && filter_var($logoUrl, FILTER_VALIDATE_URL)
        && strtolower((string) parse_url($logoUrl, PHP_URL_SCHEME)) === 'https') {
        return $logoUrl;
    }
    $logoFilename = (string) ($company['company_logo'] ?? '');
    if ($logoFilename !== '' && preg_match('/\Acompany-logo-[a-f0-9]{32}\.(?:png|jpg|webp)\z/', $logoFilename)) {
        return rtrim(public_invoice_base_url(), '/') . '/assets/uploads/' . rawurlencode($logoFilename);
    }
    throw new RuntimeException('Configure a public HTTPS LOGO_URL or upload the company logo before sending the overdue domain expiry report.');
}

function generate_overdue_google_workspace_report_pdf(array $report): array
{
    if (!isset($report['workspaces'], $report['customers'], $report['as_of_date'], $report['workspace_count'])
        || !is_array($report['workspaces']) || !is_array($report['customers'])
        || (int) $report['workspace_count'] !== count($report['workspaces'])) {
        throw new InvalidArgumentException('A consistent Google Workspace report is required to generate a PDF.');
    }
    $company = company_email_settings(db());
    $companyName = trim((string) ($company['business_name'] ?? '')) ?: 'Breax Technologies';
    $logoUrl = invoice_pdf_logo_data_uri($company);
    $generatedOn = (new DateTimeImmutable($report['as_of_date'], new DateTimeZone('Africa/Nairobi')))->format('l, F jS Y');
    ob_start();
    require __DIR__ . '/google_workspace_report_document.php';
    $document = (string) ob_get_clean();
    $css = file_get_contents(dirname(__DIR__) . '/assets/css/styles.css');
    if (!is_string($css)) {
        throw new RuntimeException('Invoice print styles are unavailable for the Google Workspace report.');
    }
    $reportCss = <<<'CSS'
@page { size: A4; margin: 0; }
.google-workspace-report { margin: 0; padding: 0; background: #fff; }
.google-workspace-report .invoice-paper {
  max-width: none; margin: 0; overflow: visible; border-radius: 0; box-shadow: none;
  padding-top: 112px; -webkit-box-decoration-break: clone; box-decoration-break: clone;
}
.google-workspace-report .invoice-paper__head {
  position: fixed; top: 0; right: 0; left: 0; z-index: 2; width: 100%; min-height: 112px;
}
.google-workspace-report .invoice-paper__main { padding-bottom: 76px; -webkit-box-decoration-break: clone; box-decoration-break: clone; }
.google-workspace-report .invoice-paper__titlebar { align-items: flex-start; }
.google-workspace-report .invoice-paper__titlebar h2 { font-size: 1.65rem; }
.google-workspace-report .invoice-paper__footer-container { position: fixed !important; border-radius: 0; }
.google-workspace-report .invoice-paper__footer { padding: 8px 40px; border-radius: 0; }
.google-workspace-report .workspace-report-period { color: var(--invoice-muted); font-size: .95rem; }
.google-workspace-report .workspace-report-section { margin-top: 22px; }
.google-workspace-report .workspace-report-section h3 { margin: 0 0 10px; padding-bottom: 5px; border-bottom: 2px solid var(--invoice-secondary); color: var(--invoice-primary); font-family: Georgia, "Times New Roman", serif; font-size: 1rem; }
.google-workspace-report .workspace-report-table { width: 100%; min-width: 0; table-layout: fixed; border-collapse: collapse; font-family: Arial, Helvetica, sans-serif; font-size: .78rem; }
.google-workspace-report .workspace-report-table thead { display: table-header-group; }
.google-workspace-report .workspace-report-table tr { break-inside: avoid; page-break-inside: avoid; }
.google-workspace-report .workspace-report-table th { padding: 9px 8px; color: #fff; background: var(--invoice-primary); font-size: .7rem; font-weight: 600; text-align: left; white-space: nowrap; }
.google-workspace-report .workspace-report-table td { padding: 8px; border-bottom: 1px solid var(--invoice-line); color: var(--invoice-ink); vertical-align: top; overflow-wrap: anywhere; }
.google-workspace-report .workspace-report-table tbody tr:nth-child(odd) td { background: var(--invoice-row-a); }
.google-workspace-report .workspace-report-table tbody tr:nth-child(even) td { background: var(--invoice-row-b); }
.google-workspace-report .report-customer-name { white-space: nowrap; font-size: .72rem; }
.google-workspace-report .report-days-remaining { display: inline-block; padding: 3px 7px; border-radius: 999px; font-weight: 700; line-height: 1.2; white-space: nowrap; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.google-workspace-report .report-days-remaining--danger { color: #fff !important; background-color: #dc2626 !important; }
.google-workspace-report .report-days-remaining--success { color: #fff !important; background-color: #16a34a !important; }
.google-workspace-report .workspace-report-table th:nth-child(1) { width: 7%; }
.google-workspace-report .workspace-report-table th:nth-child(2) { width: 23%; }
.google-workspace-report .workspace-report-table th:nth-child(3) { width: 25%; }
.google-workspace-report .workspace-report-table th:nth-child(4) { width: 20%; }
.google-workspace-report .workspace-report-table th:nth-child(5) { width: 25%; white-space: nowrap; font-size: .68rem; }
.google-workspace-report .workspace-customer-table th:nth-child(1) { width: 24%; }
.google-workspace-report .workspace-customer-table th:nth-child(2) { width: 22%; }
.google-workspace-report .workspace-customer-table th:nth-child(3) { width: 16%; }
.google-workspace-report .workspace-customer-table th:nth-child(4) { width: 38%; }
.google-workspace-report .workspace-report-note { margin: 7px 0 0; color: var(--invoice-muted); font-size: .72rem; }
.google-workspace-report .workspace-summary { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 12px 16px; border-top: 2px solid var(--invoice-primary); color: var(--invoice-accent); background: var(--invoice-total); font-size: .95rem; }
.google-workspace-report .workspace-summary strong { font-size: 1.15rem; }
CSS;
    $html = '<!doctype html><html lang="en" data-theme="light"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="color-scheme" content="light"><style>' . $css . $reportCss . '</style></head>'
        . '<body class="page-dashboard"><div class="invoice-page-surface google-workspace-report">' . $document . '</div></body></html>';
    $content = render_invoice_pdf_in_chromium($html, '');
    if (strlen($content) < 5 || !str_starts_with($content, '%PDF-') || strlen($content) > 24 * 1024 * 1024) {
        throw new RuntimeException('Overdue Google Workspace PDF is invalid, empty, or exceeds the attachment size limit.');
    }
    return [
        'filename' => 'google-workspace-report.pdf',
        'content' => $content,
    ];
}

function run_overdue_invoice_report(
    bool $send,
    bool $allowResend = false,
    ?string $previewOutputPath = null
): array
{
    $report = overdue_invoice_report_data();
    $result = [
        'as_of_date' => $report['as_of_date'],
        'invoice_count' => $report['invoice_count'],
        'pdf_filename' => null,
        'recipients' => [],
        'sent' => 0,
        'failed' => 0,
        'skipped' => 0,
        'message' => '',
    ];
    if (!$send) {
        $pdf = generate_overdue_invoice_report_pdf($report);
        $previewOutputPath ??= rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $pdf['filename'];
        if (!str_ends_with(strtolower($previewOutputPath), '.pdf')) {
            throw new InvalidArgumentException('The preview output path must end in .pdf.');
        }
        $bytesWritten = file_put_contents($previewOutputPath, $pdf['content'], LOCK_EX);
        if ($bytesWritten !== strlen($pdf['content'])) {
            throw new RuntimeException('The overdue invoice PDF preview could not be written completely.');
        }
        $result['pdf_filename'] = $pdf['filename'];
        $result['pdf_path'] = $previewOutputPath;
        $result['message'] = 'Preview generated; no email was sent.';
        return $result;
    }

    $pdo = db();
    $lockName = 'overdue-invoice-report-' . $report['as_of_date'];
    if ((int) $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lockName) . ',0)')->fetchColumn() !== 1) {
        $result['message'] = 'Another overdue invoice report run is already in progress.';
        return $result;
    }
    try {
        $recipients = [];
        foreach ($pdo->query("SELECT id,name,email FROM users WHERE receive_overdue_alerts=1 AND email<>'' ORDER BY id")->fetchAll() as $row) {
            $email = trim((string) ($row['email'] ?? ''));
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $recipients[mb_strtolower($email)] ??= [
                    'id' => (int) $row['id'],
                    'name' => trim((string) ($row['name'] ?? '')) ?: 'Administrator',
                    'email' => $email,
                ];
            }
        }
        if ($recipients === []) {
            $result['message'] = 'No administrators are opted in to internal alerts; no invoice report was sent.';
            return $result;
        }
        $logType = $pdo->query("SHOW COLUMNS FROM email_log LIKE 'type'")->fetch();
        if (!$logType || !str_contains((string) ($logType['Type'] ?? ''), "'admin_overdue_invoice_report'")) {
            $result['message'] = 'Run database/migrate_admin_overdue_invoice_reports.php before sending overdue invoice reports.';
            return $result;
        }
        if (strtolower(mail_setting('MAIL_MODE', 'live')) !== 'live') {
            $result['message'] = 'Overdue invoice reports require MAIL_MODE=live and opted-in administrators.';
            return $result;
        }

        $pdf = generate_overdue_invoice_report_pdf($report);
        $result['pdf_filename'] = $pdf['filename'];
        $company = company_email_settings($pdo);
        $companyName = trim((string) ($company['business_name'] ?? '')) ?: 'Breax Invoicing';
        $template = dirname(__DIR__) . '/emails/email-admin-overdue-invoice-report.html';
        foreach ($recipients as $recipient) {
            $result['recipients'][] = $recipient['email'];
            $prefix = 'overdue-invoice-report:' . $report['as_of_date'] . ':' . hash('sha256', mb_strtolower($recipient['email']));
            $historyQuery = $pdo->prepare("SELECT status FROM email_log WHERE type='admin_overdue_invoice_report' AND recipient=? AND idempotency_key LIKE ? ORDER BY id DESC");
            $historyQuery->execute([$recipient['email'], $prefix . ':%']);
            $history = $historyQuery->fetchAll();
            if (!$allowResend && count(array_filter($history, static fn(array $entry): bool => $entry['status'] === 'sent')) > 0) {
                $result['skipped']++;
                continue;
            }
            $key = $prefix . ':' . (count($history) + 1);
            $subject = 'Overdue Invoices Report – ' . $report['as_of_date'];
            try {
                $rendered = render_email_template([
                    'ADMIN_NAME' => $recipient['name'],
                    'COMPANY_NAME' => $companyName,
                    'LOGO_URL' => overdue_report_email_logo_url($company),
                    'AS_OF_DATE' => $report['as_of_date'],
                    'INVOICE_COUNT' => (string) $report['invoice_count'],
                    'ATTACHMENT_NAME' => $pdf['filename'],
                    'SENDER_ADDRESS' => preg_replace('/\s+/u', ' ', trim((string) ($company['business_address'] ?? ''))) ?: ' ',
                ], $template, EMAIL_ADMIN_OVERDUE_INVOICE_REPORT_PLACEHOLDERS);
                $delivery = sendEmail([
                    'to' => $recipient['email'],
                    'subject' => $subject,
                    'html' => $rendered['html'],
                    'text' => $rendered['text'],
                    'attachments' => [['filename' => $pdf['filename'], 'content' => $pdf['content'], 'mime' => 'application/pdf']],
                    'idempotencyKey' => $key,
                    'tags' => ['type' => 'admin_overdue_invoice_report', 'triggered_by' => 'system'],
                ]);
                if (($delivery['status'] ?? '') === 'sent') {
                    $result['sent']++;
                } else {
                    $result['failed']++;
                }
            } catch (Throwable $exception) {
                $existing = $pdo->prepare('SELECT 1 FROM email_log WHERE idempotency_key=?');
                $existing->execute([$key]);
                if (!$existing->fetchColumn()) {
                    email_log_failure(null, 'admin_overdue_invoice_report', $recipient['email'], $subject, 'system', $exception->getMessage(), $key);
                }
                $result['failed']++;
                error_log('Overdue invoice report failed for admin ' . $recipient['id'] . ': ' . safe_email_error($exception->getMessage()));
            }
        }
        $result['message'] = 'Overdue invoice report run completed.';
    } finally {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockName) . ')');
    }
    return $result;
}

function run_domain_renewal_report(
    bool $send,
    ?string $previewOutputPath = null,
    bool $allowResend = false
): array {
    $report = overdue_domain_report_data();
    $result = [
        'as_of_date' => $report['as_of_date'],
        'domain_count' => $report['domain_count'],
        'customer_count' => count($report['customers']),
        'pdf_filename' => null,
        'pdf_path' => null,
        'recipients' => [],
        'sent' => 0,
        'failed' => 0,
        'skipped' => 0,
        'message' => '',
    ];
    if (!$send) {
        $pdf = generate_overdue_domain_pdf($report);
        $previewOutputPath ??= rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . $pdf['filename'];
        if (!str_ends_with(strtolower($previewOutputPath), '.pdf')) {
            throw new InvalidArgumentException('The preview output path must end in .pdf.');
        }
        $bytesWritten = file_put_contents($previewOutputPath, $pdf['content'], LOCK_EX);
        if ($bytesWritten !== strlen($pdf['content'])) {
            throw new RuntimeException('The domain renewal PDF preview could not be written completely.');
        }
        $result['pdf_path'] = $previewOutputPath;
        $result['pdf_filename'] = $pdf['filename'];
        $result['message'] = 'Preview generated; no email was sent.';
        return $result;
    }

    $pdo = db();
    $lockName = 'domain-expiry-report-' . $report['as_of_date'];
    if ((int) $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lockName) . ',0)')->fetchColumn() !== 1) {
        $result['message'] = 'Another domain renewal report run is already in progress.';
        return $result;
    }
    try {
        $recipientRows = $pdo->query("SELECT id,name,email FROM users
            WHERE receive_overdue_alerts=1 AND email<>'' ORDER BY id")->fetchAll();
        $recipients = [];
        foreach ($recipientRows as $recipient) {
            $email = trim((string) ($recipient['email'] ?? ''));
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $recipients[mb_strtolower($email)] ??= [
                    'id' => (int) $recipient['id'],
                    'name' => trim((string) ($recipient['name'] ?? '')) ?: 'Administrator',
                    'email' => $email,
                ];
            }
        }
        if ($recipients === []) {
            $result['message'] = 'No administrators are opted in to internal alerts; no email was sent.';
            return $result;
        }
        $logType = $pdo->query("SHOW COLUMNS FROM email_log LIKE 'type'")->fetch();
        if (!$logType || !str_contains((string) ($logType['Type'] ?? ''), "'admin_domain_expiry_report'")) {
            $result['message'] = 'Run database/migrate_domain_expiry_reports.php before sending domain renewal reports.';
            return $result;
        }
        if (strtolower(mail_setting('MAIL_MODE', 'live')) !== 'live') {
            $result['message'] = 'Domain renewal reports require MAIL_MODE=live and are restricted to opted-in administrators; no email was sent.';
            return $result;
        }

        $pdf = generate_overdue_domain_pdf($report);
        $attachments = [[
            'filename' => $pdf['filename'],
            'content' => $pdf['content'],
            'mime' => 'application/pdf',
        ]];
        $result['pdf_filename'] = $pdf['filename'];
        $company = company_email_settings($pdo);
        $companyName = trim((string) ($company['business_name'] ?? '')) ?: 'Breax Invoicing';
        $logoUrl = overdue_report_email_logo_url($company);
        $senderAddress = preg_replace('/\s+/u', ' ', trim((string) ($company['business_address'] ?? ''))) ?: ' ';
        $templatePath = dirname(__DIR__) . '/emails/email-admin-domain-expiry-report.html';
        foreach ($recipients as $recipient) {
            $result['recipients'][] = $recipient['email'];
            $idempotencyPrefix = 'domain-expiry:' . $report['as_of_date'] . ':' . hash('sha256', mb_strtolower($recipient['email']));
            $historyQuery = $pdo->prepare("SELECT status,idempotency_key FROM email_log
                WHERE type='admin_domain_expiry_report' AND recipient=? AND idempotency_key LIKE ?
                ORDER BY id DESC");
            $historyQuery->execute([$recipient['email'], $idempotencyPrefix . ':%']);
            $history = $historyQuery->fetchAll();
            if (!$allowResend && count(array_filter($history, static fn(array $row): bool => $row['status'] === 'sent')) > 0) {
                $result['skipped']++;
                continue;
            }
            $attempt = count($history) + 1;
            $idempotencyKey = $idempotencyPrefix . ':' . $attempt;
            $subject = 'Domain Renewal Report – ' . $report['as_of_date'];
            $values = [
                'ADMIN_NAME' => $recipient['name'],
                'COMPANY_NAME' => $companyName,
                'LOGO_URL' => $logoUrl,
                'AS_OF_DATE' => $report['as_of_date'],
                'DOMAIN_COUNT' => (string) $report['domain_count'],
                'DOMAIN_ROWS_HTML' => overdue_domain_report_email_rows($report['domains']),
                'ATTACHMENT_NAME' => $pdf['filename'],
                'SENDER_ADDRESS' => $senderAddress,
            ];
            try {
                $rendered = render_email_template(
                    $values,
                    $templatePath,
                    EMAIL_ADMIN_DOMAIN_EXPIRY_PLACEHOLDERS
                );
                $delivery = sendEmail([
                    'to' => $recipient['email'],
                    'subject' => $subject,
                    'html' => $rendered['html'],
                    'text' => implode("\n", [
                        'Domain Renewal Report – ' . $report['as_of_date'],
                        '',
                        'Hello ' . $recipient['name'] . ',',
                        '',
                        'Please find attached the domain renewal report as of ' . $report['as_of_date'] . '.',
                        'The report includes customer information and ' . $report['domain_count'] . ' overdue domain(s).',
                        'The attached PDF contains the domain expiry details as of the report date.',
                        'Please review the report and follow up with the relevant customers regarding their expired domains.',
                        '',
                        'Kind regards,',
                        $companyName,
                    ]),
                    'attachments' => $attachments,
                    'idempotencyKey' => $idempotencyKey,
                    'tags' => ['type' => 'admin_domain_expiry_report', 'triggered_by' => 'system'],
                ]);
                if (($delivery['status'] ?? '') === 'sent') {
                    $result['sent']++;
                } else {
                    $result['failed']++;
                }
            } catch (Throwable $exception) {
                $existing = $pdo->prepare('SELECT 1 FROM email_log WHERE idempotency_key=?');
                $existing->execute([$idempotencyKey]);
                if (!$existing->fetchColumn()) {
                    email_log_failure(null, 'admin_domain_expiry_report', $recipient['email'], $subject, 'system', $exception->getMessage(), $idempotencyKey);
                }
                $result['failed']++;
                error_log('Domain renewal report failed for admin ' . $recipient['id'] . ': ' . safe_email_error($exception->getMessage()));
            }
        }
        $result['message'] = 'Domain renewal report run completed.';
    } finally {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockName) . ')');
    }
    return $result;
}

function run_google_workspace_renewal_report(bool $send, bool $allowResend = false, ?string $previewOutputPath = null): array
{
    $report = overdue_google_workspace_report_data();
    $result = [
        'as_of_date' => $report['as_of_date'],
        'workspace_count' => $report['workspace_count'],
        'pdf_filename' => null,
        'recipients' => [],
        'sent' => 0,
        'failed' => 0,
        'skipped' => 0,
        'message' => '',
    ];
    if (!$send) {
        $pdf = generate_overdue_google_workspace_report_pdf($report);
        $previewOutputPath ??= rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $pdf['filename'];
        if (!str_ends_with(strtolower($previewOutputPath), '.pdf')) {
            throw new InvalidArgumentException('The preview output path must end in .pdf.');
        }
        $bytesWritten = file_put_contents($previewOutputPath, $pdf['content'], LOCK_EX);
        if ($bytesWritten !== strlen($pdf['content'])) {
            throw new RuntimeException('The Google Workspace PDF preview could not be written completely.');
        }
        $result['pdf_filename'] = $pdf['filename'];
        $result['pdf_path'] = $previewOutputPath;
        $result['message'] = 'Preview generated; no email was sent.';
        return $result;
    }

    $pdo = db();
    $lockName = 'google-workspace-report-' . $report['as_of_date'];
    if ((int) $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lockName) . ',0)')->fetchColumn() !== 1) {
        $result['message'] = 'Another overdue Google Workspace report run is already in progress.';
        return $result;
    }
    try {
        $recipients = [];
        foreach ($pdo->query("SELECT id,name,email FROM users WHERE receive_overdue_alerts=1 AND email<>'' ORDER BY id")->fetchAll() as $row) {
            $email = trim((string) ($row['email'] ?? ''));
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $recipients[mb_strtolower($email)] ??= [
                    'id' => (int) $row['id'],
                    'name' => trim((string) ($row['name'] ?? '')) ?: 'Administrator',
                    'email' => $email,
                ];
            }
        }
        if ($recipients === []) {
            $result['message'] = 'No administrators are opted in to internal alerts; no Google Workspace report was sent.';
            return $result;
        }
        $logType = $pdo->query("SHOW COLUMNS FROM email_log LIKE 'type'")->fetch();
        if (!$logType || !str_contains((string) ($logType['Type'] ?? ''), "'admin_google_workspace_report'")) {
            $result['message'] = 'Run database/migrate_admin_google_workspace_reports.php before sending Google Workspace reports.';
            return $result;
        }
        if (strtolower(mail_setting('MAIL_MODE', 'live')) !== 'live') {
            $result['message'] = 'Overdue Google Workspace reports require MAIL_MODE=live and opted-in administrators.';
            return $result;
        }

        $pdf = generate_overdue_google_workspace_report_pdf($report);
        $result['pdf_filename'] = $pdf['filename'];
        $company = company_email_settings($pdo);
        $companyName = trim((string) ($company['business_name'] ?? '')) ?: 'Breax Invoicing';
        $logoUrl = overdue_report_email_logo_url($company);
        $senderAddress = preg_replace('/\s+/u', ' ', trim((string) ($company['business_address'] ?? ''))) ?: ' ';
        $templatePath = dirname(__DIR__) . '/emails/email-admin-google-workspace-report.html';
        foreach ($recipients as $recipient) {
            $result['recipients'][] = $recipient['email'];
            $prefix = 'google-workspace-report:' . $report['as_of_date'] . ':' . hash('sha256', mb_strtolower($recipient['email']));
            $historyQuery = $pdo->prepare("SELECT status FROM email_log WHERE type='admin_google_workspace_report' AND recipient=? AND idempotency_key LIKE ? ORDER BY id DESC");
            $historyQuery->execute([$recipient['email'], $prefix . ':%']);
            $history = $historyQuery->fetchAll();
            if (!$allowResend && count(array_filter($history, static fn(array $entry): bool => $entry['status'] === 'sent')) > 0) {
                $result['skipped']++;
                continue;
            }
            $key = $prefix . ':' . (count($history) + 1);
            $subject = 'Overdue Google Workspace Report – ' . $report['as_of_date'];
            try {
                $rendered = render_email_template([
                    'ADMIN_NAME' => $recipient['name'],
                    'COMPANY_NAME' => $companyName,
                    'LOGO_URL' => $logoUrl,
                    'AS_OF_DATE' => $report['as_of_date'],
                    'WORKSPACE_COUNT' => (string) $report['workspace_count'],
                    'ATTACHMENT_NAME' => $pdf['filename'],
                    'SENDER_ADDRESS' => $senderAddress,
                ], $templatePath, EMAIL_ADMIN_GOOGLE_WORKSPACE_REPORT_PLACEHOLDERS);
                $delivery = sendEmail([
                    'to' => $recipient['email'],
                    'subject' => $subject,
                    'html' => $rendered['html'],
                    'text' => implode("\n", [
                        $subject,
                        '',
                        'Hello ' . $recipient['name'] . ',',
                        '',
                        'Please find attached the Google Workspace expiry report for ' . $report['as_of_date'] . '.',
                        'The report lists ' . $report['workspace_count'] . ' active account(s) whose associated domain expiry dates have passed, with customer details and days remaining.',
                        '',
                        'Kind regards,',
                        $companyName,
                    ]),
                    'attachments' => [[
                        'filename' => $pdf['filename'],
                        'content' => $pdf['content'],
                        'mime' => 'application/pdf',
                    ]],
                    'idempotencyKey' => $key,
                    'tags' => ['type' => 'admin_google_workspace_report', 'triggered_by' => 'system'],
                ]);
                if (($delivery['status'] ?? '') === 'sent') {
                    $result['sent']++;
                } else {
                    $result['failed']++;
                }
            } catch (Throwable $exception) {
                $existing = $pdo->prepare('SELECT 1 FROM email_log WHERE idempotency_key=?');
                $existing->execute([$key]);
                if (!$existing->fetchColumn()) {
                    email_log_failure(null, 'admin_google_workspace_report', $recipient['email'], $subject, 'system', $exception->getMessage(), $key);
                }
                $result['failed']++;
                error_log('Overdue Google Workspace report failed for admin ' . $recipient['id'] . ': ' . safe_email_error($exception->getMessage()));
            }
        }
        $result['message'] = 'Overdue Google Workspace report run completed.';
    } finally {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockName) . ')');
    }
    return $result;
}
