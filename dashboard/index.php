<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/currency_exchange.php';
require_once dirname(__DIR__) . '/includes/currency_summary.php';
require_once dirname(__DIR__) . '/includes/overdue_services.php';
require_auth();

$pdo = db();
$fxRateDate = '';
$fxAvailable = true;
try {
    $fxRateDate = ensure_invoice_reporting_rates($pdo);
} catch (InvalidArgumentException $exception) {
    $fxAvailable = false;
    error_log('Dashboard currency conversion unavailable: ' . $exception->getMessage());
}
$overdueServiceData = overdue_service_data($pdo);
$currencySummary = currency_summary_data($pdo, $overdueServiceData);
$dashboardCurrencies = $currencySummary['currencies'];
$currencyStats = $currencySummary['currency_stats'];
$currencyListLoaded = $currencySummary['currency_list_loaded'];
$currencyStatsLoaded = $currencySummary['currency_stats_loaded'];
$currencyCards = [
    ['type' => 'count', 'title' => 'Total invoices', 'caption' => 'Including drafts and cancelled', 'key' => 'invoice_count', 'style' => 'purple'],
    ['type' => 'count', 'title' => 'Paid', 'caption' => 'Invoices settled', 'key' => 'paid_count', 'status' => 'paid'],
    ['type' => 'count', 'title' => 'Unpaid', 'caption' => 'Not yet overdue', 'key' => 'unpaid_count', 'status' => 'unpaid'],
    ['type' => 'count', 'title' => 'Overdue', 'caption' => 'Invoices past due date', 'key' => 'overdue_count', 'status' => 'overdue'],
    ['type' => 'money', 'title' => 'Total invoiced', 'caption' => 'Issued invoices', 'count_key' => 'invoiced_count', 'amount_key' => 'invoiced_amount', 'kes_key' => 'invoiced_kes', 'missing_key' => 'missing_invoiced_rate', 'style' => 'navy'],
    ['type' => 'money', 'title' => 'Payments received', 'caption' => 'Recorded payments', 'count_key' => 'received_count', 'amount_key' => 'received_amount', 'kes_key' => 'received_kes', 'missing_key' => 'missing_received_rate'],
    ['type' => 'money', 'title' => 'Outstanding balance', 'caption' => 'Remaining on issued invoices', 'count_key' => 'outstanding_count', 'amount_key' => 'outstanding_amount', 'kes_key' => 'outstanding_kes', 'missing_key' => 'missing_outstanding_rate'],
    ['type' => 'money', 'title' => 'Overdue balance', 'caption' => 'Past-due receivables', 'count_key' => 'overdue_balance_count', 'amount_key' => 'overdue_balance_amount', 'kes_key' => 'overdue_balance_kes', 'missing_key' => 'missing_overdue_rate'],
];
$recent = $pdo->query("
    SELECT i.id, i.invoice_number, i.total, i.currency_code, i.kes_exchange_rate, i.due_date, i.status, c.name customer_name,
           " . reporting_kes_sql('i.total') . " total_kes,
           COALESCE(p.paid, 0)*CASE WHEN i.currency_code='KES' THEN 1 ELSE i.kes_exchange_rate END paid_amount_kes
      FROM invoices i JOIN customers c ON c.id = i.customer_id
      LEFT JOIN (SELECT invoice_id, SUM(amount) paid FROM payments GROUP BY invoice_id) p ON p.invoice_id = i.id
     ORDER BY i.created_at DESC LIMIT 5
")->fetchAll();
$recentPayments = $pdo->query("
    SELECT p.amount*CASE WHEN i.currency_code='KES' THEN 1 ELSE i.kes_exchange_rate END amount_kes, i.currency_code, i.kes_exchange_rate, p.payment_date, p.method, i.invoice_number, c.name customer_name
      FROM payments p JOIN invoices i ON i.id = p.invoice_id JOIN customers c ON c.id = i.customer_id
     ORDER BY p.payment_date DESC, p.id DESC LIMIT 3
")->fetchAll();
$overdueServices = $overdueServiceData['counts'];
$overdueServiceRows = [
    [
        'name' => 'Invoices',
        'count' => (int) $overdueServices['invoices'],
        'zero_status' => 'All up to date',
        'singular_status' => 'past its due date',
        'plural_status' => 'past their due date',
        'icon' => 'invoice',
    ],
    [
        'name' => 'Domains',
        'count' => (int) $overdueServices['domains'],
        'zero_status' => 'No expired domains',
        'singular_status' => 'past its expiry date',
        'plural_status' => 'past their expiry date',
        'icon' => 'domain',
    ],
    [
        'name' => 'Google Workspace',
        'count' => (int) $overdueServices['workspaces'],
        'zero_status' => 'All up to date',
        'singular_status' => 'past its expiry date',
        'plural_status' => 'past their expiry date',
        'icon' => 'workspace',
    ],
];
$overdueServiceTotal = array_sum(array_column($overdueServiceRows, 'count'));
$months = $pdo->query("
    SELECT DATE_FORMAT(p.payment_date, '%b') month_label, COALESCE(SUM(p.amount*CASE WHEN i.currency_code='KES' THEN 1 ELSE i.kes_exchange_rate END),0) revenue
      FROM payments p JOIN invoices i ON i.id=p.invoice_id WHERE p.payment_date >= DATE_FORMAT(CURDATE() - INTERVAL 5 MONTH, '%Y-%m-01')
     GROUP BY YEAR(p.payment_date), MONTH(p.payment_date) ORDER BY YEAR(p.payment_date), MONTH(p.payment_date)
")->fetchAll();
$monthStart = new DateTimeImmutable('first day of this month');
$revenueByMonth = [];
foreach ($months as $month) {
    $revenueByMonth[$month['month_label']] = (float) $month['revenue'];
}
$revenueMonths = [];
for ($offset = 5; $offset >= 0; $offset--) {
    $monthDate = $monthStart->modify("-{$offset} months");
    $monthLabel = $monthDate->format('M');
    $revenueMonths[] = [
        'label' => $monthLabel,
        'value' => $revenueByMonth[$monthLabel] ?? 0.0,
        'current' => $offset === 0,
    ];
}
$revenueTotal = array_sum(array_column($revenueMonths, 'value'));
$currentMonthRevenue = $revenueMonths[5]['value'];
$previousMonthRevenue = $revenueMonths[4]['value'];
$revenueChange = $previousMonthRevenue > 0 ? (($currentMonthRevenue - $previousMonthRevenue) / $previousMonthRevenue) * 100 : null;
$largestRevenue = max(0.0, ...array_column($revenueMonths, 'value'));
$axisTarget = $largestRevenue > 0 ? $largestRevenue / 0.88 : 1;
$axisMagnitude = 10 ** floor(log10($axisTarget));
$axisSteps = [1, 2, 2.5, 5, 10];
$revenueAxisMax = $axisMagnitude;
foreach ($axisSteps as $step) {
    $candidate = $step * $axisMagnitude;
    if ($candidate >= $axisTarget) {
        $revenueAxisMax = $candidate;
        break;
    }
}
$revenueAxisLabel = static function (float $value): string {
    if ($value >= 1000000) {
        return rtrim(rtrim(number_format($value / 1000000, 1, '.', ''), '0'), '.') . 'M';
    }
    if ($value >= 1000) {
        return rtrim(rtrim(number_format($value / 1000, 1, '.', ''), '0'), '.') . 'K';
    }
    return number_format($value, 0, '.', ',');
};
$revenueMobileLabel = static function (float $value): string {
    if ($value >= 1000000) {
        return rtrim(rtrim(number_format($value / 1000000, 1, '.', ''), '0'), '.') . 'M';
    }
    if ($value >= 1000) {
        return number_format(round($value / 1000), 0, '.', ',') . 'K';
    }
    return number_format($value, 0, '.', ',');
};
$revenueAxisTicks = [
    ['value' => 0.8, 'label' => $revenueAxisLabel($revenueAxisMax * 0.8)],
    ['value' => 0.4, 'label' => $revenueAxisLabel($revenueAxisMax * 0.4)],
    ['value' => 0, 'label' => '0'],
];
$revenueAverage = $revenueTotal / 6;
$today = new DateTimeImmutable('today');
$invoiceSummary = ['awaiting_amount' => 0.0, 'awaiting_count' => 0, 'overdue_amount' => 0.0, 'overdue_count' => 0];
$invoiceSummaryAvailable = $fxAvailable;
foreach ($recent as &$invoice) {
    $hasRate = $invoice['currency_code'] === 'KES' || $invoice['kes_exchange_rate'] !== null;
    $balance = (float) $invoice['total_kes'] - (float) $invoice['paid_amount_kes'];
    if (in_array($invoice['status'], ['draft', 'cancelled'], true)) {
        $invoice['status_display'] = $invoice['status'];
    } elseif ($hasRate && $balance <= 0) {
        $invoice['status_display'] = 'paid';
    } elseif ($invoice['due_date'] < $today->format('Y-m-d')) {
        $invoice['status_display'] = 'overdue';
    } elseif ($hasRate && (float) $invoice['paid_amount_kes'] > 0) {
        $invoice['status_display'] = 'partially_paid';
    } else {
        $invoice['status_display'] = 'issued';
    }
    if (in_array($invoice['status_display'], ['issued', 'overdue', 'partially_paid'], true)) {
        $invoiceSummary['awaiting_count']++;
        if ($hasRate) {
            $invoiceSummary['awaiting_amount'] += (float) $invoice['total_kes'];
        } else {
            $invoiceSummaryAvailable = false;
        }
        if ($invoice['status_display'] === 'overdue') {
            $invoiceSummary['overdue_count']++;
            if ($hasRate) {
                $invoiceSummary['overdue_amount'] += (float) $invoice['total_kes'];
            }
        }
    }
}
unset($invoice);
$paymentSummaryAvailable = $fxAvailable;
$paymentTotal = 0.0;
foreach ($recentPayments as $payment) {
    if ($payment['currency_code'] === 'KES' || $payment['kes_exchange_rate'] !== null) {
        $paymentTotal += (float) $payment['amount_kes'];
    } else {
        $paymentSummaryAvailable = false;
    }
}
$latestPaymentDate = isset($recentPayments[0]) ? new DateTimeImmutable($recentPayments[0]['payment_date']) : null;
$formatDate = static fn(DateTimeImmutable $date): string => $date->format('j M Y');
$relativeDate = static function (DateTimeImmutable $date) use ($today, $formatDate): string {
    $days = (int) $today->diff($date)->format('%r%a');
    return $days === 0 ? 'Today' : ($days === -1 ? 'Yesterday' : $formatDate($date));
};
$pageTitle = 'Dashboard';
$activePage = 'dashboard';
$pageSubtitle = 'Financial overview';
require dirname(__DIR__) . '/includes/header.php';
?>
<div class="page-head">
  <div><h1 class="display-lg">Welcome back, <?= e(current_user()['name']) ?></h1><p class="page-head__sub">Your business at a glance.</p></div>
  <a class="btn btn--danger" href="<?= e(app_url('/dashboard/create_invoice.php')) ?>"><svg class="icon"><use href="#i-plus"/></svg>Create invoice</a>
</div>
<?php if (!$fxAvailable): ?><div class="notice notice--error" role="alert">KES exchange rates are unavailable. Converted financial totals are hidden until rates can be retrieved.</div><?php endif; ?>
<?php require dirname(__DIR__) . '/includes/exchange_rates.php'; ?>
<section class="stats stats--financial" aria-label="Financial summary">
  <div class="cs">
    <h2 class="cs-title">Financial summary</h2>
    <p class="cs-note">Amounts are shown in their own currency. Totals in KES are converted at the rate recorded on each invoice.</p>
    <div class="cs-grid">
      <?php foreach ($currencyCards as $card):
          $cardTotal = 0;
          $cardTotalAvailable = $currencyStatsLoaded;
          if ($card['type'] === 'money') {
              foreach ($dashboardCurrencies as $currency) {
                  $data = $currencyStats[$currency] ?? [];
                  if ((int) ($data[$card['missing_key']] ?? 0) > 0) {
                      $cardTotalAvailable = false;
                  }
                  $convertedAmount = $data === [] ? '0' : ($data[$card['kes_key']] ?? null);
                  if (!is_numeric($convertedAmount)) {
                      $cardTotalAvailable = false;
                  } else {
                      $cardTotal += round((float) $convertedAmount, 2);
                  }
              }
          } else {
              $cardCount = 0;
              foreach ($dashboardCurrencies as $currency) {
                  $cardCount += (int) ($currencyStats[$currency][$card['key']] ?? 0);
              }
          }
      ?>
      <article class="cs-card<?= ($card['style'] ?? '') === 'purple' ? ' cs-card--purple' : ((($card['style'] ?? '') === 'navy') ? ' cs-card--navy' : '') ?>">
        <div class="cs-head"><?php if (isset($card['status'])): ?><span class="cs-pill" data-status="<?= e($card['status']) ?>"><?= e($card['title']) ?></span><?php else: ?><?= e($card['title']) ?><?php endif; ?></div>
        <?php if ($card['type'] === 'count'): ?>
        <div class="cs-big"><?= $currencyStatsLoaded ? number_format($cardCount) : '—' ?></div>
        <div class="cs-sub"><?= e($card['caption']) ?></div>
        <div class="cs-rows">
          <?php foreach ($dashboardCurrencies as $currency):
              $count = (int) ($currencyStats[$currency][$card['key']] ?? 0);
              $hasFlag = in_array($currency, ['KES', 'USD', 'EUR', 'GBP'], true);
              $currencyName = currency_display_name($currency);
          ?>
          <div class="cs-row"<?= $count === 0 || !$currencyStatsLoaded ? ' data-zero="true"' : '' ?>>
            <?php if ($hasFlag): ?><svg class="cs-flag" width="18" height="18" role="img" aria-label="<?= e($currencyName) ?> flag"><use href="#cs-flag-<?= e($currency) ?>"/></svg><?php else: ?><span class="cs-flag-fallback" role="img" aria-label="<?= e($currencyName) ?> flag"><?= e($currency) ?></span><?php endif; ?>
            <span class="cs-rcode"><?= e($currency) ?></span><span class="cs-spacer"></span><span class="cs-rval"><?= !$currencyStatsLoaded || $count === 0 ? '—' : number_format($count) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="cs-sub"><?= e($card['caption']) ?></div>
        <div class="cs-rows cs-rows--money">
          <?php foreach ($dashboardCurrencies as $currency):
              $data = $currencyStats[$currency] ?? [];
              $count = (int) ($data[$card['count_key']] ?? 0);
              $amount = $data[$card['amount_key']] ?? null;
              $hasValue = $currencyStatsLoaded && $count > 0 && is_numeric($amount);
              $hasFlag = in_array($currency, ['KES', 'USD', 'EUR', 'GBP'], true);
              $currencyName = currency_display_name($currency);
          ?>
          <div class="cs-row"<?= !$hasValue ? ' data-zero="true"' : '' ?>>
            <?php if ($hasFlag): ?><svg class="cs-flag" width="18" height="18" role="img" aria-label="<?= e($currencyName) ?> flag"><use href="#cs-flag-<?= e($currency) ?>"/></svg><?php else: ?><span class="cs-flag-fallback" role="img" aria-label="<?= e($currencyName) ?> flag"><?= e($currency) ?></span><?php endif; ?>
            <span class="cs-rcode"><?= e($currency) ?></span><span class="cs-spacer"></span><?php if ($hasValue): ?><span class="cs-rcount"><?= number_format($count) ?> inv.</span><?php endif; ?><span class="cs-rval"><?= $hasValue ? number_format((float) $amount, 2, '.', ',') : '—' ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="cs-foot"><span class="cs-foot-label">Total in KES</span><span class="cs-foot-amt"><span class="cs-foot-cur">KES</span><span class="cs-foot-num"><?= $cardTotalAvailable ? number_format($cardTotal, 2, '.', ',') : '—' ?></span></span></div>
        <?php endif; ?>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<div class="dashboard-grid">
  <section class="card card__pad dashboard-card option-card option-card--revenue">
    <div class="option-header">
      <div><h2 class="option-title">Revenue received</h2><p class="option-subtitle">Monthly payments for the last six months</p></div>
      <span class="option-period">Last 6 months</span>
    </div>
    <div class="option-summary">
      <div class="option-stat option-stat--primary">
        <span class="option-stat__label">This month</span>
        <div class="option-stat__value-row"><strong class="option-stat__value tnum"><?= $fxAvailable ? 'KES ' . number_format($currentMonthRevenue, 2, '.', ',') : 'Unavailable' ?></strong>
          <?php if ($revenueChange !== null): ?><span class="option-change<?= $revenueChange < 0 ? ' option-change--down' : ($revenueChange === 0.0 ? ' option-change--flat' : '') ?>"><?= $revenueChange < 0 ? '↓' : ($revenueChange === 0.0 ? '→' : '↑') ?> <?= number_format(abs($revenueChange), 1) ?>% vs <?= e($revenueMonths[4]['label']) ?></span><?php endif; ?>
        </div>
      </div>
      <div class="option-stat option-stat--secondary">
        <span class="option-stat__label">Monthly average</span>
        <strong class="option-stat__value tnum"><?= $fxAvailable ? 'KES ' . number_format($revenueAverage, 2, '.', ',') : 'Unavailable' ?></strong>
        <span class="option-stat__detail">Across 6 months</span>
      </div>
    </div>
    <?php if (!$fxAvailable): ?><p class="empty">Revenue conversion is unavailable until exchange rates can be retrieved.</p><?php else: ?>
    <div class="revenue-chart">
      <div class="revenue-chart__axis"><?php foreach ($revenueAxisTicks as $tick): $tickPosition = (int) round(100 - ($tick['value'] * 100)); ?><span class="is-chart-position-<?= $tickPosition ?>"><?= e($tick['label']) ?></span><?php endforeach; ?></div>
      <div class="revenue-chart__plot">
        <?php foreach ($revenueAxisTicks as $tick): $tickPosition = (int) round(100 - ($tick['value'] * 100)); ?><span class="revenue-chart__gridline is-chart-position-<?= $tickPosition ?><?= $tick['value'] === 0 ? ' revenue-chart__gridline--zero' : '' ?>" aria-hidden="true"></span><?php endforeach; ?>
        <div class="revenue-chart__bars" aria-hidden="true">
          <?php foreach ($revenueMonths as $month): $barHeight = $month['value'] > 0 ? max(1, ($month['value'] / $revenueAxisMax) * 100) : 0; ?>
          <?php $barTenths = (int) round($barHeight * 10); ?>
          <div class="revenue-chart__bar-wrap">
            <span class="revenue-chart__value<?= $month['current'] ? ' revenue-chart__value--current' : '' ?> tnum" aria-hidden="true"><span class="revenue-chart__value-desktop"><?= e($revenueAxisLabel($month['value'])) ?></span><span class="revenue-chart__value-mobile"><?= e($revenueMobileLabel($month['value'])) ?></span></span>
            <span class="revenue-chart__bar pct-tens-<?= intdiv($barTenths, 100) ?> pct-ones-<?= intdiv($barTenths % 100, 10) ?> pct-decimals-<?= $barTenths % 10 ?><?= $month['current'] ? ' revenue-chart__bar--current' : '' ?>"></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="revenue-chart__months" aria-hidden="true"><?php foreach ($revenueMonths as $month): ?><span class="<?= $month['current'] ? 'is-current' : '' ?>"><?= e($month['label']) ?></span><?php endforeach; ?></div>
    <ul class="sr-only"><?php foreach ($revenueMonths as $month): ?><li><?= e($month['label']) ?>: KES <?= number_format($month['value'], 2, '.', ',') ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <p class="option-rate-note"><svg class="option-icon" aria-hidden="true"><use href="#i-info"/></svg><span>Converted to KES using invoice issue-date rates<?= $fxRateDate !== '' ? '. Rates as of ' . e($fxRateDate) : '' ?>.</span></p>
  </section>
  <section class="card card__pad dashboard-card option-card option-card--invoices">
    <div class="option-header">
      <div><h2 class="option-title">Recent invoices</h2><p class="option-subtitle">Your latest billing activity</p></div>
      <a class="option-link" href="<?= e(app_url('/dashboard/invoices.php')) ?>">View all<svg class="option-icon" aria-hidden="true"><use href="#i-arrow-right"/></svg></a>
    </div>
    <div class="option-summary">
      <div class="option-stat">
        <span class="option-stat__label">Awaiting payment</span>
        <strong class="option-stat__value tnum"><?= $invoiceSummaryAvailable ? 'KES ' . number_format($invoiceSummary['awaiting_amount'], 2, '.', ',') : 'Unavailable' ?></strong>
        <span class="option-stat__detail"><?= number_format($invoiceSummary['awaiting_count']) ?> invoices</span>
      </div>
      <div class="option-stat option-stat--secondary">
        <span class="option-stat__label">Overdue</span>
        <strong class="option-stat__value option-stat__value--overdue tnum"><?= $invoiceSummaryAvailable ? 'KES ' . number_format($invoiceSummary['overdue_amount'], 2, '.', ',') : 'Unavailable' ?></strong>
        <span class="option-stat__detail"><?= number_format($invoiceSummary['overdue_count']) ?> invoice<?= $invoiceSummary['overdue_count'] === 1 ? '' : 's' ?></span>
      </div>
    </div>
    <?php if ($recent): ?>
    <div class="invoice-list-option">
      <div class="invoice-list-option__head"><span>Invoice</span><span>Customer</span><span>Amount</span><span>Status</span></div>
      <?php foreach ($recent as $invoice): $hasRate = $invoice['currency_code'] === 'KES' || $invoice['kes_exchange_rate'] !== null; $status = $invoice['status_display']; $statusClass = $status === 'issued' ? 'issued' : ($status === 'overdue' ? 'overdue' : ($status === 'paid' ? 'paid' : 'other')); ?>
      <div class="invoice-row">
        <a class="invoice-row__number" href="<?= e(app_url('/dashboard/invoice_view.php?id=' . $invoice['id'])) ?>"><?= e($invoice['invoice_number'] ?: 'Draft') ?></a>
        <span class="invoice-row__customer"><?= e($invoice['customer_name']) ?></span>
        <span class="invoice-row__amount tnum"><?= $fxAvailable && $hasRate ? 'KES ' . number_format((float) $invoice['total_kes'], 2, '.', ',') : 'Unavailable' ?></span>
        <span class="invoice-status invoice-status--<?= e($statusClass) ?>"><i aria-hidden="true"></i><?= e(status_label($status)) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?><p class="empty">No invoices yet. <a class="link" href="<?= e(app_url('/dashboard/create_invoice.php')) ?>">Create your first invoice</a>.</p><?php endif; ?>
  </section>
  <section class="card card__pad dashboard-card option-card option-card--linked">
    <div class="option-header">
      <div><h2 class="option-title">Recent payments</h2><p class="option-subtitle">Latest recorded receipts</p></div>
      <a class="option-link option-link--bottom" href="<?= e(app_url('/dashboard/payments.php')) ?>">View payments<svg class="option-icon" aria-hidden="true"><use href="#i-arrow-right"/></svg></a>
    </div>
    <div class="option-summary option-summary--payments">
      <div class="option-stat">
        <span class="option-stat__label">Latest receipts</span>
        <strong class="option-stat__value tnum"><?= $paymentSummaryAvailable ? 'KES ' . number_format($paymentTotal, 2, '.', ',') : 'Unavailable' ?></strong>
        <span class="option-stat__detail"><?= number_format(count($recentPayments)) ?> payments</span>
      </div>
      <div class="option-stat option-stat--secondary">
        <span class="option-stat__label">Last payment</span>
        <strong class="option-stat__value"><?= $latestPaymentDate !== null ? e($relativeDate($latestPaymentDate)) : '—' ?></strong>
        <span class="option-stat__detail"><?= $latestPaymentDate !== null ? e($formatDate($latestPaymentDate)) : 'No payments recorded' ?></span>
      </div>
    </div>
    <?php if ($recentPayments): ?>
    <div class="payment-list">
      <?php foreach ($recentPayments as $payment): $hasRate = $payment['currency_code'] === 'KES' || $payment['kes_exchange_rate'] !== null; $paymentDate = new DateTimeImmutable($payment['payment_date']); $mobileMoney = stripos((string) $payment['method'], 'mobile money') !== false; ?>
      <div class="payment-row">
        <span class="option-icon-tile option-icon-tile--payment"><svg class="option-icon option-icon--large" aria-hidden="true"><use href="<?= $mobileMoney ? '#i-phone' : '#i-bank' ?>"/></svg></span>
        <div class="payment-row__body">
          <strong class="payment-row__customer"><?= e($payment['customer_name']) ?></strong>
          <span class="payment-row__detail payment-row__invoice"><?= e($payment['invoice_number']) ?><span class="payment-row__separator"> · </span><span class="payment-row__method"><?= e($payment['method']) ?></span></span>
          <span class="payment-row__method-mobile"><?= e($payment['method']) ?></span>
        </div>
        <div class="payment-row__aside">
          <strong class="payment-row__amount tnum"><?= $fxAvailable && $hasRate ? 'KES ' . number_format((float) $payment['amount_kes'], 2, '.', ',') : 'Unavailable' ?></strong>
          <span class="payment-row__date"><?= e($relativeDate($paymentDate)) ?></span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?><p class="empty">No payments recorded yet.</p><?php endif; ?>
  </section>
  <section class="dashboard-card overdue-card">
    <header class="overdue-header">
      <h2>Overdue services</h2>
      <p>Invoices and services past their due or expiry date</p>
    </header>
    <div class="overdue-banner">
      <div>
        <div class="banner-label">NEEDS ATTENTION</div>
        <div class="banner-value"><?= number_format($overdueServiceTotal) ?> overdue item<?= $overdueServiceTotal === 1 ? '' : 's' ?></div>
        <div class="banner-sub">Across <?= count($overdueServiceRows) ?> service types</div>
      </div>
      <div class="banner-icon">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#c4351f" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="Warning"><path d="M12 3 2.5 20h19L12 3z"/><path d="M12 10v4M12 17.5v.01"/></svg>
      </div>
    </div>
    <div class="overdue-list">
      <?php foreach ($overdueServiceRows as $service): $isOverdue = $service['count'] > 0; ?>
      <div class="svc-row<?= $isOverdue ? ' is-overdue' : '' ?>">
        <div class="svc-icon">
          <?php if ($service['icon'] === 'invoice'): ?>
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5z"/><path d="M14 3v5h5M9 14l2 2 4-4"/></svg>
          <?php elseif ($service['icon'] === 'domain'): ?>
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18"/></svg>
          <?php else: ?>
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
          <?php endif; ?>
        </div>
        <div class="svc-text">
          <div class="svc-name"><?= e($service['name']) ?></div>
          <div class="svc-status"><?= $isOverdue ? number_format($service['count']) . ' ' . ($service['count'] === 1 ? $service['singular_status'] : $service['plural_status']) : e($service['zero_status']) ?></div>
        </div>
        <div class="svc-pill"><?= number_format($service['count']) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
</div>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
