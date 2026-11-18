<article class="invoice-paper">
  <?php require __DIR__ . '/invoice_pdf_header.php'; ?>
  <div class="invoice-paper__titlebar">
    <div><h2>Google Workspace Renewal Report</h2><div class="workspace-report-period">As of <?= e(date('j F Y', strtotime($report['as_of_date']))) ?></div></div>
  </div>
  <main class="invoice-paper__main">
    <section class="workspace-report-section">
      <h3>Customer Information</h3>
      <table class="workspace-report-table workspace-customer-table">
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
    <div class="workspace-summary"><span>Overdue Google Workspace accounts</span><strong><?= e($report['workspace_count']) ?></strong></div>
    <section class="workspace-report-section">
      <h3>Google Workspace Accounts</h3>
      <table class="workspace-report-table">
        <thead><tr><th>No.</th><th>Customer</th><th>Domain</th><th>Expiry Date</th><th>Days Remaining</th></tr></thead>
        <tbody>
          <?php foreach ($report['workspaces'] as $index => $workspace): ?>
            <tr>
              <td><?= e($index + 1) ?></td>
              <td><span class="report-customer-name"><?= e($workspace['customer_name']) ?></span></td>
              <td><?= e($workspace['domain_name']) ?></td>
              <td><?= e(date('j F Y', strtotime($workspace['expiry_date']))) ?></td>
              <td><span class="report-days-remaining <?= (int) $workspace['days_remaining'] < 0 ? 'report-days-remaining--danger' : 'report-days-remaining--success' ?>" style="<?= (int) $workspace['days_remaining'] < 0 ? 'background-color:#dc2626;color:#ffffff;' : 'background-color:#16a34a;color:#ffffff;' ?>"><?= e($workspace['days_remaining']) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p class="workspace-report-note">Days Remaining is calculated from today; negative values indicate accounts that are overdue.</p>
    </section>
  </main>
  <?php require __DIR__ . '/invoice_pdf_footer.php'; ?>
</article>
