<article class="invoice-paper">
  <?php require __DIR__ . '/invoice_pdf_header.php'; ?>
  <div class="invoice-paper__titlebar">
    <div><h2>Domain Renewal Report</h2><div class="domain-report-period">As of <?= e(date('j F Y', strtotime($report['as_of_date']))) ?></div></div>
  </div>
  <main class="invoice-paper__main">
    <section class="domain-report-section">
      <h3>Customer Information</h3>
      <table class="domain-report-table domain-customer-table">
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
    <div class="domain-summary"><span>Number of Domains Expiring</span><strong><?= e($report['domain_count']) ?></strong></div>
    <section class="domain-report-section">
      <h3>Overdue Domains</h3>
      <table class="domain-report-table domain-list-table">
        <thead><tr><th>No.</th><th>Domain Name</th><th>Expiry Date</th><th>Customer</th><th>Days Remaining</th></tr></thead>
        <tbody>
          <?php foreach ($report['domains'] as $index => $domain): ?>
            <tr>
              <?php $daysRemaining = (int) $domain['days_remaining']; ?>
              <td><?= e($index + 1) ?></td>
              <td><?= e($domain['domain_name']) ?></td>
              <td><?= e(date('j F Y', strtotime($domain['expiry_date']))) ?></td>
              <td><?= e($domain['customer_name']) ?></td>
              <td><span class="report-days-remaining <?= $daysRemaining < 0 ? 'report-days-remaining--danger' : 'report-days-remaining--success' ?>"><?= e($daysRemaining) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p class="domain-report-note">Negative values indicate domains that have already expired.</p>
    </section>
  </main>
  <?php require __DIR__ . '/invoice_pdf_footer.php'; ?>
</article>
