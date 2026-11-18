<?php
$fxPanel = exchange_rates_panel_data($pdo);
$fxNames = [
    'KES' => 'Kenyan Shilling',
    'USD' => 'US Dollar',
    'EUR' => 'Euro',
    'GBP' => 'British Pound',
];
$fxUpdated = $fxPanel['updated_timestamp'] !== null
    ? (new DateTimeImmutable('@' . $fxPanel['updated_timestamp']))->setTimezone(new DateTimeZone('Africa/Nairobi'))
    : null;
?>
<section class="fx" aria-labelledby="fx-title">
  <div class="fx-head">
    <div>
      <h2 class="cs-title" id="fx-title">Exchange rates</h2>
      <div class="fx-sub">Base currency KES. Rates are per 1 unit of the foreign currency.</div>
    </div>
    <div class="fx-updated" data-stale="<?= $fxPanel['is_stale'] ? 'true' : 'false' ?>"><span class="fx-live"></span><span>Updated <?php if ($fxUpdated !== null): ?><time datetime="<?= e($fxUpdated->format('Y-m-d\TH:i:sP')) ?>"><?= e($fxUpdated->format('j M Y, H:i')) ?></time><?= $fxPanel['is_stale'] ? ' (may be out of date)' : '' ?><?php else: ?>unavailable<?php endif; ?></span></div>
  </div>
  <div class="fx-grid">
    <article class="fx-card fx-card--base">
      <div class="fx-id">
        <svg class="fx-flag" width="40" height="40" viewBox="0 0 40 40" role="img" aria-label="KES flag"><clipPath id="fx-c-KES"><circle cx="20" cy="20" r="20"/></clipPath><g clip-path="url(#fx-c-KES)"><rect width="40" height="40" fill="#006600"/><rect width="40" height="27" fill="#bb0000"/><rect width="40" height="13" fill="#111111"/><rect y="12.5" width="40" height="2" fill="#fff"/><rect y="25.5" width="40" height="2" fill="#fff"/><ellipse cx="20" cy="20" rx="4.5" ry="8" fill="#bb0000" stroke="#fff" stroke-width="1.5"/></g><circle cx="20" cy="20" r="19.5" fill="none" stroke="rgba(20,29,56,0.18)"/></svg>
        <div><div class="fx-code">KES</div><div class="fx-name"><?= e($fxNames['KES']) ?></div></div>
      </div>
      <div class="fx-base-title">Base currency</div>
      <div class="fx-base-note">All amounts are recorded in KES. Rates below are shown against it.</div>
    </article>
    <?php foreach ($fxPanel['currencies'] as $fxCurrency):
        $fxCurrency = strtoupper((string) $fxCurrency);
        $fxRate = $fxPanel['rates'][$fxCurrency] ?? null;
        $fxHasRate = is_numeric($fxRate) && (float) $fxRate > 0;
        $fxName = $fxNames[$fxCurrency] ?? $fxCurrency;
    ?>
    <article class="fx-card">
      <div class="fx-id">
        <?php if ($fxCurrency === 'USD'): ?>
        <svg class="fx-flag" width="40" height="40" viewBox="0 0 40 40" role="img" aria-label="USD flag"><clipPath id="fx-c-USD"><circle cx="20" cy="20" r="20"/></clipPath><g clip-path="url(#fx-c-USD)"><rect width="40" height="40" fill="#fff"/><rect y="0.0" width="40" height="5.7" fill="#b22234"/><rect y="11.4" width="40" height="5.7" fill="#b22234"/><rect y="22.9" width="40" height="5.7" fill="#b22234"/><rect y="34.3" width="40" height="5.7" fill="#b22234"/><rect width="20" height="22.9" fill="#3c3b6e"/><circle cx="4" cy="4" r="1.2" fill="#fff"/><circle cx="4" cy="10" r="1.2" fill="#fff"/><circle cx="4" cy="16" r="1.2" fill="#fff"/><circle cx="10" cy="4" r="1.2" fill="#fff"/><circle cx="10" cy="10" r="1.2" fill="#fff"/><circle cx="10" cy="16" r="1.2" fill="#fff"/><circle cx="16" cy="4" r="1.2" fill="#fff"/><circle cx="16" cy="10" r="1.2" fill="#fff"/><circle cx="16" cy="16" r="1.2" fill="#fff"/></g><circle cx="20" cy="20" r="19.5" fill="none" stroke="rgba(20,29,56,0.18)"/></svg>
        <?php elseif ($fxCurrency === 'EUR'): ?>
        <svg class="fx-flag" width="40" height="40" viewBox="0 0 40 40" role="img" aria-label="EUR flag"><clipPath id="fx-c-EUR"><circle cx="20" cy="20" r="20"/></clipPath><g clip-path="url(#fx-c-EUR)"><rect width="40" height="40" fill="#003399"/><circle cx="31.0" cy="20.0" r="1.5" fill="#ffcc00"/><circle cx="29.5" cy="25.5" r="1.5" fill="#ffcc00"/><circle cx="25.5" cy="29.5" r="1.5" fill="#ffcc00"/><circle cx="20.0" cy="31.0" r="1.5" fill="#ffcc00"/><circle cx="14.5" cy="29.5" r="1.5" fill="#ffcc00"/><circle cx="10.5" cy="25.5" r="1.5" fill="#ffcc00"/><circle cx="9.0" cy="20.0" r="1.5" fill="#ffcc00"/><circle cx="10.5" cy="14.5" r="1.5" fill="#ffcc00"/><circle cx="14.5" cy="10.5" r="1.5" fill="#ffcc00"/><circle cx="20.0" cy="9.0" r="1.5" fill="#ffcc00"/><circle cx="25.5" cy="10.5" r="1.5" fill="#ffcc00"/><circle cx="29.5" cy="14.5" r="1.5" fill="#ffcc00"/></g><circle cx="20" cy="20" r="19.5" fill="none" stroke="rgba(20,29,56,0.18)"/></svg>
        <?php elseif ($fxCurrency === 'GBP'): ?>
        <svg class="fx-flag" width="40" height="40" viewBox="0 0 40 40" role="img" aria-label="GBP flag"><clipPath id="fx-c-GBP"><circle cx="20" cy="20" r="20"/></clipPath><g clip-path="url(#fx-c-GBP)"><rect width="40" height="40" fill="#012169"/><path d="M0 0L40 40M40 0L0 40" stroke="#fff" stroke-width="7"/><path d="M0 0L40 40M40 0L0 40" stroke="#c8102e" stroke-width="2.5"/><path d="M20 0V40M0 20H40" stroke="#fff" stroke-width="11"/><path d="M20 0V40M0 20H40" stroke="#c8102e" stroke-width="6.5"/></g><circle cx="20" cy="20" r="19.5" fill="none" stroke="rgba(20,29,56,0.18)"/></svg>
        <?php else: ?>
        <span class="fx-flag-fallback" role="img" aria-label="<?= e($fxCurrency) ?> flag"><?= e($fxCurrency) ?></span>
        <?php endif; ?>
        <div><div class="fx-code"><?= e($fxCurrency) ?></div><div class="fx-name"><?= e($fxName) ?></div></div>
      </div>
      <?php if ($fxHasRate): ?>
      <div class="fx-rate-row"><span class="fx-cur">KES</span><span class="fx-rate"><?= e(number_format((float) $fxRate, 2, '.', ',')) ?></span></div>
      <div class="fx-per">per 1 <?= e($fxCurrency) ?></div>
      <div class="fx-inv">1 KES = <?= e(number_format(1 / (float) $fxRate, 4, '.', '')) ?> <?= e($fxCurrency) ?></div>
      <?php else: ?>
      <div class="fx-rate-row"><span class="fx-per">Rate unavailable</span></div>
      <div class="fx-per">Rate unavailable</div>
      <?php endif; ?>
    </article>
    <?php endforeach; ?>
  </div>
</section>
