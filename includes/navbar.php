<?php
$links = [
    'dashboard' => ['Dashboard', '/dashboard/index.php', 'dashboard'],
    'invoices' => ['Invoices', '/dashboard/invoices.php', 'invoice'],
    'public-links' => ['Public invoice links', '/dashboard/public_links.php', 'globe'],
    'customers' => ['Customers', '/dashboard/clients.php', 'clients'],
    'products' => ['Products & Services', '/dashboard/products.php', 'box'],
    'payments' => ['Payments', '/dashboard/payments.php', 'card'],
    'email' => ['Email delivery', '/dashboard/email.php', 'card'],
    'reports' => ['Reports', '/dashboard/reports.php', 'reports'],
];
?>
<nav class="nav">
  <?php foreach ($links as $key => [$label, $href, $icon]): ?>
    <a class="nav__link<?= $activePage === $key ? ' is-active' : '' ?>" href="<?= e(app_url($href)) ?>"<?= $activePage === $key ? ' aria-current="page"' : '' ?>>
      <svg class="icon"><use href="#i-<?= e($icon) ?>"/></svg><?= e($label) ?>
    </a>
  <?php endforeach; ?>
</nav>
<div class="sidebar__foot">
  <a class="nav__link<?= $activePage === 'settings' ? ' is-active' : '' ?>" href="<?= e(app_url('/dashboard/settings.php')) ?>"><svg class="icon"><use href="#i-settings"/></svg>Settings</a>
</div>