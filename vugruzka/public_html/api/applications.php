<?php
require __DIR__ . '/db.php';
require_once __DIR__ . '/lib/notify.php';
require_once __DIR__ . '/lib/crm.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') json_response(null, 200);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error' => 'Method not allowed'], 405);

$cfg = api_config();

// Медовая ловушка: это поле скрыто от людей через CSS, но боты, заполняющие
// форму "вслепую" по HTML (минуя фронтенд-проверку), часто его тоже заполняют.
// Отвечаем успехом, но ничего не сохраняем и никого не уведомляем.
if (trim($_POST['website'] ?? '') !== '') {
    json_response(['ok' => true]);
}

$name    = trim($_POST['name'] ?? '');
$phone   = trim($_POST['phone'] ?? '');
$email   = trim($_POST['email'] ?? '');
$comment = trim($_POST['comment'] ?? '');
$org     = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string)($_POST['org'] ?? '')) ?? ''), 0, 255); // «Организация или ИНН», необязательно

if ($name === '' || $phone === '') {
    json_response(['error' => 'Имя и телефон обязательны'], 400);
}

// Согласие на обработку персональных данных (галочка в форме, /soglasie/).
// Без него заявку не сохраняем.
if (($_POST['consent'] ?? '') !== '1') {
    json_response(['error' => 'Нужно согласие на обработку персональных данных'], 400);
}

$clientIp = client_ip();
$pdo = db_connect();

$recentStmt = $pdo->prepare('SELECT COUNT(*) FROM applications WHERE ip_address = ? AND created_at > NOW() - INTERVAL 1 MINUTE');
$recentStmt->execute([$clientIp]);
if ((int)$recentStmt->fetchColumn() > 0) {
    json_response(['error' => 'Заявка уже отправлена, подождите минуту перед повторной отправкой'], 429);
}

$dailyStmt = $pdo->prepare('SELECT COUNT(*) FROM applications WHERE ip_address = ? AND created_at > NOW() - INTERVAL 1 DAY');
$dailyStmt->execute([$clientIp]);
if ((int)$dailyStmt->fetchColumn() >= 5) {
    json_response(['error' => 'Слишком много заявок с вашего адреса за сегодня. Позвоните нам напрямую.'], 429);
}

$calcPayload = null;
$calcPayloadRaw = $_POST['calc_payload'] ?? '';
if ($calcPayloadRaw !== '') {
    $decoded = json_decode($calcPayloadRaw, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $calcPayload = $decoded;
    }
}

$specFilename   = '';
$attachmentPath = '';

