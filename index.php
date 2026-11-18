<?php
require_once __DIR__ . '/includes/auth.php';

redirect(current_user() !== null ? '/dashboard/index.php' : '/login.php');
