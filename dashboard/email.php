<?php
require_once dirname(__DIR__) . '/includes/email.php';
require_once dirname(__DIR__) . '/includes/domain_expiry_report.php';
require_once dirname(__DIR__) . '/includes/currency_exchange.php';
require_auth();
$pdo = db();
$success = '';
$error = '';
$action = '';
$reportType = 'all';
$reportResponse = '';
$smtpResponse = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $action = post_string('action', 30, true);
        if ($action === 'clear_email_logs') {
            require_invoice_email_permission();
            $clearedCount = clear_email_logs($pdo);
            $success = sprintf('Cleared %d email log %s.', $clearedCount, $clearedCount === 1 ? 'entry' : 'entries');
        } elseif ($action === 'overdue_check_now') {
            set_time_limit(300);
            require_invoice_email_permission();
            $reportType = post_string('report_type', 30, true);
            $reportTypes = ['all', 'domains', 'invoices', 'google_workspace'];
            if (!in_array($reportType, $reportTypes, true)) {
                throw new InvalidArgumentException('Select a valid report type.');
            }
            $runIndependently = static function (callable $operation, string $label): array {
                try {
                    return $operation();
                } catch (Throwable $exception) {
                    error_log($label . ' failed: ' . safe_email_error($exception->getMessage()));
                    return [
                        'sent' => 0,
                        'failed' => 1,
                        'skipped' => 0,
                        'domain_count' => 0,
                        'invoice_count' => 0,
                        'workspace_count' => 0,
                        'message' => $label . ' could not be completed.',
                    ];
                }
            };
            $alertResult = null;
            if (in_array($reportType, ['all', 'invoices'], true)) {
                $alertResult = $runIndependently(
                    static fn(): array => runOverdueAdminCheck(null, true),
                    'Overdue invoice alerts'
                );
            }
            $reports = [];
            if (in_array($reportType, ['all', 'domains'], true)) {
                $reports['Domain renewal report'] = $runIndependently(
                    static fn(): array => run_domain_renewal_report(true, null, true),
                    'Domain renewal report'
                );
            }
            if (in_array($reportType, ['all', 'invoices'], true)) {
                $reports['Overdue invoices report'] = $runIndependently(
                    static fn(): array => run_overdue_invoice_report(true, true),
                    'Overdue invoice report'
                );
            }
            if (in_array($reportType, ['all', 'google_workspace'], true)) {
                $reports['Google Workspace renewal report'] = $runIndependently(
                    static fn(): array => run_google_workspace_renewal_report(true, true),
                    'Google Workspace renewal report'
                );
            }
            $summaryParts = [];
            if ($alertResult !== null) {
                $summaryParts[] = sprintf(
                    'Overdue alerts: %d sent, %d failed, %d skipped',
                    $alertResult['sent'],
                    $alertResult['failed'],
                    $alertResult['skipped']
                );
            }
            foreach ($reports as $label => $reportResult) {
                $count = $reportResult['domain_count'] ?? $reportResult['invoice_count'] ?? $reportResult['workspace_count'] ?? 0;
                $summaryParts[] = sprintf(
                    '%s: %d sent, %d failed, %d skipped (%d items)',
                    $label,
                    $reportResult['sent'],
                    $reportResult['failed'],
                    $reportResult['skipped'],
                    $count
                );
            }
            $messages = array_filter(array_merge(
                [$alertResult['message'] ?? ''],
                array_map(static fn(array $reportResult): string => $reportResult['message'] ?? '', $reports)
            ));
            $summary = implode('. ', $summaryParts) . ($messages !== [] ? '. ' . implode(' ', $messages) : '') . '.';
            $reportFailed = count(array_filter($reports, static fn(array $reportResult): bool => $reportResult['failed'] > 0)) > 0;
            if (($alertResult !== null && $alertResult['failed'] > 0) || $reportFailed) {
                $error = $summary . ' See the email log for delivery details.';
            } else {
                $success = $summary;
            }
        } elseif ($action === 'smtp_test') {
            $success = test_smtp_connection();
        } elseif ($action === 'sample_send') {
            $recipient = post_string('recipient', 254, true);
            if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Enter a valid test recipient address.');
            }
            $type = post_string('template_type', 40, true);
            $sampleTemplateTypes = array_merge(INVOICE_EMAIL_TYPES, ['invoice_paid', 'invoice_foreign_currency']);
            if (!in_array($type, $sampleTemplateTypes, true)) {
                throw new InvalidArgumentException('Select a valid invoice email template.');
            }
            $settings = company_email_settings($pdo);
            $publicTokenQuery = $pdo->query("SELECT public_token FROM invoices WHERE status NOT IN ('draft','cancelled') AND public_token IS NOT NULL AND public_token<>'' ORDER BY id DESC LIMIT 1");
            $publicToken = $publicTokenQuery->fetchColumn();
            if (!is_string($publicToken) || $publicToken === '') {
                throw new RuntimeException('An issued invoice with an active public link is required to test this template.');
            }
            $publicUrl = public_invoice_url($publicToken);
            $isPartialSample = $type === 'invoice_partial';
            $isOverdueSample = $type === 'invoice_overdue';
            $isPaidSample = $type === 'invoice_paid';
            $isForeignCurrencySample = $type === 'invoice_foreign_currency';
            $invoiceNumber = ($isPartialSample || $isOverdueSample || $isPaidSample)
                ? 'INV-2026-0142'
                : ($isForeignCurrencySample ? 'INV-SAMPLE-FX-0001' : 'INV-SAMPLE-0001');
            $sampleClientName = ($isPartialSample || $isOverdueSample || $isPaidSample) ? 'Amina' : 'Sample Client';
            $sampleIssueDate = $isPartialSample ? '2026-10-13' : date('Y-m-d');
            $sampleDueDate = $isPartialSample
                ? '2026-11-12'
                : ($isOverdueSample
                    ? (new DateTimeImmutable('today', new DateTimeZone('Africa/Nairobi')))->modify('-7 days')->format('Y-m-d')
                    : date('Y-m-d', strtotime('+30 days')));
            $sampleCurrency = 'KES';
            $sampleFxRate = null;
            $sampleFxRateDate = null;
            $sampleTotal = '438480.00';
            if ($isForeignCurrencySample) {
                $sampleCurrency = currency_code() === 'USD' ? 'GBP' : 'USD';
                $sampleFx = kes_rate_for_invoice_date($pdo, $sampleCurrency, $sampleIssueDate);
                $sampleFxRate = $sampleFx['rate'];
                $sampleFxRateDate = $sampleFx['date'];
                $sampleTotal = '3400.00';
            }
            $attachmentName = 'Invoice-' . $invoiceNumber . '.pdf';
            $sampleTransactions = $isPaidSample
                ? [['date' => '18 October 2026', 'method' => 'M-Pesa', 'reference' => 'SAMPLE-PAID', 'amount' => 'KES 438,480']]
                : ($isPartialSample
                    ? [
                        ['date' => '17 October 2026', 'method' => 'Bank transfer', 'reference' => 'SAMPLE-001', 'amount' => 'KES 100,000'],
                        ['date' => '18 October 2026', 'method' => 'M-Pesa', 'reference' => 'SAMPLE-002', 'amount' => 'KES 100,000'],
                    ]
                    : []);
            $sampleTransactionsHtml = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><thead><tr><th align="left">Date</th><th align="left">Method</th><th align="left">Transaction reference</th><th align="right">Amount</th></tr></thead><tbody>';
            foreach ($sampleTransactions as $transaction) {
                $sampleTransactionsHtml .= '<tr><td>' . e($transaction['date']) . '</td><td>' . e($transaction['method'])
                    . '</td><td>' . e($transaction['reference']) . '</td><td align="right">' . e($transaction['amount']) . '</td></tr>';
            }
            $sampleTransactionsHtml .= '</tbody></table>';
            $values = [
                'COMPANY_NAME' => (string) ($settings['business_name'] ?? 'Sample Company'),
                'LOGO_URL' => mail_setting('LOGO_URL'),
                'CLIENT_NAME' => $sampleClientName,
                'CLIENT_EMAIL' => $recipient,
                'INVOICE_NUMBER' => $invoiceNumber,
                'ISSUE_DATE' => date('j F Y', strtotime($sampleIssueDate)),
                'DUE_DATE' => date('j F Y', strtotime($sampleDueDate)),
                'TOTAL' => $isForeignCurrencySample ? email_money(money_to_cents($sampleTotal), $sampleCurrency) : 'KES 438,480',
                'AMOUNT_PAID' => $isPaidSample ? 'KES 438,480' : ($isForeignCurrencySample ? email_money(0, $sampleCurrency) : 'KES 200,000'),
                'BALANCE' => $isPaidSample ? 'KES 0' : ($isForeignCurrencySample ? email_money(money_to_cents($sampleTotal), $sampleCurrency) : 'KES 238,480'),
                'LAST_PAYMENT_DATE' => ($isPartialSample || $isPaidSample) ? '18 October 2026' : date('j F Y'),
                'DAYS_OVERDUE' => $isOverdueSample ? (string) invoice_days_overdue($sampleDueDate) : '7',
                'PUBLIC_INVOICE_URL' => $publicUrl,
                'ATTACHMENT_NAME' => $attachmentName,
                'SUPPORT_EMAIL' => mail_setting('SUPPORT_EMAIL', (string) ($settings['business_email'] ?? 'billing@example.invalid')),
                'SENDER_ADDRESS' => (string) ($settings['business_address'] ?? 'Sample Company address'),
                'TRANSACTIONS_HTML' => $sampleTransactionsHtml,
                'TRANSACTIONS_TEXT' => $sampleTransactions !== []
                    ? implode("\n", array_map(static fn(array $transaction): string => implode(' | ', $transaction), $sampleTransactions))
                    : 'No payments recorded.',
                'EXCHANGE_RATE_BLOCK' => '',
                'EXCHANGE_RATE_TEXT' => '',
                'PAYMENT_DETAILS' => payment_details_html([
                    'mpesa_phone' => 'Sample M-Pesa phone',
                    'mpesa_name' => 'Sample account name',
                    'bank_name' => 'Sample bank',
                    'bank_account_number' => 'Sample account number',
                    'bank_account_name' => 'Sample account name',
                ]),
            ];
            if ($isForeignCurrencySample) {
                $exchangeRateContent = exchange_rate_email_content([
                    'currency_code' => $sampleCurrency,
                    'kes_exchange_rate' => $sampleFxRate,
                    'fx_rate_date' => $sampleFxRateDate,
                    'total' => $sampleTotal,
                ], 'invoice_issued', money_to_cents($sampleTotal), $invoiceNumber);
                if ($exchangeRateContent['html'] === '') {
                    throw new RuntimeException('The foreign-currency sample could not build its exchange-rate information.');
                }
                $values['EXCHANGE_RATE_BLOCK'] = $exchangeRateContent['html'];
                $values['EXCHANGE_RATE_TEXT'] = $exchangeRateContent['text'];
                $rendered = render_email_template($values, dirname(__DIR__) . '/emails/email-invoice-issued.html', EMAIL_ISSUED_PLACEHOLDERS);
                $rendered['text'] = invoice_issued_plain_text($values);
                $subject = 'Sample foreign-currency invoice ' . $values['INVOICE_NUMBER'] . ' from ' . $values['COMPANY_NAME']
                    . ': ' . $values['TOTAL'] . ' due ' . $values['DUE_DATE'];
            } elseif ($type === 'invoice_issued') {
                $rendered = render_email_template($values, dirname(__DIR__) . '/emails/email-invoice-issued.html', EMAIL_ISSUED_PLACEHOLDERS);
                $rendered['text'] = invoice_issued_plain_text($values);
                $subject = 'Invoice ' . $values['INVOICE_NUMBER'] . ' from ' . $values['COMPANY_NAME']
                    . ': ' . $values['TOTAL'] . ' due ' . $values['DUE_DATE'];
            } elseif ($type === 'invoice_partial') {
                $rendered = render_email_template($values, dirname(__DIR__) . '/emails/email-invoice-partially-paid.html', EMAIL_PARTIAL_PLACEHOLDERS);
                $rendered['text'] = invoice_partial_plain_text($values);
                $subject = 'Balance of ' . $values['BALANCE'] . ' remaining on invoice ' . $values['INVOICE_NUMBER'];
            } elseif ($type === 'invoice_paid') {
                $rendered = render_email_template($values, dirname(__DIR__) . '/emails/email-invoice-paid.html', EMAIL_PAID_PLACEHOLDERS);
                $rendered['text'] = invoice_paid_plain_text($values);
                $subject = 'Thank you — invoice ' . $values['INVOICE_NUMBER'] . ' is paid in full';
            } elseif ($type === 'invoice_overdue') {
                $rendered = render_email_template($values, dirname(__DIR__) . '/emails/email-invoice-overdue.html', EMAIL_OVERDUE_PLACEHOLDERS);
                $rendered['text'] = invoice_overdue_plain_text($values);
                $subject = 'Overdue: invoice ' . $values['INVOICE_NUMBER'] . ' was due ' . $values['DUE_DATE'];
            } else {
                $rendered = render_email_template($values);
                $subject = 'Template test: ' . str_replace('_', ' ', $type);
            }
            $samplePayments = $isPartialSample
                ? [
                    ['amount' => '100000.00', 'payment_date' => '2026-10-17', 'method' => 'Bank transfer', 'reference' => 'SAMPLE-001'],
                    ['amount' => '100000.00', 'payment_date' => '2026-10-18', 'method' => 'M-Pesa', 'reference' => 'SAMPLE-002'],
                ]
                : ($isPaidSample
                    ? [['amount' => '438480.00', 'payment_date' => '2026-10-18', 'method' => 'M-Pesa', 'reference' => 'SAMPLE-PAID']]
                    : []);
            $result = sendEmail([
                'to' => $recipient,
                'cc' => [],
                'subject' => $subject,
                'html' => $rendered['html'],
                'text' => $rendered['text'],
                'attachments' => [[
                    'sample_invoice' => true,
                    'invoice_number' => $invoiceNumber,
                    'payments' => $samplePayments,
                    'filename' => $attachmentName,
                    'issue_date' => $sampleIssueDate,
                    'due_date' => $sampleDueDate,
                    'client_name' => $sampleClientName,
                    'currency_code' => $sampleCurrency,
                    'kes_exchange_rate' => $sampleFxRate,
                    'fx_rate_date' => $sampleFxRateDate,
                ]],
                'idempotencyKey' => null,
                'tags' => ['type' => 'template_test', 'triggered_by' => (string) current_user()['name']],
            ]);
            $success = ($result['status'] ?? '') === 'sent'
                ? 'Sample Email template sent successfully'
                : '';
            if (($result['status'] ?? '') !== 'sent') {
                $error = $result['message'] ?? 'The sample email was not sent.';
            }
        } else {
            throw new InvalidArgumentException('Unknown email action.');
        }
    } catch (InvalidArgumentException | RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Email administration action failed: ' . safe_email_error($exception->getMessage()));
        $error = 'The email action could not be completed. Check the email settings and log.';
    }
    if ($action === 'overdue_check_now') {
        $reportResponse = $error !== '' ? $error : $success;
        if (isset($_POST['report_type']) && is_string($_POST['report_type'])) {
            $reportType = $_POST['report_type'];
        }
    } elseif ($action === 'smtp_test') {
        $smtpResponse = $error !== '' ? $error : $success;
    }
}