if (!empty($_FILES['file']['name']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
    $allowedExt = ['pdf', 'xlsx', 'xls', 'docx', 'doc', 'csv', 'txt'];
    $origName = $_FILES['file']['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

    if (in_array($ext, $allowedExt, true) && $_FILES['file']['size'] <= 10 * 1024 * 1024) {
        if (!is_dir($cfg['uploads_dir'])) {
            mkdir($cfg['uploads_dir'], 0755, true);
        }
        $safeName = preg_replace('/[^a-zA-Z0-9.\-_]/', '_', $origName);
        $storedName = time() . '-' . bin2hex(random_bytes(4)) . '-' . $safeName;
        $dest = rtrim($cfg['uploads_dir'], '/') . '/' . $storedName;

        if (move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
            $specFilename   = $origName;
            $attachmentPath = $storedName;
        }
    }
}

$items = app_items($calcPayload);
[$itemsTotal, $itemsKg] = app_items_totals($items);

try {
    $id = uuid_v4();
    $stmt = $pdo->prepare(
        'INSERT INTO applications (id, name, phone, email, org, comment, spec_filename, attachment_path, calc_payload, items_total, items_weight_kg, ip_address, created_at)
         VALUES (:id, :name, :phone, :email, :org, :comment, :spec_filename, :attachment_path, :calc_payload, :items_total, :items_weight_kg, :ip_address, NOW())'
    );
    $stmt->execute([
        'id'              => $id,
        'name'            => $name,
        'phone'           => $phone,
        'email'           => $email,
        'org'             => $org,
        'comment'         => $comment,
        'spec_filename'   => $specFilename,
        'attachment_path' => $attachmentPath,
        'calc_payload'    => $calcPayload !== null ? json_encode($calcPayload, JSON_UNESCAPED_UNICODE) : null,
        'items_total'     => $itemsTotal,
        'items_weight_kg' => $itemsKg,
        'ip_address'      => $clientIp,
    ]);
    $num = (int)$pdo->query('SELECT num FROM applications WHERE id = ' . $pdo->quote($id))->fetchColumn();
} catch (Throwable $e) {
    error_log('application insert: ' . $e->getMessage());
    // Подстраховка, если файлы залиты раньше миграции (шаг 8): сохраняем заявку по-старому,
    // чтобы не потерять клиента. Номер и клиент появятся после миграции.
    try {
        $pdo->prepare('INSERT INTO applications (id, name, phone, email, comment, spec_filename, attachment_path, calc_payload, ip_address, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
            ->execute([$id, $name, $phone, $email, trim($comment . ($org !== '' ? "\nОрганизация: " . $org : '')),
                       $specFilename, $attachmentPath,
                       $calcPayload !== null ? json_encode($calcPayload, JSON_UNESCAPED_UNICODE) : null, $clientIp]);
        $num = 0;
    } catch (Throwable $e2) {
        error_log('application insert (legacy): ' . $e2->getMessage());
        json_response(['error' => 'Не удалось сохранить заявку'], 500);
    }
}

// Клиент: по телефону, почте или ИНН. Если что-то пошло не так, заявка всё равно
// сохранена — админка привяжет её при следующем открытии.
$client = null;
try {
    clients_backfill($pdo); // заявки до базы клиентов — сначала они, чтобы история шла по порядку
    $clientId = client_find_or_create($pdo, $name, $phone, $email, $org);
    $pdo->prepare('UPDATE applications SET client_id = ? WHERE id = ?')->execute([$clientId, $id]);
    $stats = client_stats($pdo, $clientId);
    $manual = (bool)$pdo->query('SELECT regular_manual FROM clients WHERE id = ' . (int)$clientId)->fetchColumn();
    [$kind, $kindLabel] = client_kind($stats['count'], $manual);
    $client = [
        'kind'       => $kind,
        'kind_label' => $kindLabel,
        'seq'        => app_seq($pdo, $clientId, $num),
        'count'      => $stats['count'],
        'total'      => $stats['total'],
        'url'        => SITE_ORIGIN . '/api/admin/client.php?id=' . $clientId,
    ];
} catch (Throwable $e) {
    error_log('application client: ' . $e->getMessage());
}
app_event($pdo, $id, 'Заявка с сайта');

// Сначала отвечаем посетителю, потом шлём уведомления: письмо может идти
// несколько секунд, форма не должна их ждать.
json_send(['ok' => true, 'id' => $id]);
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
ignore_user_abort(true);

// Ссылка на файл ведёт в админку: папка с вложениями снаружи закрыта.
$sent = notify_application($cfg, [
    'num'             => $num,
    'name'            => $name,
    'phone'           => $phone,
    'email'           => $email,
    'org'             => $org,
    'comment'         => $comment,
    'items'           => $items,
    'items_total'     => $itemsTotal,
    'items_weight_kg' => $itemsKg,
    'spec_filename'   => $specFilename,
    'attachment_url'  => $attachmentPath !== '' ? SITE_ORIGIN . '/api/admin/file.php?id=' . rawurlencode($id) : '',
    'admin_url'       => SITE_ORIGIN . '/api/admin/application.php?id=' . rawurlencode($id),
    'client'          => $client,
]);
if ($sent) {
    $parts = [];
    foreach ($sent as $ch => $ok) $parts[] = $ch . ($ok ? ' — ушло' : ' — ошибка (см. журнал ошибок хостинга)');
    app_event($pdo, $id, 'Уведомление: ' . implode(', ', $parts));
}
