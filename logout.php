<?php
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/login.php');
}
verify_csrf();
logout_user();
header('Location: ' . app_url('/login.php'));
exit;