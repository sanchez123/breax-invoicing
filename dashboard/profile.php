<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_auth();
$pdo = db();
$error = '';
$statement = $pdo->prepare('SELECT id,name,email,password_hash,receive_overdue_alerts FROM users WHERE id=?');
$statement->execute([current_user()['id']]);
$account = $statement->fetch();
if (!$account) {
    logout_user();
    redirect('/login.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $name = post_string('name', 150, true);
        $email = post_string('email', 190, true);
        $receiveOverdueAlerts = isset($_POST['receive_overdue_alerts']) ? 1 : 0;
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Enter a valid email address.');
        }
        if ($newPassword !== '' && (strlen($newPassword) < 12 || !password_verify($currentPassword, $account['password_hash']))) {
            throw new InvalidArgumentException('Changing your password requires the correct current password and a new password of at least 12 characters.');
        }
        if ($currentPassword !== '' && !password_verify($currentPassword, $account['password_hash'])) {
            throw new InvalidArgumentException('The current password is incorrect.');
        }
        if ($newPassword !== '') {
            $pdo->prepare('UPDATE users SET name=?,email=?,password_hash=?,receive_overdue_alerts=? WHERE id=?')->execute([$name,$email,password_hash($newPassword,PASSWORD_DEFAULT),$receiveOverdueAlerts,$account['id']]);
        } else {
            $pdo->prepare('UPDATE users SET name=?,email=?,receive_overdue_alerts=? WHERE id=?')->execute([$name,$email,$receiveOverdueAlerts,$account['id']]);
        }
        login_user(['id'=>$account['id'],'name'=>$name,'email'=>$email]);
        flash('Profile updated.');
        redirect('/dashboard/profile.php');
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        error_log('Profile update failed: ' . $exception->getMessage());
        $error = $exception->getCode() === '23000' ? 'That email address is already in use.' : 'The profile could not be updated.';
    }
}
$pageTitle = 'My profile';
$activePage = '';
$pageSubtitle = 'Account details and password';
require dirname(__DIR__) . '/includes/header.php';
?>
<div class="page-head"><div><h1 class="display-lg">My profile</h1><p class="page-head__sub">Update your sign-in details.</p></div></div>
<?php if ($error !== ''): ?><div class="notice notice--error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="card card__pad form-card">
  <div class="card__head"><div><h2 class="heading-lg">Account details</h2><p class="card__sub">Update the information and password used to sign in.</p></div></div>
  <form class="data-form data-form--grid" method="post"><?= csrf_field() ?>
    <label class="field"><span class="field__label">Name *</span><input class="input" name="name" maxlength="150" data-validation-required="true" required value="<?= e($_POST['name'] ?? $account['name']) ?>"></label>
    <label class="field"><span class="field__label">Email address *</span><input class="input" type="email" name="email" maxlength="190" data-validation-required="true" required value="<?= e($_POST['email'] ?? $account['email']) ?>"></label>
    <label class="field field--wide"><span><input type="checkbox" name="receive_overdue_alerts" value="1"<?= (isset($_POST['receive_overdue_alerts']) ? $_POST['receive_overdue_alerts'] === '1' : (int) $account['receive_overdue_alerts'] === 1) ? ' checked' : '' ?>> Receive internal overdue invoice alerts and renewal reports at this email address</span></label>
    <h2 class="heading-lg field--wide section-title">Change password</h2>
    <label class="field"><span class="field__label">Current password</span><input class="input" type="password" name="current_password" autocomplete="current-password"></label>
    <label class="field"><span class="field__label">New password (leave blank to keep current)</span><input class="input" type="password" name="new_password" minlength="12" data-validation-minlength="12" autocomplete="new-password"></label>
    <div class="form-actions field--wide"><button class="btn btn--primary">Save profile</button></div>
  </form>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
