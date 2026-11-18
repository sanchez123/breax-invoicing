<header class="invoice-paper__head">
  <?php if ($logoUrl !== ''): ?><img class="invoice-paper__logo" src="<?= e($logoUrl) ?>" alt="<?= e($companyName) ?> logo"><?php else: ?><span class="invoice-paper__brand-fallback"><?= e($companyName) ?></span><?php endif; ?>
</header>
