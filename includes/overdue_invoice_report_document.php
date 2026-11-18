<article class="invoice-paper">
  <?php require __DIR__ . '/invoice_pdf_header.php'; ?>
  <div class="invoice-paper__titlebar">
    <div><h2>Overdue Invoices Report</h2><div class="report-period">As of <?= e(date('j F Y', strtotime($report['as_of_date']))) ?></div></div>
  </div>
  <main class="invoice-paper__main">
    <section class="report-section">
      <h3>Customer Information</h3>
      <table class="report-table report-customer-table">
        <thead><tr><th>Customer</th><th>Email</th><th>Phone</th><th>Billing address</th></tr></thead>
        <tbody>
          <?php foreach ($report['customers'] as $customer): ?>
            <tr>
              <td><strong class="report-customer-name"><?= e($customer['customer_name']) ?>(<?= e($customer['relevant_count']) ?>)</strong></td>
              <td><?= e($customer['customer_email'] !== '' ? $customer['customer_email'] : '—') ?></td>
              <td><?= e($customer['customer_phone'] !== '' ? $customer['customer_phone'] : '—') ?></td>
              <td><?= e($customer['customer_address'] !== '' ? $customer['customer_address'] : '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>
    <div class="report-summary"><span>Overdue invoices</span><strong><?= e($report['invoice_count']) ?></strong></div>
    <table class="report-table report-invoice-table">
      <thead><tr><th>No.</th><th>Invoice No.</th><th>Customer Name</th><th>Phone</th><th>Email</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($report['invoices'] as $index => $invoice): ?>
          <tr>
            <td><?= e($index + 1) ?></td>
            <td><?= e($invoice['invoice_number']) ?></td>
            <td><span class="report-customer-name"><?= e($invoice['customer_name']) ?></span></td>
            <td><?= e($invoice['customer_phone'] !== '' ? $invoice['customer_phone'] : '—') ?></td>
            <td><?= e($invoice['customer_email'] !== '' ? $invoice['customer_email'] : '—') ?></td>
            <?php
              $statusClass = match ($invoice['status']) {
                  'Paid' => 'report-status--paid',
                  'Partially Paid' => 'report-status--partial',
                  default => 'report-status--unpaid',
              };
            ?>
            <td><span class="report-status <?= e($statusClass) ?>"><?= e($invoice['status']) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </main>
  <?php require __DIR__ . '/invoice_pdf_footer.php'; ?>
</article>
