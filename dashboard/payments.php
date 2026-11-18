<?php
require_once dirname(__DIR__) . '/includes/email.php';
require_auth();
$pdo = db();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $invoiceId = filter_var($_POST['invoice_id'] ?? null, FILTER_VALIDATE_INT);
        $amountCents = money_to_cents($_POST['amount'] ?? '');
        $paymentDate = is_string($_POST['payment_date'] ?? null) ? $_POST['payment_date'] : '';
        $method = post_string('method', 60, true);
        $reference = post_string('reference', 150);
        if ($reference === '') {
            throw new InvalidArgumentException('Enter a transaction reference.');
        }
        $notes = post_string('notes', 2000);
        if (!$invoiceId || $amountCents <= 0 || !in_array($method, PAYMENT_METHODS, true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate) || $paymentDate > date('Y-m-d')) {
            throw new InvalidArgumentException('Enter a valid invoice, amount, payment date, and method.');
        }
        $pdo->beginTransaction();
        $invoiceQuery = $pdo->prepare('SELECT id,total,status,due_date FROM invoices WHERE id=? FOR UPDATE');
        $invoiceQuery->execute([$invoiceId]);
        $invoice = $invoiceQuery->fetch();
        if (!$invoice || in_array($invoice['status'], ['draft','cancelled'], true)) {
            throw new InvalidArgumentException('Payments can only be recorded against issued invoices.');
        }
        $referenceQuery = $pdo->prepare('SELECT 1 FROM payments WHERE invoice_id=? AND reference=? LIMIT 1');
        $referenceQuery->execute([$invoiceId, $reference]);
        if ($referenceQuery->fetchColumn()) {
            throw new InvalidArgumentException('This transaction reference is already used for this invoice.');
        }
        $paidQuery = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=?');
        $paidQuery->execute([$invoiceId]);
        $invoice['paid'] = $paidQuery->fetchColumn();
        $balanceCents = money_to_cents($invoice['total']) - money_to_cents($invoice['paid']);
        if ($amountCents > $balanceCents) {
            throw new InvalidArgumentException('Payment cannot exceed the outstanding invoice balance.');
        }
        $insert = $pdo->prepare('INSERT INTO payments (invoice_id,amount,payment_date,method,reference,notes,created_by) VALUES (?,?,?,?,?,?,?)');
        $insert->execute([$invoiceId,cents_to_decimal($amountCents),$paymentDate,$method,$reference,$notes ?: null,current_user()['id']]);
        $newPaid = money_to_cents($invoice['paid']) + $amountCents;
        $newStatus = $newPaid >= money_to_cents($invoice['total']) ? 'paid' : ($invoice['due_date'] < date('Y-m-d') ? 'overdue' : 'partially_paid');
        $pdo->prepare('UPDATE invoices SET status=? WHERE id=?')->execute([$newStatus,$invoiceId]);
        $pdo->commit();
        try {
            $emailResult = sendInvoiceEmail((int) $invoiceId, 'payment_received', 'system');
            $notice = payment_received_email_notice($emailResult);
            flash('Payment recorded successfully. ' . $notice['message'], $notice['type']);
        } catch (Throwable $mailException) {
            error_log('Payment confirmation email failed after payment recording: ' . safe_email_error($mailException->getMessage()));
            flash('Payment recorded successfully, but the customer confirmation email could not be sent. Check the invoice email log and settings.', 'error');
        }
        redirect('/dashboard/payments.php');
    } catch (InvalidArgumentException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Payment recording failed: ' . $exception->getMessage());
        $error = str_contains($exception->getMessage(), 'uq_payment_invoice_reference')
            ? 'This transaction reference is already used for this invoice.'
            : 'The payment could not be recorded. Check the invoice and try again.';
    }
}

$showPaymentForm = isset($_GET['new'])
    || isset($_GET['invoice_id'])
    || $_SERVER['REQUEST_METHOD'] === 'POST';
