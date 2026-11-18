<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/currency_exchange.php';
require_once dirname(__DIR__) . '/includes/revenue_by_item.php';
require_auth();
$pdo = db();
$fxAvailable = true;
try {
    ensure_invoice_reporting_rates($pdo);
} catch (InvalidArgumentException $exception) {
    $fxAvailable = false;
    error_log('Reports currency conversion unavailable: ' . $exception->getMessage());
}
$revenueDataError = '';
try {
    $revenueData = revenue_by_item_data($pdo);
} catch (RuntimeException $exception) {
    error_log('Revenue by item report unavailable: ' . $exception->getMessage());
    $revenueDataError = $exception->getMessage();
    $revenueData = ['as_of_date' => date('Y-m-d'), 'items' => []];
}
$monthly = $pdo->query("SELECT DATE_FORMAT(p.payment_date,'%Y-%m') month_key,DATE_FORMAT(p.payment_date,'%b %Y') month_label,SUM(p.amount*CASE WHEN i.currency_code='KES' THEN 1 ELSE i.kes_exchange_rate END) revenue FROM payments p JOIN invoices i ON i.id=p.invoice_id WHERE p.payment_date>=CURDATE()-INTERVAL 12 MONTH GROUP BY YEAR(p.payment_date),MONTH(p.payment_date) ORDER BY YEAR(p.payment_date) DESC,MONTH(p.payment_date) DESC LIMIT 6")->fetchAll();
$monthlyDisplayed = array_slice($monthly, 0, 5);
$monthlyTotal = array_sum(array_map(static fn(array $row): float => (float) $row['revenue'], $monthlyDisplayed));
$monthlyLargest = $monthlyDisplayed !== [] ? max(array_map(static fn(array $row): float => (float) $row['revenue'], $monthlyDisplayed)) : 0.0;
$customers = $pdo->query("SELECT c.name,COUNT(i.id) invoice_count,COALESCE(SUM(CASE WHEN i.currency_code='KES' THEN i.total ELSE i.total*i.kes_exchange_rate END),0) invoiced FROM customers c JOIN invoices i ON i.customer_id=c.id AND i.status NOT IN ('draft','cancelled') GROUP BY c.id ORDER BY invoiced DESC LIMIT 5")->fetchAll();
$customersTotal = array_sum(array_map(static fn(array $customer): float => (float) $customer['invoiced'], $customers));
$customersLargest = $customers !== [] ? max(array_map(static fn(array $customer): float => (float) $customer['invoiced'], $customers)) : 0.0;
$pageTitle = 'Reports';
$activePage = 'reports';
$pageSubtitle = 'Revenue and receivables';
require dirname(__DIR__) . '/includes/header.php';
?>
<?php require dirname(__DIR__) . '/includes/revenue_by_item_section.php'; ?>
<div class="grid-2">
  <section class="report-card report-card--revenue">
    <header class="report-card__header">
      <div><h2>Revenue by month</h2><p>Recorded payments converted to KES</p></div>
      <span class="report-pill">Last 5 months</span>
    </header>
    <?php if ($monthlyDisplayed === []): ?>
      <p class="report-empty">No payments recorded yet</p>
    <?php elseif (!$fxAvailable): ?>
      <p class="report-empty">Revenue conversion is unavailable until exchange rates can be retrieved.</p>
    <?php else: ?>
      <div class="report-summary">
        <span class="report-summary__label">TOTAL RECEIVED</span>
        <strong><?= e('KES ' . number_format($monthlyTotal, 2, '.', ',')) ?></strong>
      </div>
      <div class="report-rows">
        <?php foreach ($monthlyDisplayed as $index => $row):
            $revenue = (float) $row['revenue'];
            $previousRow = $monthly[$index + 1] ?? null;
            $barWidth = $monthlyLargest > 0 ? min(100, max(0, ($revenue / $monthlyLargest) * 100)) : 0;
            if ($previousRow === null) {
                $changeClass = 'report-change--neutral';
                $changeLabel = 'First month';
            } elseif ((float) $previousRow['revenue'] === 0.0) {
                $changeClass = 'report-change--neutral';
                $changeLabel = 'New';
            } else {
                $change = (($revenue - (float) $previousRow['revenue']) / (float) $previousRow['revenue']) * 100;
                $changeClass = $change > 0 ? 'report-change--up' : ($change < 0 ? 'report-change--down' : 'report-change--neutral');
                $changeLabel = ($change > 0 ? '↑ ' : ($change < 0 ? '↓ ' : '')) . number_format(abs($change), 1) . '% vs prior';
            }
        ?>
        <div class="report-row report-row--revenue">
          <span class="report-month"><?= e($row['month_label']) ?></span>
          <svg class="report-bar" viewBox="0 0 100 8" preserveAspectRatio="none" aria-hidden="true">
            <rect class="report-bar__track" x="0" y="0" width="100" height="8" rx="4"/>
            <rect class="<?= $index === 0 ? 'report-bar__fill--primary' : 'report-bar__fill--secondary' ?>" x="0" y="0" width="<?= e(number_format($barWidth, 2, '.', '')) ?>" height="8" rx="4"/>
          </svg>
          <span class="report-amount">
            <strong><?= e('KES ' . number_format($revenue, 2, '.', ',')) ?></strong>
            <span class="report-change <?= e($changeClass) ?>"><?= e($changeLabel) ?></span>
          </span>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
  <section class="report-card report-card--customers">
    <header class="report-card__header">
      <div><h2>Top customers</h2><p>By issued invoice value, converted to KES</p></div>
      <span class="report-pill">Top 5</span>
    </header>
    <?php if ($customers === []): ?>
      <p class="report-empty">No invoices issued yet</p>
    <?php elseif (!$fxAvailable): ?>
      <p class="report-empty">Invoice conversion is unavailable until exchange rates can be retrieved.</p>
    <?php else: ?>
      <div class="report-summary">
        <span class="report-summary__label">TOTAL INVOICED</span>
        <strong><?= e('KES ' . number_format($customersTotal, 2, '.', ',')) ?></strong>
      </div>
      <div class="report-rows">
        <?php foreach ($customers as $index => $customer):
            $customerName = (string) $customer['name'];
            $nameWords = preg_split('/\s+/u', trim($customerName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $initials = '';
            foreach (array_slice($nameWords, 0, 2) as $word) {
                $initials .= mb_strtoupper(mb_substr($word, 0, 1));
            }
            $invoiced = (float) $customer['invoiced'];
            $barWidth = $customersLargest > 0 ? min(100, max(0, ($invoiced / $customersLargest) * 100)) : 0;
            $share = $customersTotal > 0 ? (int) round(($invoiced / $customersTotal) * 100) : 0;
            $invoiceCountForCustomer = (int) $customer['invoice_count'];
        ?>
        <div class="report-row report-row--customer">
          <span class="report-avatar<?= $index === 0 ? ' report-avatar--first' : '' ?>" aria-hidden="true"><?= e($initials) ?></span>
          <div class="report-customer-info">
            <span class="report-customer-name" title="<?= e($customerName) ?>"><?= e($customerName) ?></span>
            <svg class="report-bar report-customer-bar" viewBox="0 0 100 8" preserveAspectRatio="none" aria-hidden="true">
              <rect class="report-bar__track" x="0" y="0" width="100" height="8" rx="4"/>
              <rect class="<?= $index === 0 ? 'report-bar__fill--primary' : 'report-bar__fill--secondary' ?>" x="0" y="0" width="<?= e(number_format($barWidth, 2, '.', '')) ?>" height="8" rx="4"/>
            </svg>
          </div>
          <span class="report-amount report-customer-amount">
            <strong><?= e('KES ' . number_format($invoiced, 2, '.', ',')) ?></strong>
            <span class="report-customer-meta"><?= e(number_format($invoiceCountForCustomer) . ' ' . ($invoiceCountForCustomer === 1 ? 'invoice' : 'invoices') . ' · ' . $share . '%') ?></span>
          </span>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>