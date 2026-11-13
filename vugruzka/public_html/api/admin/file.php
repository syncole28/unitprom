<?php
/**
 * Скачивание вложения из заявки. Папка uploads/applications снаружи закрыта
 * (Require all denied), поэтому файлы отдаются только отсюда и только после входа.
 */
require __DIR__ . '/_auth.php';
admin_require_login();

$pdo = db_connect();
$stmt = $pdo->prepare('SELECT attachment_path, spec_filename FROM applications WHERE id = ?');
$stmt->execute([(string)($_GET['id'] ?? '')]);
$row = $stmt->fetch();

$cfg = api_config();
$dir = realpath($cfg['uploads_dir'] ?? (__DIR__ . '/../uploads/applications'));
$path = ($row && $row['attachment_path'] !== '' && $dir)
    ? realpath($dir . '/' . basename($row['attachment_path']))
    : false;

if (!$path || !str_starts_with($path, $dir . DIRECTORY_SEPARATOR) || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Файл не найден';
    exit;
}

$name = $row['spec_filename'] !== '' ? $row['spec_filename'] : basename($path);
$ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
header('Content-Type: application/octet-stream');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($path));
header("Content-Disposition: attachment; filename=\"{$ascii}\"; filename*=UTF-8''" . rawurlencode($name));
readfile($path);
