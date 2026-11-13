<?php
require __DIR__ . '/_session.php';
admin_session_start();
require __DIR__ . '/../db.php';

function admin_config(): array {
    return api_config();
}

function admin_require_login(): void {
    if (empty($_SESSION['admin_logged_in'])) {
        // После входа вернём на ту страницу, куда шли (например, ссылка на файл из письма)
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (preg_match('#^/api/admin/[a-z_]+\.php(\?[^\s]*)?$#', $uri)) {
            $_SESSION['after_login'] = $uri;
        }
        header('Location: login.php');
        exit;
    }
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_check(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('Ошибка проверки формы (CSRF). Обновите страницу и попробуйте снова.');
    }
}

function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}