$sentTodayQuery = $pdo->prepare("SELECT COUNT(*) FROM email_log WHERE status='sent' AND sent_at>=?");
$todayStartUtc = (new DateTimeImmutable('today', new DateTimeZone('Africa/Nairobi')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$sentTodayQuery->execute([$todayStartUtc]);
$sentToday = (int) $sentTodayQuery->fetchColumn();
$maxPerDay = (int) mail_setting('MAIL_MAX_PER_DAY', '400');
$recentLogs = $pdo->query('SELECT email_log.invoice_id,invoices.invoice_number,email_log.type,email_log.recipient,email_log.subject,email_log.status,email_log.error,email_log.triggered_by,email_log.created_at FROM email_log LEFT JOIN invoices ON invoices.id=email_log.invoice_id ORDER BY email_log.created_at DESC,email_log.id DESC LIMIT 100')->fetchAll();
$pageTitle = 'Email delivery';
$activePage = 'email';
$pageSubtitle = 'SMTP, testing, and direct delivery';
require dirname(__DIR__) . '/includes/header.php';
?>
<?php if ($action === 'sample_send' && $success !== ''): ?><div class="notice notice--success" role="status"><?= e($success) ?></div><?php endif; ?>
<?php if ($action === 'sample_send' && $error !== ''): ?><div class="notice notice--error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($action === 'clear_email_logs' && $success !== ''): ?><div class="notice notice--success" role="status"><?= e($success) ?></div><?php endif; ?>
<?php if ($action === 'clear_email_logs' && $error !== ''): ?><div class="notice notice--error" role="alert"><?= e($error) ?></div><?php endif; ?>
<div class="email-delivery-grid">
  <section class="card card--primary">
    <div class="card__head">
      <span class="card__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/></svg></span>
      <div><h2 class="card__title">Admin overdue and domain reports</h2><p class="card__desc">Run overdue invoice alerts and choose which report to send to opted-in admins.</p></div>
    </div>
    <form method="post" data-disable-on-submit data-email-delivery-form>
      <?= csrf_field() ?><input type="hidden" name="action" value="overdue_check_now">
      <div class="card__body">
        <div class="chips"><span class="chip">One PDF per report</span><span class="chip">Admins only</span><span class="chip">Clients never receive</span></div>
        <div class="field">
          <select id="report-type" class="select" name="report_type" required>
            <option value="all"<?= $reportType === 'all' ? ' selected' : '' ?>>All</option>
            <option value="invoices"<?= $reportType === 'invoices' ? ' selected' : '' ?>>Invoice overdue report</option>
            <option value="domains"<?= $reportType === 'domains' ? ' selected' : '' ?>>Domain renewal report</option>
            <option value="google_workspace"<?= $reportType === 'google_workspace' ? ' selected' : '' ?>>Google Workspace renewal report</option>
          </select>
        </div>
        <p class="result<?= $reportResponse !== '' ? ' is-visible' : '' ?><?= $error !== '' && $action === 'overdue_check_now' ? ' result--error' : '' ?>" id="report-result" role="status" tabindex="-1"><?= e($reportResponse) ?></p>
      </div>
      <div class="card__foot"><button class="btn btn--primary" id="run-check" type="submit" data-submitting-label="Checking..."><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 3 14 9-14 9z"/></svg>Run overdue check now</button></div>
    </form>
  </section>
  <section class="card card--secondary">
    <div class="card__head">
      <span class="card__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></span>
      <div><h2 class="card__title">Test SMTP login</h2><p class="card__desc">Connects and authenticates with your mail server without sending an email.</p></div>
    </div>
    <form method="post" data-email-delivery-form>
      <?= csrf_field() ?><input type="hidden" name="action" value="smtp_test">
      <div class="card__body"><div class="chips"><span class="chip">No email sent</span><span class="chip">Safe to run anytime</span></div>
        <p class="result<?= $smtpResponse !== '' ? ' is-visible' : '' ?><?= $error !== '' && $action === 'smtp_test' ? ' result--error' : '' ?>" id="smtp-result" role="status" tabindex="-1"><?= e($smtpResponse) ?></p>
      </div>
      <div class="card__foot"><button class="btn btn--secondary" id="test-smtp" type="submit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg>Test SMTP connection</button></div>
    </form>
  </section>
</div>
<div class="email-delivery-support">
<section class="et-sample card card__pad section-spaced">
    <div class="et-head">
      <h2>Send a sample template</h2>
      <p>Uses synthetic invoice data and sends directly to the recipient entered below.</p>
    </div>
    <form class="et-form" method="post" data-disable-on-submit><?= csrf_field() ?><input type="hidden" name="action" value="sample_send">
      <label class="et-field"><span class="et-field-label">Test recipient <span>*</span></span><input type="email" name="recipient" maxlength="254" value="wosukila@gmail.com" required></label>
      <label class="et-field"><span class="et-field-label">Template <span>*</span></span><select name="template_type" required><option value="invoice_issued">Invoice issued</option><option value="invoice_foreign_currency">Invoice in Foreign Currency</option><option value="invoice_partial">Invoice partially paid</option><option value="invoice_paid">Invoice Paid</option><option value="invoice_overdue">Invoice overdue</option></select></label>
      <button class="btn btn--danger" type="submit" data-submitting-label="Sending...">Send Sample Email</button>
    </form>
  </section>
<section class="card card__pad section-spaced"><div class="card__head"><div><h2 class="heading-lg">Recent email activity</h2><p class="card__sub">Latest 100 email deliveries, including messages without an invoice.</p></div><?php $emailLogClearInvoiceId = null; require dirname(__DIR__) . '/includes/email_log_clear_form.php'; ?></div>
<?php if ($recentLogs): ?><div class="table-wrap"><table id="recent-email-activity" data-enhanced-table data-filter-columns="2,4" data-filter-labels="Type,Status" data-filter-options-4="Sent,Failed"><thead><tr><th data-sort-type="date">Date</th><th>Invoice</th><th>Type</th><th>Recipient</th><th>Status</th></tr></thead><tbody><?php foreach ($recentLogs as $log): ?><tr><td data-sort-value="<?= e($log['created_at']) ?>"><?= e((new DateTimeImmutable($log['created_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Africa/Nairobi'))->format('j M Y H:i')) ?></td><td><?php if ($log['invoice_id'] !== null): ?><a class="link" href="<?= e(app_url('/dashboard/invoice_view.php?id=' . $log['invoice_id'])) ?>"><?= e($log['invoice_number'] ?? $log['invoice_id']) ?></a><?php else: ?>—<?php endif; ?></td><td><?= e(str_replace('_', ' ', $log['type'])) ?></td><td><?= e($log['recipient'] ?? '—') ?></td><td data-filter-value="<?= $log['status'] === 'sent' ? 'Sent' : 'Failed' ?>"><?= e(ucfirst($log['status'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p class="empty">No email activity yet.</p><?php endif; ?>
</section>
 </div>
<script src="<?= e(app_url('/assets/js/email-delivery.js')) ?>?v=<?= e((string) (filemtime(dirname(__DIR__) . '/assets/js/email-delivery.js') ?: 1)) ?>" defer></script>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
