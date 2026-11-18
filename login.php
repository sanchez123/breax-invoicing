<?php
require_once __DIR__ . '/includes/auth.php';
if (current_user() !== null) {
    redirect('/dashboard/index.php');
}

$loginError = '';
$hasUsers = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $emailInput = $_POST['email'] ?? '';
    $passwordInput = $_POST['password'] ?? '';
    $email = is_string($emailInput) ? trim($emailInput) : '';
    $password = is_string($passwordInput) ? $passwordInput : '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $loginError = 'Enter a valid email address and password.';
    } else {
        $statement = db()->prepare('SELECT id, name, email, password_hash FROM users WHERE email = ? LIMIT 1');
        $statement->execute([$email]);
        $user = $statement->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
            login_user($user);
            redirect('/dashboard/index.php');
        }
        $loginError = 'The email address or password is incorrect.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign in · Breax Tech Ltd. Invoicing</title>
  <meta name="theme-color" content="#141d38">
  <link rel="icon" type="image/png" href="assets/images/favicon.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/styles.css">
  <script src="assets/js/app.js" defer></script>
</head>
<body class="page-login">
<svg class="sprite" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
    <symbol id="i-dashboard" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></symbol>
    <symbol id="i-invoice" viewBox="0 0 24 24"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/></symbol>
    <symbol id="i-clients" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.4c2.2.6 3.5 2.4 3.5 5.6"/></symbol>
    <symbol id="i-box" viewBox="0 0 24 24"><path d="M12 3 3.5 7.5v9L12 21l8.5-4.5v-9z"/><path d="M3.5 7.5 12 12l8.5-4.5M12 12v9"/></symbol>
    <symbol id="i-card" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M3 10h18"/></symbol>
    <symbol id="i-reports" viewBox="0 0 24 24"><path d="M5 20v-6M12 20V6M19 20v-10"/></symbol>
    <symbol id="i-settings" viewBox="0 0 24 24"><path d="M4 6h8M16 6h4M4 12h3M11 12h9M4 18h10M18 18h2"/><circle cx="14" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="16" cy="18" r="2"/></symbol>
    <symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></symbol>
    <symbol id="i-bell" viewBox="0 0 24 24"><path d="M6 16v-5a6 6 0 0 1 12 0v5l1.5 2h-15z"/><path d="M10 21a2 2 0 0 0 4 0"/></symbol>
    <symbol id="i-chevron" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></symbol>
    <symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
    <symbol id="i-menu" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></symbol>
    <symbol id="i-close" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></symbol>
    <symbol id="i-globe" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.6 2.6 3.9 5.6 3.9 9s-1.3 6.4-3.9 9c-2.6-2.6-3.9-5.6-3.9-9S9.4 5.6 12 3z"/></symbol>
    <symbol id="i-server" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/></symbol>
    <symbol id="i-cloud" viewBox="0 0 24 24"><path d="M7 18a4.5 4.5 0 0 1-.6-8.96A6 6 0 0 1 17.9 9.5 4 4 0 0 1 17.5 18z"/></symbol>
    <symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 3 5 6v5.5c0 4.4 3 8 7 9.5 4-1.5 7-5.1 7-9.5V6z"/><path d="m9 12 2.2 2.2L15.5 10"/></symbol>
    <symbol id="i-pen" viewBox="0 0 24 24"><path d="M4 20l4-1 11-11a2.1 2.1 0 0 0-3-3L5 16z"/><path d="M14 7l3 3"/></symbol>
    <symbol id="i-wrench" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94z"/></symbol>
    <symbol id="i-code" viewBox="0 0 24 24"><path d="m8 8-4 4 4 4M16 8l4 4-4 4"/></symbol>
    <symbol id="i-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6 8-6s8 2 8 6"/></symbol>
    <symbol id="i-logout" viewBox="0 0 24 24"><path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3M16 8l4 4-4 4M20 12H9"/></symbol>
    <symbol id="i-eye" viewBox="0 0 24 24"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></symbol>
    <symbol id="i-eye-off" viewBox="0 0 24 24"><path d="M3 3l18 18"/><path d="M6.5 6.9A17 17 0 0 0 2 12s3.6 7 10 7a9.7 9.7 0 0 0 4-.9M10.6 5.1A10 10 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 4"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></symbol>
  </svg>  
  <div class="auth">
    <section class="auth__panel">
      <img src="./assets/images/logo.png" class="login-logo" alt="Breax Technologies. Smart Invoicing. Faster Business.">

      <main class="auth__main">
        <div class="auth__form">
          <h1 class="display-lg">Welcome back</h1>
          <p class="auth__sub">Sign in to create and track your invoices.</p>

          <form class="form" id="login-form" method="post" action="<?= e(app_url('/login.php')) ?>" novalidate>
            <?= csrf_field() ?>
            <?php if ($loginError !== ''): ?><p class="notice notice--error" role="alert"><?= e($loginError) ?></p><?php endif; ?>
            <div class="field">
              <label class="field__label" for="email">Email address</label>
              <input class="input" id="email" name="email" type="email" placeholder="you@company.com" autocomplete="email" aria-describedby="email-error" data-validation-required="true" required>
              <p class="field__error" id="email-error" hidden></p>
            </div>

            <div class="field">
              <div class="field__row">
                <label class="field__label" for="password">Password</label>
                <span class="text-mute">Use your account email</span>
              </div>
              <div class="input-wrap">
                <input class="input input--has-action" id="password" name="password" type="password" placeholder="Enter your password" autocomplete="current-password" aria-describedby="password-error" data-validation-required="true" required>
                <button class="input-action" type="button" data-toggle-password aria-label="Show password" aria-pressed="false">
                  <svg class="icon"><use href="#i-eye"/></svg>
                </button>
              </div>
              <p class="field__error" id="password-error" hidden></p>
            </div>

            <button class="btn btn--primary btn--block" type="submit" id="login-submit">Sign in</button>
          </form>

          <?php if (!$hasUsers): ?><p class="auth__alt">First time setup? <a class="link" href="<?= e(app_url('/register.php')) ?>">Create the administrator account</a></p><?php endif; ?>
        </div>
      </main>

      <footer class="auth__foot">&copy; <?= date('Y') ?> Breax Technologies Limited.</footer>
    </section>

    <aside class="showcase" aria-label="Product preview">
      <div class="showcase__inner">
        <h2 class="display-xl">Smart invoicing.<br>Faster business.</h2>
        <p class="showcase__lead">Invoice domains, hosting, SSL, design, SEO and web development in minutes, and see who has paid at a glance.</p>

        <div class="preview">
          <div class="preview__head">
            <div>
              <p class="preview__title">Invoice INV-1043</p>
              <p class="preview__client">Nairobi Tailors Co.</p>
            </div>
            <span class="pill pill--paid">Paid</span>
          </div>
          <div class="preview__lines">
            <p class="preview__line"><span>cPanel Executive 30 GB</span><span>KES 12,000</span></p>
            <p class="preview__line"><span>SSL Certificate</span><span>KES 4,500</span></p>
            <p class="preview__line"><span>Domain Registration</span><span>KES 1,500</span></p>
          </div>
          <p class="preview__total"><span class="preview__total-label">Total</span><span class="preview__total-value">KES 18,000</span></p>
        </div>
      </div>
    </aside>
  </div>
</body>
</html>
