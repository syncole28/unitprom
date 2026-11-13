<?php
require __DIR__ . '/_session.php';
admin_session_start();
require __DIR__ . '/../db.php';
$cfg = api_config();
$error = '';

// Защита от перебора пароля: не больше 10 неудачных попыток с одного адреса
// за 15 минут. Счётчик — в закрытой папке uploads (снаружи она недоступна).
const LOGIN_MAX_FAILS = 10;
const LOGIN_WINDOW = 900;
function login_throttle_file(): string {
    return __DIR__ . '/../uploads/.login_fails.json';
}
function login_fails_load(): array {
    $raw = @file_get_contents(login_throttle_file());
    $data = $raw ? json_decode($raw, true) : [];
    $now = time();
    return array_filter(is_array($data) ? $data : [], fn($v) => is_array($v) && ($v['t'] ?? 0) > $now - LOGIN_WINDOW);
}
function login_fails_save(array $data): void {
    @file_put_contents(login_throttle_file(), json_encode($data), LOCK_EX);
}
$ipKey = hash('sha256', client_ip());
$fails = login_fails_load();
$locked = ($fails[$ipKey]['n'] ?? 0) >= LOGIN_MAX_FAILS;

if (!empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $locked) {
    http_response_code(429);
    $error = 'Слишком много неудачных попыток. Попробуйте через 15 минут.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    if (!empty($cfg['admin_password_hash']) && password_verify($password, $cfg['admin_password_hash'])) {
        unset($fails[$ipKey]);
        login_fails_save($fails);
        $next = $_SESSION['after_login'] ?? 'index.php';
        unset($_SESSION['after_login']);
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        header('Location: ' . $next);
        exit;
    }
    $fails[$ipKey] = ['n' => ($fails[$ipKey]['n'] ?? 0) + 1, 't' => time()];
    login_fails_save($fails);
    $error = 'Неверный пароль';
    usleep(1000000); // задержка против перебора
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Вход — Юнитпром админка</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <div class="login-box">
    <h1>Юнитпром</h1>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post">
      <div class="field-group">
        <label for="password">Пароль</label>
        <input type="password" id="password" name="password" autofocus required>
      </div>
      <button type="submit" class="btn" style="width:100%">Войти</button>
    </form>
  </div>
</body>
</html>
