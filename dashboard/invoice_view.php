<?php
require_once dirname(__DIR__) . '/includes/email.php';
require_once dirname(__DIR__) . '/includes/currency_exchange.php';
require_once dirname(__DIR__) . '/includes/customer_domains.php';
require_auth();
$pdo = db();
$invoiceId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$pageNotice = null;
$paymentDialogOpen = false;
$paymentAmountError = '';
$paymentDateError = '';
$paymentMethodError = '';
$paymentReferenceError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_payment') {
    verify_csrf();
    try {
        $submittedInvoiceId = filter_var($_POST['invoice_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$submittedInvoiceId || $submittedInvoiceId !== $invoiceId) {
            throw new InvalidArgumentException('Invoice not found.');
        }
        try {
            $amountCents = money_to_cents($_POST['amount'] ?? '');
        } catch (InvalidArgumentException $exception) {
            $paymentAmountError = 'Enter a valid payment amount.';
            throw $exception;
        }
        $paymentDate = post_string('payment_date', 10, true);
        $method = post_string('method', 60, true);
        $reference = post_string('reference', 150);
        if ($reference === '') {
            $paymentReferenceError = 'Enter a transaction reference.';
            throw new InvalidArgumentException('Enter a transaction reference.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $paymentDate);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($amountCents <= 0) {
            $paymentAmountError = 'Enter a payment amount above zero.';
            throw new InvalidArgumentException($paymentAmountError);
        }
        if (!$date || $date->format('Y-m-d') !== $paymentDate
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $paymentDate > date('Y-m-d')) {
            $paymentDateError = 'Choose a valid payment date no later than today.';
            throw new InvalidArgumentException($paymentDateError);
        }
        if (!in_array($method, PAYMENT_METHODS, true)) {
            $paymentMethodError = 'Choose a valid payment method.';
            throw new InvalidArgumentException($paymentMethodError);
        }

        $pdo->beginTransaction();
        $invoiceLock = $pdo->prepare('SELECT id,total,status,due_date,currency_code FROM invoices WHERE id=? FOR UPDATE');
        $invoiceLock->execute([$invoiceId]);
        $lockedInvoice = $invoiceLock->fetch();
        if (!$lockedInvoice || in_array($lockedInvoice['status'], ['draft', 'cancelled', 'paid'], true)) {
            throw new InvalidArgumentException('Payments can only be recorded against an issued invoice with an outstanding balance.');
        }
        $duplicateReference = $pdo->prepare('SELECT 1 FROM payments WHERE invoice_id=? AND reference=? LIMIT 1');
        $duplicateReference->execute([$invoiceId, $reference]);
        if ($duplicateReference->fetchColumn()) {
            $paymentReferenceError = 'This transaction reference is already used for this invoice.';
            throw new InvalidArgumentException($paymentReferenceError);
        }
        $paidQuery = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=?');
        $paidQuery->execute([$invoiceId]);
        $paidCents = money_to_cents($paidQuery->fetchColumn());
        $totalCents = money_to_cents($lockedInvoice['total']);
        $balanceCents = max(0, $totalCents - $paidCents);
        if ($balanceCents === 0 || $amountCents > $balanceCents) {
            $balanceLabel = invoice_currency($lockedInvoice) . ' ' . rtrim(rtrim(number_format($balanceCents / 100, 2, '.', ','), '0'), '.');
            $paymentAmountError = 'Payment cannot exceed the outstanding invoice balance of ' . $balanceLabel . '.';
            throw new InvalidArgumentException($paymentAmountError);
        }
        $insertPayment = $pdo->prepare('INSERT INTO payments (invoice_id,amount,payment_date,method,reference,created_by) VALUES (?,?,?,?,?,?)');
        $insertPayment->execute([$invoiceId, cents_to_decimal($amountCents), $paymentDate, $method, $reference, current_user()['id']]);
        $newPaidCents = $paidCents + $amountCents;
        $newStatus = $newPaidCents >= $totalCents
            ? 'paid'
            : ($lockedInvoice['due_date'] < date('Y-m-d') ? 'overdue' : 'partially_paid');
        $pdo->prepare('UPDATE invoices SET status=? WHERE id=?')->execute([$newStatus, $invoiceId]);
        $pdo->commit();

        try {
            $emailResult = sendInvoiceEmail((int) $invoiceId, 'payment_received', 'system');
            $pageNotice = [
                'message' => $emailResult['status'] === 'sent'
                    ? 'Payment recorded successfully. The customer payment confirmation email was sent to ' . (string) $emailResult['recipient'] . '.'
                    : 'Payment recorded successfully, but the customer payment confirmation email could not be sent: '
                        . (string) ($emailResult['message'] ?? 'Email delivery failed.'),
                'type' => $emailResult['status'] === 'sent' ? 'success' : 'error',
            ];
        } catch (Throwable $mailException) {
            error_log('Payment confirmation email failed from invoice view: ' . safe_email_error($mailException->getMessage()));
            $pageNotice = [
                'message' => 'Payment recorded successfully, but the customer payment confirmation email could not be sent. Check the invoice email log and settings.',
                'type' => 'error',
            ];
        }
    } catch (InvalidArgumentException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $paymentDialogOpen = true;
        if ($paymentAmountError !== '' || $paymentDateError !== '' || $paymentMethodError !== '' || $paymentReferenceError !== '') {
            $pageNotice = null;
        } else {
            $pageNotice = ['message' => $exception->getMessage(), 'type' => 'error'];
        }
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Payment recording from invoice view failed: ' . $exception->getMessage());
        $paymentDialogOpen = true;
        $duplicateReference = str_contains($exception->getMessage(), 'uq_payment_invoice_reference');
        if ($duplicateReference) {
            $paymentReferenceError = 'This transaction reference is already used for this invoice.';
        }
        $pageNotice = $duplicateReference
            ? null
            : ['message' => 'The payment could not be recorded. Check the invoice and try again.', 'type' => 'error'];
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'record_payment') {
    verify_csrf();
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'clear_email_logs') {
            require_invoice_email_permission();
            $submittedInvoiceId = filter_var($_POST['invoice_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$submittedInvoiceId || $submittedInvoiceId !== $invoiceId) {
                throw new InvalidArgumentException('Invoice not found.');
            }
            $clearedCount = clear_email_logs($pdo, (int) $invoiceId);
            flash(sprintf('Cleared %d email log %s for this invoice.', $clearedCount, $clearedCount === 1 ? 'entry' : 'entries'));
            redirect('/dashboard/invoice_view.php?id=' . $invoiceId);
        }
        if ($action === 'send_email_log') {
            require_invoice_email_permission();
            $logId = filter_var($_POST['email_log_id'] ?? null, FILTER_VALIDATE_INT);
            $emailLogQuery = $pdo->prepare("SELECT type FROM email_log
                WHERE id=? AND invoice_id=? AND type IN ('invoice_issued','invoice_partial','invoice_overdue','payment_received')");
            $emailLogQuery->execute([$logId, $invoiceId]);
            $emailType = $emailLogQuery->fetchColumn();
            if (!$logId || !is_string($emailType)) {
                throw new InvalidArgumentException('This email cannot be resent from the invoice log.');
            }
            $resendResult = sendInvoiceEmail((int) $invoiceId, $emailType, (string) current_user()['name'], true);
            $recipient = trim((string) ($resendResult['recipient'] ?? ''));
            $sent = $resendResult['status'] === 'sent';
            flash(
                $sent
                    ? 'Email sent to ' . $recipient . '.'
                    : 'Email could not be sent: ' . (string) ($resendResult['message'] ?? 'Email delivery failed.'),
                $sent ? 'success' : 'error'
            );
            redirect('/dashboard/invoice_view.php?id=' . $invoiceId);
        }
        if (in_array($action, ['invoice_partial', 'invoice_overdue'], true)) {
            require_invoice_email_permission();
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT id FROM invoices WHERE id=? FOR UPDATE');
            $lock->execute([$invoiceId]);
            if (!$lock->fetchColumn()) {
                throw new InvalidArgumentException('Invoice not found.');
            }
            $pdo->commit();
            try {
                $result = sendInvoiceEmail((int) $invoiceId, $action, (string) current_user()['name']);
                $recipient = trim((string) ($result['recipient'] ?? ''));
                if ($result['status'] === 'sent') {
                    $deliveryMessage = 'Email sent to ' . $recipient . '.';
                } else {
                    $deliveryMessage = 'Failed: ' . ($result['message'] ?? 'Email delivery failed.');
                }
                flash($deliveryMessage, $result['status'] === 'sent' ? 'success' : 'error');
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }
                flash(safe_email_error($exception->getMessage()), 'error');
            }
            redirect('/dashboard/invoice_view.php?id=' . $invoiceId);
        }
        $fxSnapshot = null;
        if ($action === 'issue') {
            $fxInvoiceQuery = $pdo->prepare('SELECT currency_code,issue_date FROM invoices WHERE id=?');
            $fxInvoiceQuery->execute([$invoiceId]);
            $fxInvoice = $fxInvoiceQuery->fetch();
            if (!$fxInvoice) {
                throw new InvalidArgumentException('Invoice not found.');
            }
            $fxSnapshot = kes_rate_for_invoice_date($pdo, (string) $fxInvoice['currency_code'], (string) $fxInvoice['issue_date']);
            $issueCustomerQuery = $pdo->prepare('SELECT customer_id FROM invoices WHERE id=?');
            $issueCustomerQuery->execute([$invoiceId]);
            $issueCustomerId = (int) $issueCustomerQuery->fetchColumn();
        }
        $pdo->beginTransaction();
        if ($action === 'issue') {
            $customerLock = $pdo->prepare('SELECT id FROM customers WHERE id=? FOR UPDATE');
            $customerLock->execute([$issueCustomerId]);
            if (!$customerLock->fetchColumn()) {
                throw new InvalidArgumentException('Invoice customer not found.');
            }
        }
        $lock = $pdo->prepare('SELECT id,status,issue_date,customer_id,domain_id,domain_name,recipient_email,company_name_snapshot,billing_address_snapshot,phone_number_snapshot FROM invoices WHERE id=? FOR UPDATE');
        $lock->execute([$invoiceId]);
        $lockedInvoice = $lock->fetch();
        if (!$lockedInvoice) {
            throw new InvalidArgumentException('Invoice not found.');
        }
        if ($action === 'issue' && (int) $lockedInvoice['customer_id'] !== $issueCustomerId) {
            throw new InvalidArgumentException('The invoice customer changed. Reload the invoice and try again.');
        }
        if (($_POST['action'] ?? '') === 'delete') {
            if (!in_array($lockedInvoice['status'], ['draft', 'cancelled'], true)) {
                throw new InvalidArgumentException('Only draft or cancelled invoices can be deleted.');
            }
            $paymentCheck = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE invoice_id=?');
            $paymentCheck->execute([$invoiceId]);
            if ((int) $paymentCheck->fetchColumn() > 0) {
                throw new InvalidArgumentException('An invoice with recorded payments cannot be deleted.');
            }
            $pdo->prepare('DELETE FROM invoices WHERE id=?')->execute([$invoiceId]);
            $pdo->commit();
            flash('Invoice deleted.');
            redirect('/dashboard/invoices.php');
        }
        if (($_POST['action'] ?? '') === 'cancel') {
            $paymentCheck = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE invoice_id=?');
            $paymentCheck->execute([$invoiceId]);
            if ($lockedInvoice['status'] !== 'draft' || (int) $paymentCheck->fetchColumn() > 0) {
                throw new InvalidArgumentException('Only draft invoices without recorded payments can be cancelled.');
            }
            $pdo->prepare("UPDATE invoices SET status='cancelled' WHERE id=?")->execute([$invoiceId]);
            $pdo->commit();
            flash('Invoice cancelled.');
            redirect('/dashboard/invoice_view.php?id=' . $invoiceId);
        }
        if ($action !== 'issue') {
            throw new InvalidArgumentException('Unknown invoice action.');
        }
        if ($lockedInvoice['status'] !== 'draft') {
            throw new InvalidArgumentException('Only draft invoices can be issued.');
        }
        $customerQuery = $pdo->prepare('SELECT invoice_delivery FROM customers WHERE id=?');
        $customerQuery->execute([(int) $lockedInvoice['customer_id']]);
        $delivery = $customerQuery->fetchColumn();
        $recipientSnapshot = customer_domain_recipient(
            $pdo,
            (int) $lockedInvoice['customer_id'],
            $delivery === 'domain' && $lockedInvoice['domain_id'] !== null
                ? (int) $lockedInvoice['domain_id']
                : null,
            trim((string) ($lockedInvoice['company_name_snapshot'] ?? '')) === '',
            true
        );
        if ($delivery !== 'domain') {
            $recipientSnapshot['domain_id'] = $lockedInvoice['domain_id'];
            $recipientSnapshot['domain_name'] = $lockedInvoice['domain_name'];
        }
        if (trim((string) ($lockedInvoice['company_name_snapshot'] ?? '')) !== '') {
            $recipientSnapshot['domain_id'] = $lockedInvoice['domain_id'];
            $recipientSnapshot['domain_name'] = $lockedInvoice['domain_name'];
            $recipientSnapshot['recipient_email'] = $lockedInvoice['recipient_email'];
            $recipientSnapshot['company_name_snapshot'] = $lockedInvoice['company_name_snapshot'];
            $recipientSnapshot['billing_address_snapshot'] = $lockedInvoice['billing_address_snapshot'];
            $recipientSnapshot['phone_number_snapshot'] = $lockedInvoice['phone_number_snapshot'];
        }
        $prefixQuery = $pdo->prepare("SELECT setting_value FROM company_settings WHERE setting_key='invoice_prefix'");
        $prefixQuery->execute();
        $prefix = $prefixQuery->fetchColumn() ?: 'INV';
        $number = $prefix . '-' . date('Y', strtotime($lockedInvoice['issue_date'])) . '-' . str_pad((string) $invoiceId, 6, '0', STR_PAD_LEFT);
        $pdo->prepare("UPDATE invoices SET status='issued',invoice_number=?,domain_id=?,domain_name=?,recipient_email=?,company_name_snapshot=?,billing_address_snapshot=?,phone_number_snapshot=?,kes_exchange_rate=?,fx_rate_date=? WHERE id=?")->execute([$number, $recipientSnapshot['domain_id'], $recipientSnapshot['domain_name'], $recipientSnapshot['recipient_email'], $recipientSnapshot['company_name_snapshot'], $recipientSnapshot['billing_address_snapshot'], $recipientSnapshot['phone_number_snapshot'], $fxSnapshot['rate'], $fxSnapshot['date'], $invoiceId]);
        create_public_invoice_link($pdo, (int) $invoiceId);
        $pdo->commit();
        try {
            $emailResult = sendInvoiceEmail((int) $invoiceId, 'invoice_issued', 'system');
            $notice = invoice_issued_email_notice($emailResult);
            $noticeMessages = [$notice['message']];
            $noticeType = $notice['type'];
        } catch (Throwable $mailException) {
            error_log('Automatic invoice email failed: ' . safe_email_error($mailException->getMessage()));
            $noticeMessages = ['Invoice issued, but its email could not be sent. Check the email log and settings.'];
            $noticeType = 'error';
        }
        $recordedPayments = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=?');
        $recordedPayments->execute([$invoiceId]);
        if (money_to_cents($recordedPayments->fetchColumn()) > 0) {
            try {
                $paymentEmailResult = sendInvoiceEmail((int) $invoiceId, 'payment_received', 'system');
                $paymentNotice = payment_received_email_notice($paymentEmailResult);
                $noticeMessages[] = $paymentNotice['message'];
                if ($paymentNotice['type'] === 'error') {
                    $noticeType = 'error';
                }
            } catch (Throwable $mailException) {
                error_log('Payment confirmation email failed after draft invoice issue: ' . safe_email_error($mailException->getMessage()));
                $noticeMessages[] = 'The customer payment confirmation email could not be sent. Check the invoice email log and settings.';
                $noticeType = 'error';
            }
        }
        flash(implode(' ', $noticeMessages), $noticeType);
        redirect('/dashboard/invoice_view.php?id=' . $invoiceId);
    } catch (InvalidArgumentException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash($exception->getMessage(), 'error');
        redirect('/dashboard/invoice_view.php?id=' . (int) $invoiceId);
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Invoice issue failed during a database operation.');
        flash('The invoice could not be issued.', 'error');
        redirect('/dashboard/invoice_view.php?id=' . (int) $invoiceId);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Invoice email or update failed: ' . safe_email_error($exception->getMessage()));
        flash('The request could not be completed. Check the invoice email log and settings.', 'error');
        redirect('/dashboard/invoice_view.php?id=' . (int) $invoiceId);
    }
}
$statement = $pdo->prepare("SELECT i.*,c.name customer_name,c.contact_person,c.email customer_email,
    COALESCE(NULLIF(TRIM(i.recipient_email),''),c.email) invoice_recipient,
    c.phone,c.billing_address,COALESCE(p.paid_amount,0) paid_amount FROM invoices i JOIN customers c ON c.id=i.customer_id
    LEFT JOIN (SELECT invoice_id,SUM(amount) paid_amount FROM payments GROUP BY invoice_id) p ON p.invoice_id=i.id WHERE i.id=?");
$statement->execute([$invoiceId]);
$invoice = $statement->fetch();
if (!$invoice) {
    http_response_code(404);
    exit('Invoice not found.');
}
apply_invoice_party_snapshot($invoice);
$itemsStatement = $pdo->prepare('SELECT ii.description,ii.quantity,ii.unit_price,ii.line_total,p.name catalogue_name,p.category catalogue_category,p.billing_cycle catalogue_billing_cycle FROM invoice_items ii LEFT JOIN products p ON p.id=ii.product_id WHERE ii.invoice_id=? ORDER BY ii.id');
$itemsStatement->execute([$invoiceId]);
$items = $itemsStatement->fetchAll();
$paymentsStatement = $pdo->prepare('SELECT amount,payment_date,method,reference FROM payments WHERE invoice_id=? ORDER BY payment_date ASC,id ASC');
$paymentsStatement->execute([$invoiceId]);
$payments = $paymentsStatement->fetchAll();
$hasPayments = count($payments) > 0;
$paymentsTotalCents = array_reduce(
    $payments,
    static fn(int $total, array $payment): int => $total + money_to_cents($payment['amount']),
    0
);
$balanceCents = max(0, money_to_cents($invoice['total']) - $paymentsTotalCents);
$balanceLabel = invoice_currency($invoice) . ' ' . rtrim(rtrim(number_format($balanceCents / 100, 2, '.', ','), '0'), '.');
$company = [];
foreach ($pdo->query('SELECT setting_key,setting_value FROM company_settings WHERE setting_key IN ("business_name","business_email","business_phone","business_address","payment_instructions","payment_terms","company_logo")')->fetchAll() as $row) {
    $company[$row['setting_key']] = $row['setting_value'];
}
$logoFilename = (string) ($company['company_logo'] ?? '');
$logoUrl = preg_match('/\Acompany-logo-[a-f0-9]{32}\.(?:png|jpg|webp)\z/', $logoFilename)
    ? app_url('/assets/uploads/' . rawurlencode($logoFilename))
    : '';
$status = invoice_status($invoice);
$draftIssueBlockMessage = '';
if ($status === 'draft') {
    $deliveryQuery = $pdo->prepare('SELECT invoice_delivery FROM customers WHERE id=?');
    $deliveryQuery->execute([(int) $invoice['customer_id']]);
    $currentDelivery = $deliveryQuery->fetchColumn();
    try {
        customer_domain_recipient(
            $pdo,
            (int) $invoice['customer_id'],
            $currentDelivery === 'domain' && $invoice['domain_id'] !== null ? (int) $invoice['domain_id'] : null,
            true
        );
    } catch (InvalidArgumentException $exception) {
        $draftIssueBlockMessage = $exception->getMessage();
    }
}
$canSendInvoiceEmails = can_send_invoice_emails();
$partialEmailEligibility = invoice_email_eligibility($invoice, 'invoice_partial');
$overdueEmailEligibility = invoice_email_eligibility($invoice, 'invoice_overdue');
$billingEmail = trim((string) ($invoice['invoice_recipient'] ?? ''));
if ($billingEmail === '' || !filter_var($billingEmail, FILTER_VALIDATE_EMAIL)) {
    $partialEmailEligibility = ['eligible' => false, 'reason' => 'Client billing email is missing or invalid'];
    $overdueEmailEligibility = ['eligible' => false, 'reason' => 'Client billing email is missing or invalid'];
} else {
    $blockedEmailQuery = $pdo->prepare('SELECT 1 FROM email_bounced_addresses WHERE address=?');
    $blockedEmailQuery->execute([mb_strtolower($billingEmail)]);
    if ($blockedEmailQuery->fetchColumn()) {
        $partialEmailEligibility = ['eligible' => false, 'reason' => 'Client billing email previously bounced'];
        $overdueEmailEligibility = ['eligible' => false, 'reason' => 'Client billing email previously bounced'];
    }
}
$partialLatestQuery = $pdo->prepare("SELECT triggered_by,status,sent_at FROM email_log WHERE invoice_id=? AND type='invoice_partial' AND status='sent' ORDER BY created_at DESC,id DESC LIMIT 1");
$partialLatestQuery->execute([$invoiceId]);
$partialLatest = $partialLatestQuery->fetch();
$overdueLatestQuery = $pdo->prepare("SELECT triggered_by,status,sent_at FROM email_log WHERE invoice_id=? AND type='invoice_overdue' AND status='sent' ORDER BY created_at DESC,id DESC LIMIT 1");
$overdueLatestQuery->execute([$invoiceId]);
$overdueLatest = $overdueLatestQuery->fetch();
$adminAlertLatestQuery = $pdo->prepare("SELECT sent_at FROM email_log WHERE invoice_id=? AND type='admin_overdue_alert' AND status='sent' AND sent_at IS NOT NULL ORDER BY sent_at DESC,id DESC LIMIT 1");
$adminAlertLatestQuery->execute([$invoiceId]);
$adminAlertLatest = $adminAlertLatestQuery->fetchColumn();
$emailLogQuery = $pdo->prepare('SELECT id,type,recipient,subject,status,error,triggered_by,created_at,sent_at,text_body FROM email_log WHERE invoice_id=? ORDER BY created_at DESC,id DESC');
$emailLogQuery->execute([$invoiceId]);
$emailLogs = $emailLogQuery->fetchAll();
$bouncedAddressQuery = $pdo->prepare('SELECT reason FROM email_bounced_addresses WHERE address=?');
$bouncedAddressQuery->execute([mb_strtolower($billingEmail)]);
$bouncedAddressReason = $bouncedAddressQuery->fetchColumn();
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
$publicLinkConfigurationError = '';
if (is_string($invoice['public_token'] ?? null) && $invoice['public_token'] !== '') {
    try {
        $publicInvoiceUrl = public_invoice_url($invoice['public_token']);
    } catch (InvalidArgumentException $exception) {
        $publicLinkConfigurationError = $exception->getMessage();
    }
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
$canRecordPayment = !in_array($status, ['draft', 'cancelled', 'paid'], true) && $balanceCents > 0;
$pageTitle = $invoice['invoice_number'] ?: 'Draft invoice';
$activePage = 'invoices';
$pageSubtitle = 'Invoice details';
require dirname(__DIR__) . '/includes/header.php';
?>
<div class="page-head no-print"><div><h1 class="display-lg"><?= e($pageTitle) ?></h1><p class="page-head__sub"><?= e($invoice['customer_name']) ?> · <span class="pill pill--<?= $status === 'paid' ? 'paid' : ($status === 'overdue' ? 'overdue' : 'pending') ?>"><?= e(status_label($status)) ?></span></p><?php if ($invoice['domain_name']): ?><p class="page-head__sub">Domain: <?= e($invoice['domain_name']) ?></p><?php endif; ?></div><div class="row-actions"><?php if ($status === 'draft'): ?><a class="action-pill action-pill--edit" href="<?= e(app_url('/dashboard/create_invoice.php?id=' . $invoice['id'])) ?>">Edit draft</a><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="issue"><button class="action-pill action-pill--issue" type="submit">Issue invoice</button></form><?php if (!$payments): ?><form method="post" data-confirm="Are you sure you want to cancel this invoice? This cannot be undone." data-confirm-title="Cancel invoice" data-confirm-danger="true"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="action-pill action-pill--end" type="submit">Cancel invoice</button></form><?php endif; ?><?php endif; ?><button class="action-pill action-pill--neutral" type="button" onclick="window.print()">Print / Save PDF</button></div></div>
<?php if ($pageNotice !== null): ?><div class="notice notice--<?= e($pageNotice['type']) ?> no-print" role="status" aria-live="polite"><?= e($pageNotice['message']) ?></div><?php endif; ?>
<?php if ($draftIssueBlockMessage !== ''): ?><div class="notice notice--warning no-print" role="status"><?= e($draftIssueBlockMessage) ?> Edit this draft to correct the customer or domain selection before issuing.</div><?php endif; ?>
<?php if ($publicLinkConfigurationError !== ''): ?><div class="notice notice--error no-print" role="alert"><?= e($publicLinkConfigurationError) ?></div><?php endif; ?>
<?php if (is_string($bouncedAddressReason) && $bouncedAddressReason !== ''): ?><div class="notice notice--error no-print" role="alert">Email warning: this client's billing address was permanently rejected by the mail server. Update the client email address before sending.</div><?php endif; ?>
<?php if ($canSendInvoiceEmails): ?>
<section class="invoice-email-toolbar invoice-email-toolbar--constrained no-print" aria-label="Invoice email reminders" data-invoice-email-toolbar>
  <div class="invoice-email-toolbar__item">
    <form method="post" data-disable-on-submit<?= !$partialEmailEligibility['eligible'] ? ' title="' . e($partialEmailEligibility['reason']) . '"' : '' ?> data-confirm="Send partial payment reminder to <?= e($billingEmail !== '' ? $billingEmail : 'the client billing address') ?>, with the invoice PDF attached?" data-confirm-title="Confirm email" data-confirm-cancel-label="Cancel" data-confirm-accept-label="Send">
      <?= csrf_field() ?><input type="hidden" name="action" value="invoice_partial">
      <button class="invoice-email-toolbar__button" type="submit"<?= !$partialEmailEligibility['eligible'] ? ' disabled title="' . e($partialEmailEligibility['reason']) . '"' : '' ?>>Send partial payment reminder</button>
    </form>
    <p class="invoice-email-toolbar__meta" aria-live="polite"><?php if ($partialLatest): ?>Last sent: <?= e((new DateTimeImmutable($partialLatest['sent_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Africa/Nairobi'))->format('j F Y, H:i')) ?> by <?= e($partialLatest['triggered_by']) ?><?php else: ?>Not sent yet<?php endif; ?></p>
    <?php if (!$partialEmailEligibility['eligible']): ?><p class="invoice-email-toolbar__reason"><?= e($partialEmailEligibility['reason']) ?></p><?php endif; ?>
  </div>
  <div class="invoice-email-toolbar__item">
    <form method="post" data-disable-on-submit data-confirm-danger="true"<?= !$overdueEmailEligibility['eligible'] ? ' title="' . e($overdueEmailEligibility['reason']) . '"' : '' ?> data-confirm="Send overdue reminder to <?= e($billingEmail !== '' ? $billingEmail : 'the client billing address') ?>, with the invoice PDF attached?" data-confirm-title="Confirm email" data-confirm-cancel-label="Cancel" data-confirm-accept-label="Send">
      <?= csrf_field() ?><input type="hidden" name="action" value="invoice_overdue">
      <button class="invoice-email-toolbar__button" type="submit"<?= !$overdueEmailEligibility['eligible'] ? ' disabled title="' . e($overdueEmailEligibility['reason']) . '"' : '' ?>>Send overdue reminder</button>
    </form>
    <p class="invoice-email-toolbar__meta" aria-live="polite"><?php if ($overdueLatest): ?>Last sent: <?= e((new DateTimeImmutable($overdueLatest['sent_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Africa/Nairobi'))->format('j F Y, H:i')) ?> by <?= e($overdueLatest['triggered_by']) ?><?php else: ?>Not sent yet<?php endif; ?></p>
    <?php if (!$overdueEmailEligibility['eligible']): ?><p class="invoice-email-toolbar__reason"><?= e($overdueEmailEligibility['reason']) ?></p><?php endif; ?>
  </div>
  <p class="invoice-email-toolbar__result" data-invoice-email-result role="status" aria-live="polite"></p>
</section>
<?php endif; ?>
<?php if ($canSendInvoiceEmails): ?>
<?php $adminAlertSent = $adminAlertLatest !== false && $adminAlertLatest !== null; ?>
<div class="admin-alert-status-wrap no-print">
<section class="admin-alert-status<?= $adminAlertSent ? ' is-sent' : '' ?>" data-admin-alert-status data-sent="<?= $adminAlertSent ? 'true' : 'false' ?>" data-endpoint="<?= e(app_url('/dashboard/admin_alert.php?id=' . (int) $invoiceId)) ?>" aria-label="Admin alert status">
  <span class="admin-alert-status__icon<?= $adminAlertSent ? ' is-sent' : '' ?>" data-admin-alert-icon aria-hidden="true">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9a6 6 0 0 1 12 0c0 6 2 7 2 7H4s2-1 2-7"/><path d="M10 20a2 2 0 0 0 4 0"/></svg>
  </span>
  <div class="admin-alert-status__copy" aria-live="polite">
    <div class="admin-alert-status__title-row">
      <h2 class="admin-alert-status__title">Admin alert</h2>
      <span class="admin-alert-status__badge<?= $adminAlertSent ? ' is-sent' : '' ?>" data-admin-alert-badge><?= $adminAlertSent ? 'Sent' : 'Not sent' ?></span>
    </div>
    <p class="admin-alert-status__helper" data-admin-alert-helper><?= $adminAlertSent ? 'Alert was sent. You can send it again.' : 'Alerts are sent automatically. Nothing for you to do.' ?></p>
    <p class="admin-alert-status__feedback notice" data-admin-alert-feedback role="status" aria-live="polite" hidden></p>
  </div>
  <button class="admin-alert-status__button" data-admin-alert-send type="button" title="<?= $adminAlertSent ? 'Send this alert again' : 'Alerts are sent automatically' ?>"<?= $adminAlertSent ? '' : ' disabled aria-disabled="true"' ?>>Send alert</button>
</section>
</div>
<?php endif; ?>
<?php require dirname(__DIR__) . '/includes/invoice_document.php'; ?>
<div class="invoice-view-actions no-print section-spaced"><a class="btn btn--back" href="<?= e(app_url('/dashboard/invoices.php')) ?>">Back to invoices</a><?php if ($status === 'paid'): ?><a class="btn btn--delete-invoice" href="<?= e(app_url('/dashboard/create_invoice.php')) ?>">Create an Invoice</a><?php endif; ?><?php if ($status === 'draft'): ?><a class="btn btn--edit-invoice" href="<?= e(app_url('/dashboard/create_invoice.php?id=' . $invoice['id'])) ?>">Edit invoice</a><form method="post" data-confirm="Are you sure you want to permanently delete this draft invoice? This cannot be undone." data-confirm-title="Delete draft invoice" data-confirm-danger="true"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn btn--delete-invoice" type="submit">Delete Invoice</button></form><?php elseif ($status === 'cancelled'): ?><form method="post" data-confirm="Are you sure you want to permanently delete this cancelled invoice? This cannot be undone." data-confirm-title="Delete cancelled invoice" data-confirm-danger="true"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn btn--delete-invoice" type="submit">Delete Invoice</button></form><?php endif; ?><?php if ($canRecordPayment): ?><button class="btn btn--primary" type="button" id="open-invoice-payment-dialog">Record a payment</button><?php endif; ?></div>
<?php if ($canRecordPayment): ?>
<dialog class="invoice-payment-dialog" id="invoice-payment-dialog" aria-labelledby="invoice-payment-title" data-open="<?= $paymentDialogOpen ? 'true' : 'false' ?>" data-balance-label="<?= e($balanceLabel) ?>" data-existing-references="<?= e(json_encode(array_column($payments, 'reference'), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)) ?>">
  <div class="invoice-payment-dialog__panel">
    <h2 id="invoice-payment-title">Record a payment</h2>
    <form class="data-form" method="post" action="<?= e(app_url('/dashboard/invoice_view.php?id=' . $invoice['id'])) ?>" data-validation-managed>
      <?= csrf_field() ?><input type="hidden" name="action" value="record_payment"><input type="hidden" name="invoice_id" value="<?= e($invoice['id']) ?>">
      <label class="field"><span class="field__label">Amount (<?= e(invoice_currency($invoice)) ?>) *</span><input class="input" type="number" name="amount" min="0.01" max="<?= e(cents_to_decimal($balanceCents)) ?>" step="0.01" required aria-describedby="invoice-payment-amount-error" value="<?= e(is_string($_POST['amount'] ?? null) || is_numeric($_POST['amount'] ?? null) ? (string) $_POST['amount'] : '') ?>"><span class="field__error" id="invoice-payment-amount-error" role="alert" aria-live="polite"<?= $paymentAmountError === '' ? ' hidden' : '' ?>><?= e($paymentAmountError) ?></span></label>
      <label class="field"><span class="field__label">Payment date *</span><input class="input" type="date" name="payment_date" max="<?= e(date('Y-m-d')) ?>" required aria-describedby="invoice-payment-date-error" value="<?= e(is_string($_POST['payment_date'] ?? null) ? $_POST['payment_date'] : date('Y-m-d')) ?>"><span class="field__error" id="invoice-payment-date-error" role="alert" aria-live="polite"<?= $paymentDateError === '' ? ' hidden' : '' ?>><?= e($paymentDateError) ?></span></label>
      <label class="field"><span class="field__label">Payment method *</span><select class="input" name="method" required aria-describedby="invoice-payment-method-error"><option value="">Select a method</option><?php foreach (PAYMENT_METHODS as $method): ?><option value="<?= e($method) ?>"<?= (is_string($_POST['method'] ?? null) ? $_POST['method'] : '') === $method ? ' selected' : '' ?>><?= e($method) ?></option><?php endforeach; ?></select><span class="field__error" id="invoice-payment-method-error" role="alert" aria-live="polite"<?= $paymentMethodError === '' ? ' hidden' : '' ?>><?= e($paymentMethodError) ?></span></label>
      <label class="field"><span class="field__label">Transaction reference *</span><input class="input" name="reference" maxlength="150" required aria-describedby="invoice-payment-reference-error" value="<?= e(is_string($_POST['reference'] ?? null) ? $_POST['reference'] : '') ?>"><span class="field__error" id="invoice-payment-reference-error" role="alert" aria-live="polite"<?= $paymentReferenceError === '' ? ' hidden' : '' ?>><?= e($paymentReferenceError) ?></span></label>
      <div class="invoice-payment-dialog__actions"><button class="btn btn--quiet btn--accent" type="button" id="cancel-invoice-payment">Cancel</button><button class="btn btn--danger" type="submit" disabled>Record a payment</button></div>
    </form>
  </div>
</dialog>
<?php endif; ?>
<section class="card card__pad section-spaced no-print"><div class="card__head"><div><h2 class="heading-lg">Email log</h2><p class="card__sub">Sent, bounced, and failed invoice email deliveries.</p></div><?php $emailLogClearInvoiceId = (int) $invoiceId; require dirname(__DIR__) . '/includes/email_log_clear_form.php'; ?></div>
<?php if ($emailLogs): ?><div class="table-wrap"><table id="invoice-email-log" data-enhanced-table data-filter-columns="1,3" data-filter-labels="Type,Status"><thead><tr><th data-sort-type="date">Date</th><th>Type</th><th>Recipient</th><th>Status</th><th>Preview</th><th>Action</th></tr></thead><tbody><?php foreach ($emailLogs as $emailLog): $emailCreatedAt = new DateTimeImmutable($emailLog['created_at'], new DateTimeZone('UTC')); ?><tr><td data-sort-value="<?= e($emailCreatedAt->format('Y-m-d H:i:s')) ?>"><?= e($emailCreatedAt->setTimezone(new DateTimeZone('Africa/Nairobi'))->format('j M Y H:i')) ?></td><td><?= e(str_replace('_', ' ', $emailLog['type'])) ?></td><td><?= e($emailLog['recipient'] ?? '—') ?></td><td><?= e(ucfirst($emailLog['status'])) ?></td><td><details><summary>View</summary><pre><?= e($emailLog['text_body'] ?? '') ?></pre></details></td><td><?php if ($canSendInvoiceEmails && in_array($emailLog['type'], ['invoice_issued','invoice_partial','invoice_overdue','payment_received'], true)): ?><form method="post" class="invoice-email-log__send-form" data-disable-on-submit data-confirm="Resend this email to <?= e($billingEmail !== '' ? $billingEmail : 'the client billing address') ?>?" data-confirm-title="Send Email" data-confirm-accept-label="Send Email" data-confirm-danger="true" data-confirm-action-style="danger"><?= csrf_field() ?><input type="hidden" name="action" value="send_email_log"><input type="hidden" name="email_log_id" value="<?= e($emailLog['id']) ?>"><button class="btn btn--danger invoice-email-log__send" type="submit">Send Email</button></form><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p class="empty">No invoice emails have been recorded.</p><?php endif; ?>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
