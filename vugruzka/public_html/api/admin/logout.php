<?php
require __DIR__ . '/_session.php';
admin_session_start();
$_SESSION = [];
$p = session_get_cookie_params();
setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
session_destroy();
header('Location: login.php');
