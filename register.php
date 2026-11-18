<?php
require_once __DIR__ . '/includes/auth.php';

if (current_user() !== null) {
    redirect('/dashboard/index.php');
}

$error = '';
$userCount = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($userCount > 0) {
    redirect('/login.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $nameInput = $_POST['name'] ?? '';
    $emailInput = $_POST['email'] ?? '';
    $passwordInput = $_POST['password'] ?? '';
    $name = is_string($nameInput) ? trim($nameInput) : '';
    $email = is_string($emailInput) ? trim($emailInput) : '';
    $password = is_string($passwordInput) ? $passwordInput : '';
    if ($name === '' || mb_strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter your name and a valid email address.';
    } elseif (strlen($password) < 12) {
        $error = 'Choose a password with at least 12 characters.';
    } else {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            $pdo->query('SELECT lock_id FROM app_install_lock WHERE lock_id=1 FOR UPDATE')->fetch();
            if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) {
                $pdo->rollBack();
                redirect('/login.php');
            }
            $statement = $pdo->prepare('INSERT INTO users (name, email, password_hash, can_send_invoice_emails, receive_overdue_alerts) VALUES (?, ?, ?, 1, 0)');
            $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $user = ['id' => $pdo->lastInsertId(), 'name' => $name, 'email' => $email];
            $pdo->commit();
            login_user($user);
            redirect('/dashboard/index.php');
        } catch (PDOException $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            error_log('Administrator account setup failed: ' . $exception->getMessage());
            $error = 'Account setup could not be completed. Check the email and try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Administrator setup · <?= e(APP_NAME) ?></title>
  <link rel="icon" type="image/png" href="<?= e(app_url('/assets/images/favicon.png')) ?>">
  <link rel="stylesheet" href="<?= e(app_url('/assets/css/styles.css')) ?>">
  <script src="<?= e(app_url('/assets/js/app.js')) ?>" defer></script>
</head>
<body class="page-login">
  <main class="setup-card">
    <img src="<?= e(app_url('/assets/images/logo.png')) ?>" class="login-logo" alt="Breax Technologies">
    <h1 class="display-md">Create your administrator account</h1>
    <p class="auth__sub">This one-time setup is available only while no users exist.</p>
    <?php if ($error !== ''): ?><p class="notice notice--error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form class="form" method="post">
      <?= csrf_field() ?>
      <label class="field"><span class="field__label">Name</span><input class="input" name="name" autocomplete="name" maxlength="150" data-validation-required="true" required></label>
      <label class="field"><span class="field__label">Email address</span><input class="input" type="email" name="email" autocomplete="email" maxlength="190" data-validation-required="true" required></label>
      <label class="field"><span class="field__label">Password (at least 12 characters)</span><input class="input" type="password" name="password" autocomplete="new-password" minlength="12" data-validation-required="true" required></label>
      <button class="btn btn--primary btn--block">Create account</button>
    </form>
  </main>
</body>
</html>