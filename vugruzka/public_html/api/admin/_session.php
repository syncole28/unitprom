<?php
/**
 * Сессия админки с защищёнными флагами cookie:
 * Secure — только по https; HttpOnly — недоступна скриптам на странице;
 * SameSite=Lax — не уходит с запросами с чужих сайтов (кроме обычного перехода по ссылке,
 * чтобы ссылка на файл из письма открывалась сразу, если вы уже вошли).
 */
function admin_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('unitprom_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/api/admin/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    // Админку не встраивать в чужие страницы и не кешировать
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: frame-ancestors 'none'");
    header('Cache-Control: no-store');
}
