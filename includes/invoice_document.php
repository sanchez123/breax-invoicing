<?php $documentCurrency = invoice_currency($invoice); ?>
<?php if (!isset($generatedOn) || $generatedOn === ''): $generatedOn = (new DateTimeImmutable('now', new DateTimeZone('Africa/Nairobi')))->format('l, F jS Y'); endif; ?>
<div class="invoice-page-surface<?= !empty($invoicePdfDocument) ? ' invoice-pdf' : '' ?>">
  <article class="invoice-paper">
    <?php require __DIR__ . '/invoice_pdf_header.php'; ?>
    <div class="invoice-paper__titlebar">
      <div><h2>Invoice</h2><div class="invoice-paper__number"><?= e($invoice['invoice_number'] ?: (!empty($isPublicInvoice) ? 'Invoice' : 'Draft #' . $invoice['id'])) ?></div></div>
      <div class="status-box">
        <div class="status-row"><span class="invoice-status__label">Status</span><span class="invoice-badge" data-status="<?= e($statusKey) ?>" role="status"><?= e($statusLabel) ?></span></div>
        <?php if ($publicInvoiceUrl !== ''): ?><div class="qr" id="qrWrap"><div class="qr-code" id="qrCode" data-qr="<?= e($publicInvoiceUrl) ?>"><?= !empty($invoicePdfQrPlaceholder) ? '<!--SERVER_INVOICE_QR-->' : '' ?></div>Scan to view invoice</div><?php endif; ?>
      </div>
    </div>
    <main class="invoice-paper__main">
      <div class="invoice-dates">
        <div><strong><?= e(date('j F Y', strtotime($invoice['issue_date']))) ?></strong><span>Issue date</span></div>
        <div><strong><?= e(date('j F Y', strtotime($invoice['due_date']))) ?></strong><span>Due date</span></div>
      </div>
      <section class="invoice-parties">
        <div><h3>From</h3><p><strong><?= e($company['business_name'] ?? 'Breax Technologies') ?></strong><?php if (!empty($company['business_phone'])): ?><br><?= e($company['business_phone']) ?><?php endif; ?><?php if (!empty($company['business_email'])): ?><br><?= e($company['business_email']) ?><?php endif; ?><?php if (!empty($company['business_address'])): ?><br><?= nl2br(e($company['business_address'])) ?><?php endif; ?></p></div>
        <div><h3>Billed to</h3><p><strong><?= e($invoice['customer_name']) ?></strong><?php if (!empty($invoice['phone'])): ?><br><?= e($invoice['phone']) ?><?php endif; ?><?php if (!empty($invoice['customer_email'])): ?><br><?= e($invoice['customer_email']) ?><?php endif; ?><?php if (!empty($invoice['billing_address'])): ?><br><?= nl2br(e($invoice['billing_address'])) ?><?php endif; ?></p></div>
      </section>
      <div class="invoice-table-wrap"><table class="invoice-table"><thead><tr><th>Description</th><th class="num">Quantity</th><th class="num">Amount</th></tr></thead><tbody><?php foreach ($items as $item): $descriptionParts = array_pad(preg_split('/\R/', (string) $item['description'], 2) ?: [], 2, ''); $catalogueName = trim((string) ($item['catalogue_name'] ?? '')); $catalogueCategory = trim((string) ($item['catalogue_category'] ?? '')); $catalogueBillingCycle = trim((string) ($item['catalogue_billing_cycle'] ?? '')); $itemTitle = $catalogueName !== '' ? $catalogueName . ($catalogueCategory !== '' ? ' · ' . $catalogueCategory : '') . ($catalogueBillingCycle !== '' ? ' (' . ucfirst($catalogueBillingCycle) . ')' : '') : $descriptionParts[0]; $itemDescription = $catalogueName !== '' ? trim((string) $item['description']) : $descriptionParts[1]; if ($catalogueName !== '' && in_array($itemDescription, [$catalogueName, $catalogueName . ($catalogueCategory !== '' ? ' · ' . $catalogueCategory : ''), $itemTitle], true)) { $itemDescription = ''; } ?><tr><td class="desc"><strong><?= e($itemTitle) ?></strong><?php if ($itemDescription !== ''): ?><span><?= e($itemDescription) ?></span><?php endif; ?></td><td class="num"><?= e($item['quantity']) ?></td><td class="num"><?= money($item['line_total'], $documentCurrency) ?></td></tr><?php endforeach; ?></tbody><tfoot>
        <tr class="sub"><td colspan="2" class="num">Subtotal</td><td class="num"><?= money($invoice['subtotal'], $documentCurrency) ?></td></tr>
        <?php if ((float) $invoice['discount_percent'] > 0): ?><tr class="sub"><td colspan="2" class="num">Discount (<?= e($invoice['discount_percent']) ?>%)</td><td class="num">-<?= money(cents_to_decimal($discountCents), $documentCurrency) ?></td></tr><?php endif; ?>
        <?php if ((float) $invoice['tax_percent'] > 0): ?><tr class="tax"><td colspan="2" class="num">VAT (<?= e($invoice['tax_percent']) ?>%)</td><td class="num"><?= money($invoice['tax_amount'], $documentCurrency) ?></td></tr><?php endif; ?>
        <tr class="grand"><td colspan="2" class="num">Total due</td><td class="num"><?= money($invoice['total'], $documentCurrency) ?></td></tr>
        <?php if ($hasPayments): ?><tr class="sub total-paid"><td colspan="2" class="num">Amount Paid</td><td class="num"><?= money(cents_to_decimal($paymentsTotalCents), $documentCurrency) ?></td></tr><?php endif; ?>
        <?php if ($hasPayments): ?><tr class="balance <?= $status === 'paid' ? 'balance--paid' : 'balance--partial' ?>"><td colspan="2" class="num">Balance</td><td class="num"><?= money(cents_to_decimal($balanceCents), $documentCurrency) ?></td></tr><?php endif; ?>
      </tfoot></table></div>
      <?php if ($hasPayments): ?>
      <section class="transactions">
        <h2>Transactions</h2>
        <div class="table-wrap">
          <table class="tx">
            <thead><tr><th>Date</th><th>Payment method</th><th>Transaction code</th><th class="num">Amount</th></tr></thead>
            <tbody><?php foreach ($payments as $payment):
                $rawPaymentDate = (string) ($payment['payment_date'] ?? '');
                $parsedPaymentDate = $rawPaymentDate !== '' ? strtotime($rawPaymentDate) : false;
                $transactionDate = $parsedPaymentDate !== false ? date('j F Y', $parsedPaymentDate) : '-';
                $paymentMethod = (string) ($payment['method'] ?? '');
                $transactionCode = (string) ($payment['reference'] ?? '');
            ?><tr><td><?= e($transactionDate) ?></td><td><?= e($paymentMethod !== '' ? $paymentMethod : '-') ?></td><td class="code"><?= e($transactionCode !== '' ? $transactionCode : '-') ?></td><td class="num"><?= money($payment['amount'], $documentCurrency) ?></td></tr><?php endforeach; ?></tbody>
          </table>
        </div>
      </section>
      <?php endif; ?>
      <section class="invoice-notes">
        <div>
          <h3>Payment Details</h3>
          <p><?= !empty($company['payment_instructions']) ? format_invoice_payment_instructions((string) $company['payment_instructions']) : 'Please contact us for payment instructions.' ?><?php if ($paymentTerms !== ''): ?><br><br><?= nl2br(e($paymentTerms)) ?><?php endif; ?></p>
        </div>
        <?php if ($invoicePaymentTerms !== ''): ?><div><h3>Terms/Notes</h3><p><?= nl2br(e($invoicePaymentTerms)) ?></p></div><?php endif; ?>
      </section>
    </main>
    <?php require __DIR__ . '/invoice_pdf_footer.php'; ?>
  </article>
</div>