$openInvoices = [];
$payments = [];
if ($showPaymentForm) {
    $openInvoices = $pdo->query("SELECT i.id,i.invoice_number,i.total,i.currency_code,i.due_date,c.name customer_name,COALESCE(p.paid,0) paid FROM invoices i JOIN customers c ON c.id=i.customer_id LEFT JOIN (SELECT invoice_id,SUM(amount) paid FROM payments GROUP BY invoice_id) p ON p.invoice_id=i.id WHERE i.status NOT IN ('draft','cancelled') AND COALESCE(p.paid,0)<i.total ORDER BY i.due_date")->fetchAll();
} else {
    $payments = $pdo->query('SELECT p.*,i.invoice_number,i.currency_code,c.name customer_name FROM payments p JOIN invoices i ON i.id=p.invoice_id JOIN customers c ON c.id=i.customer_id ORDER BY p.payment_date DESC,p.id DESC LIMIT 250')->fetchAll();
}
$pageTitle = 'Payments';
$activePage = 'payments';
$pageSubtitle = 'Record and review receipts';
require dirname(__DIR__) . '/includes/header.php';
?>
<div class="page-head"><div><h1 class="display-lg">Payments</h1><p class="page-head__sub">Record manual receipts against outstanding invoices.</p></div></div>
<?php if ($error !== ''): ?><div class="notice notice--error" role="alert"><?= e($error) ?></div><?php endif; ?>
<div class="customer-page">
  <?php if ($showPaymentForm): ?>
  <section class="card card__pad form-card"><div class="card__head"><div><h2 class="heading-lg">Record a payment</h2><p class="card__sub">Enter payment details to update an invoice balance.</p></div></div>
    <?php if ($openInvoices): ?><form class="data-form data-form--grid" method="post"><?= csrf_field() ?>
      <label class="field"><span class="field__label">Invoice *</span><select class="input" name="invoice_id" id="payment-invoice" data-validation-required="true" required><option value="">Choose an invoice</option><?php foreach ($openInvoices as $invoice): ?><option value="<?= e($invoice['id']) ?>" data-currency="<?= e(invoice_currency($invoice)) ?>"<?= (string) ($_POST['invoice_id'] ?? $_GET['invoice_id'] ?? '') === (string) $invoice['id'] ? ' selected' : '' ?>><?= e($invoice['invoice_number']) ?> · <?= e($invoice['customer_name']) ?> · Balance <?= money((float) $invoice['total'] - (float) $invoice['paid'], invoice_currency($invoice)) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span class="field__label">Amount (<span id="payment-invoice-currency"><?= e(currency_code()) ?></span>) *</span><input class="input" type="number" min="0.01" step="0.01" name="amount" data-validation-required="true" required value="<?= e($_POST['amount'] ?? '') ?>"></label>
      <label class="field"><span class="field__label">Payment date *</span><input class="input" type="date" name="payment_date" max="<?= e(date('Y-m-d')) ?>" value="<?= e($_POST['payment_date'] ?? date('Y-m-d')) ?>" data-validation-required="true" required></label>
      <label class="field"><span class="field__label">Method *</span><select class="input" name="method" data-validation-required="true" required><?php foreach (PAYMENT_METHODS as $method): ?><option<?= ($_POST['method'] ?? PAYMENT_METHODS[0]) === $method ? ' selected' : '' ?>><?= e($method) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span class="field__label">Transaction reference *</span><input class="input" name="reference" maxlength="150" data-validation-required="true" required value="<?= e($_POST['reference'] ?? '') ?>"></label>
      <label class="field field--wide"><span class="field__label">Notes</span><textarea class="input textarea" name="notes" maxlength="2000"><?= e($_POST['notes'] ?? '') ?></textarea></label>
      <div class="form-actions field--wide"><button class="btn btn--primary btn--danger">Record payment</button><a class="btn btn--quiet btn--accent" href="<?= e(app_url('/dashboard/payments.php')) ?>">Cancel</a></div>
    </form><?php else: ?><p class="empty">There are no outstanding invoices ready for payment.</p><a class="btn btn--quiet btn--accent" href="<?= e(app_url('/dashboard/payments.php')) ?>">Back to payment history</a><?php endif; ?>
  </section>
  <?php else: ?>
  <section class="card card__pad"><div class="card__head card__head--row"><div><h2 class="heading-lg">Payment history</h2><p class="card__sub">Latest 250 transactions</p></div><a class="btn btn--danger" href="<?= e(app_url('/dashboard/payments.php?new=1')) ?>">Record a Payment</a></div>
    <section class="dt" aria-label="Payment history">
      <div class="dt-toolbar" data-payment-history-controls>
        <div class="dt-search">
          <label class="dt-label" for="pay-search">Search payments</label>
          <div class="dt-field"><span class="dt-icon-left"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/></svg></span><input class="dt-input" id="pay-search" type="search" data-payment-search placeholder="Search customer, invoice or reference"></div>
        </div>
        <div>
          <label class="dt-label" for="pay-method">Method</label>
          <div class="dt-field"><select class="dt-select" id="pay-method" data-payment-method-filter><option value="">All methods</option><?php foreach (PAYMENT_METHODS as $method): ?><option value="<?= e(mb_strtolower($method)) ?>"><?= e($method) ?></option><?php endforeach; ?></select><span class="dt-icon-right"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></span></div>
        </div>
        <div>
          <label class="dt-label" for="pay-currency">Currency</label>
          <div class="dt-field"><select class="dt-select" id="pay-currency" data-payment-currency-filter><option value="">All currencies</option><?php foreach (supported_invoice_currencies() as $currency): ?><option value="<?= e($currency) ?>"><?= e($currency) ?></option><?php endforeach; ?></select><span class="dt-icon-right"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></span></div>
        </div>
        <div class="dt-sortby">
          <label class="dt-label" for="pay-sortby">Sort by</label>
          <div class="dt-field"><select class="dt-select" id="pay-sortby" data-payment-sortby>
            <option value="date:-1">Date, newest first</option><option value="date:1">Date, oldest first</option>
            <option value="customer:1">Customer A–Z</option><option value="customer:-1">Customer Z–A</option>
            <option value="invoice:1">Invoice A–Z</option><option value="invoice:-1">Invoice Z–A</option>
            <option value="method:1">Method A–Z</option><option value="method:-1">Method Z–A</option>
            <option value="reference:1">Reference A–Z</option><option value="reference:-1">Reference Z–A</option>
            <option value="amount:-1">Amount, high to low</option><option value="amount:1">Amount, low to high</option>
          </select><span class="dt-icon-right"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></span></div>
        </div>
      </div>
      <div class="dt-scroll">
        <table class="dt-table" data-payment-history-table>
          <caption class="dt-sr">Payments</caption>
          <thead><tr>
            <th scope="col" data-sort-column="date" data-payment-sort-header="date" aria-sort="descending"><button type="button" class="dt-sort" data-payment-sort="date">Date<svg class="dt-sort-arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M6 13l6 6 6-6"/></svg></button></th>
            <th scope="col" data-sort-column="customer" data-payment-sort-header="customer"><button type="button" class="dt-sort" data-payment-sort="customer">Customer<span class="dt-arrow-idle"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 9l4-4 4 4M8 15l4 4 4-4"/></svg></span></button></th>
            <th scope="col" data-sort-column="invoice" data-payment-sort-header="invoice"><button type="button" class="dt-sort" data-payment-sort="invoice">Invoice<span class="dt-arrow-idle"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 9l4-4 4 4M8 15l4 4 4-4"/></svg></span></button></th>
            <th scope="col" data-sort-column="method" data-payment-sort-header="method"><button type="button" class="dt-sort" data-payment-sort="method">Method<span class="dt-arrow-idle"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 9l4-4 4 4M8 15l4 4 4-4"/></svg></span></button></th>
            <th scope="col" data-sort-column="reference" data-payment-sort-header="reference"><button type="button" class="dt-sort" data-payment-sort="reference">Transaction reference<span class="dt-arrow-idle"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 9l4-4 4 4M8 15l4 4 4-4"/></svg></span></button></th>
            <th scope="col" class="dt-right" data-sort-column="amount" data-payment-sort-header="amount"><button type="button" class="dt-sort" data-payment-sort="amount">Amount<span class="dt-arrow-idle"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 9l4-4 4 4M8 15l4 4 4-4"/></svg></span></button></th>
          </tr></thead>
          <tbody><?php foreach ($payments as $payment): $paymentDateDisplay = (new DateTimeImmutable((string) $payment['payment_date']))->format('j M Y'); $paymentMethodKey = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower(trim((string) $payment['method']))) ?: ''; ?>
            <tr data-payment-row data-payment-date="<?= e($payment['payment_date']) ?>" data-payment-customer="<?= e(mb_strtolower((string) $payment['customer_name'])) ?>" data-payment-invoice="<?= e(mb_strtolower((string) $payment['invoice_number'])) ?>" data-payment-method="<?= e(mb_strtolower((string) $payment['method'])) ?>" data-payment-reference="<?= e(mb_strtolower((string) ($payment['reference'] ?? ''))) ?>" data-payment-amount="<?= e(money_to_cents($payment['amount'])) ?>" data-payment-currency="<?= e(invoice_currency($payment)) ?>">
              <td class="dt-c-date"><?= e($paymentDateDisplay) ?></td><td class="dt-c-customer" data-date="<?= e($paymentDateDisplay) ?>"><?= e($payment['customer_name']) ?></td><td class="dt-c-invoice"><a href="<?= e(app_url('/dashboard/invoice_view.php?id=' . $payment['invoice_id'])) ?>"><?= e($payment['invoice_number']) ?></a></td><td class="dt-c-method"><span class="dt-chip" data-method="<?= e($paymentMethodKey) ?>"><?= e($payment['method']) ?></span></td><td class="dt-c-ref"><?= e($payment['reference'] ?? '') ?></td><td class="dt-c-amount"><span class="dt-cur"><?= e(invoice_currency($payment)) ?></span> <span class="dt-num"><?= e(number_format((float) $payment['amount'], 2, '.', ',')) ?></span></td>
            </tr><?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="dt-empty" data-payment-empty<?= $payments ? ' hidden' : '' ?>><?= $payments ? 'No payments match your search or filters. <a href="#" data-payment-clear>Clear filters</a>' : 'No payments have been recorded.' ?></div>
      <div class="dt-foot" data-payment-history-footer>
        <div class="dt-foot-left">
          <div class="dt-count" data-payment-history-count aria-live="polite"></div>
          <div class="dt-rpp"><label for="pay-rpp">Rows per page</label><div class="dt-field"><select class="dt-select" id="pay-rpp" data-payment-page-size><option value="10" selected>10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option><option value="250">250</option></select><span class="dt-icon-right"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></span></div></div>
        </div>
        <nav class="dt-pager" aria-label="Pagination" data-payment-pagination></nav>
      </div>
    </section>
  </section>
  <?php endif; ?>
</div>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>