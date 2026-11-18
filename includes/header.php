<?php
require_once __DIR__ . '/auth.php';
require_auth();
$pageTitle = $pageTitle ?? APP_NAME;
$activePage = $activePage ?? '';
$pageSubtitle = $pageSubtitle ?? '';
$user = current_user();
$flashMessage = take_flash();
$appScriptPath = dirname(__DIR__) . '/assets/js/app.js';
$appScriptVersion = is_file($appScriptPath) ? (string) filemtime($appScriptPath) : '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
  <meta name="theme-color" content="#141d38">
  <link rel="icon" type="image/png" href="<?= e(app_url('/assets/images/favicon.png')) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(app_url('/assets/css/styles.css')) ?>">
  <?php if (!empty($invoiceFormStyles)): ?><link rel="stylesheet" href="<?= e(app_url('/assets/css/invoice-form.css')) ?>?v=<?= e((string) (filemtime(dirname(__DIR__) . '/assets/css/invoice-form.css') ?: 1)) ?>"><?php endif; ?>
  <?php if ($activePage === 'email'): ?><link rel="stylesheet" href="<?= e(app_url('/assets/css/email-delivery.css')) ?>?v=<?= e((string) (filemtime(dirname(__DIR__) . '/assets/css/email-delivery.css') ?: 1)) ?>"><?php endif; ?>
  <script src="<?= e(app_url('/assets/js/app.js')) ?>?v=<?= e($appScriptVersion) ?>" defer></script>
</head>
<body class="page-dashboard<?= $activePage === 'email' ? ' page-email-delivery' : '' ?>" data-overdue-admin-check="<?= e(app_url('/dashboard/overdue_check_background.php')) ?>">
<?php require __DIR__ . '/icons.php'; ?>
<?php require __DIR__ . '/currency_flag_sprite.php'; ?>
<aside class="sidebar" id="sidebar" aria-label="Main navigation">
  <div class="sidebar__head">
    <a class="brand brand--light" href="<?= e(app_url('/dashboard/index.php')) ?>" aria-label="Breax Invoicing home">
      <svg class="logo-mark" viewBox="0 0 44 44" aria-hidden="true"><rect class="logo-back" x="2" y="14" width="26" height="28" rx="7"/><rect class="logo-front" x="12" y="2" width="30" height="32" rx="9"/><path class="logo-line" d="M20 12h14M20 18h14M20 24h8"/></svg>
      <span class="brand__text"><span class="brand__name">Breax</span><span class="brand__sub">Invoicing</span></span>
    </a>
    <button class="sidebar__close" type="button" data-sidebar-close aria-label="Close menu"><svg class="icon"><use href="#i-close"/></svg></button>
  </div>
  <?php require __DIR__ . '/navbar.php'; ?>
</aside>
<div class="scrim" id="scrim" data-sidebar-close></div>
<div class="main">
  <header class="topbar">
    <button class="icon-btn topbar__menu" type="button" data-sidebar-toggle aria-label="Open menu" aria-controls="sidebar" aria-expanded="false"><svg class="icon"><use href="#i-menu"/></svg></button>
    <div class="topbar__heading"><strong><?= e($pageTitle) ?></strong><?php if ($pageSubtitle !== ''): ?><span><?= e($pageSubtitle) ?></span><?php endif; ?></div>
    <div class="topbar__actions">
      <div class="menu-wrap">
        <button class="profile-btn" type="button" data-menu-toggle="profile-menu" aria-haspopup="true" aria-expanded="false">
          <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($user['name'] ?? 'U', 0, 1))) ?></span>
          <span class="profile-btn__name"><?= e($user['name'] ?? 'User') ?></span><svg class="icon"><use href="#i-chevron"/></svg>
        </button>
        <div class="menu menu--narrow" id="profile-menu" hidden>
          <a class="menu__item" href="<?= e(app_url('/dashboard/profile.php')) ?>"><svg class="icon"><use href="#i-user"/></svg>My profile</a>
          <a class="menu__item" href="<?= e(app_url('/dashboard/settings.php')) ?>"><svg class="icon"><use href="#i-settings"/></svg>Settings</a>
          <form method="post" action="<?= e(app_url('/logout.php')) ?>"><?= csrf_field() ?><button class="menu__item" type="submit"><svg class="icon"><use href="#i-logout"/></svg>Sign out</button></form>
        </div>
      </div>
    </div>
  </header>
  <main class="content" id="main">
    <?php if ($flashMessage !== null): $customerFlash = $activePage === 'customers'; ?><div class="notice notice--<?= e($flashMessage['type']) ?><?= $customerFlash ? ' notice--customer' : '' ?>" role="<?= $customerFlash && $flashMessage['type'] === 'error' ? 'alert' : 'status' ?>"<?= $customerFlash && $flashMessage['type'] !== 'error' ? ' aria-live="polite"' : '' ?>><?php if ($customerFlash && $flashMessage['type'] === 'success'): ?><svg class="notice__icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="m5 10 3.2 3.2L15.5 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg><?php endif; ?><span class="notice__message"><?= e($flashMessage['message']) ?></span><?php if ($customerFlash): ?><button class="notice__dismiss" type="button" aria-label="Dismiss message" data-dismiss-notice>&times;</button><?php endif; ?></div><?php endif; ?>