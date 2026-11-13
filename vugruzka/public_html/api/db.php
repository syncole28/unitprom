<?php

function api_config(): array {
    static $cfg = null;
    if ($cfg === null) {
        $path = __DIR__ . '/config.php';
        if (!file_exists($path)) {
            json_response(['error' => 'Server error'], 500);
        }
        $cfg = require $path;
    }
    return $cfg;
}

function db_connect(): PDO {
    $cfg = api_config();
    $dsn = "mysql:host={$cfg['db_host']};dbname={$cfg['db_name']};charset=utf8mb4";
    try {
        return new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        // Текст ошибки (с именем базы и пользователя) — только в журнал хостинга
        error_log('db_connect: ' . $e->getMessage());
        json_response(['error' => 'Server error'], 500);
    }
}

// Адрес сайта: для CORS и ссылок в уведомлениях (вложение открывается через админку).
const SITE_ORIGIN = 'https://unit-prom.ru';

function json_response($data, int $status = 200): void {
    json_send($data, $status);
    exit;
}

/** Как json_response, но без exit: после ответа можно доделать работу (уведомления). */
function json_send($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    // API вызывает только сам сайт, чужим доменам браузер ответ не отдаст.
    header('Access-Control-Allow-Origin: ' . SITE_ORIGIN);
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    echo $data === null ? '' : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function client_ip(): string {
    // Для анти-спама заявок. Заголовок X-Forwarded-For клиент может прислать
    // свой, поэтому его первому значению не верим. Если запрос пришёл напрямую
    // (REMOTE_ADDR — внешний адрес), берём REMOTE_ADDR. Если через прокси
    // хостинга (REMOTE_ADDR — внутренний адрес), берём последний адрес в
    // X-Forwarded-For: его дописал сам прокси, подделать его клиент не может.
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($remote !== '' && filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return $remote;
    }
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        $parts = array_map('trim', explode(',', $xff));
        $last = end($parts);
        if ($last !== false && filter_var($last, FILTER_VALIDATE_IP)) return $last;
    }
    $real = trim($_SERVER['HTTP_X_REAL_IP'] ?? '');
    if ($real !== '' && filter_var($real, FILTER_VALIDATE_IP)) return $real;
    return $remote;
}

function uuid_v4(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Вставляет/обновляет категории. Используется импортом прайс-листа в админке.
 * Ничего не удаляет — категории, отсутствующие в $categories, остаются как есть.
 */
function upsert_categories(PDO $pdo, array $categories): int {
    if (!$categories) return 0;
    $stmt = $pdo->prepare(
        'INSERT INTO catalog_categories
            (slug, parent_slug, title, short_desc, steel, gost_main, price_from, in_stock, image, sort_order,
             filter_label, filter_ranges, filter1_label, filter1_type, filter1_values, filter2_label, filter2_type, filter2_values)
         VALUES
            (:slug, :parent_slug, :title, :short_desc, :steel, :gost_main, :price_from, :in_stock, :image, :sort_order,
             :filter_label, :filter_ranges, :filter1_label, :filter1_type, :filter1_values, :filter2_label, :filter2_type, :filter2_values)
         ON DUPLICATE KEY UPDATE
            parent_slug = VALUES(parent_slug), title = VALUES(title), short_desc = VALUES(short_desc),
            steel = VALUES(steel), gost_main = VALUES(gost_main), price_from = VALUES(price_from),
            in_stock = VALUES(in_stock), image = VALUES(image), sort_order = VALUES(sort_order),
            filter_label = VALUES(filter_label), filter_ranges = VALUES(filter_ranges),
            filter1_label = VALUES(filter1_label), filter1_type = VALUES(filter1_type), filter1_values = VALUES(filter1_values),
            filter2_label = VALUES(filter2_label), filter2_type = VALUES(filter2_type), filter2_values = VALUES(filter2_values)'
    );
    foreach ($categories as $i => $c) {
        $stmt->execute([
            'slug'           => $c['slug'],
            'parent_slug'    => $c['parent_slug'] ?? null,
            'title'          => $c['title'],
            'short_desc'     => $c['short_desc'] ?? '',
            'steel'          => $c['steel'] ?? '',
            'gost_main'      => $c['gost_main'] ?? '',
            'price_from'     => (int)($c['price_from'] ?? 0),
            'in_stock'       => (int)($c['in_stock'] ?? 0),
            'image'          => $c['image'] ?? '',
            'sort_order'     => $c['sort_order'] ?? $i,
            'filter_label'   => $c['filter_label'] ?? null,
            'filter_ranges'  => $c['filter_ranges'] ?? null,
            'filter1_label'  => $c['filter1_label'] ?? null,
            'filter1_type'   => $c['filter1_type'] ?? null,
            'filter1_values' => $c['filter1_values'] ?? null,
            'filter2_label'  => $c['filter2_label'] ?? null,
            'filter2_type'   => $c['filter2_type'] ?? null,
            'filter2_values' => $c['filter2_values'] ?? null,
        ]);
    }
    return count($categories);
}

/**
 * Вставляет/обновляет товары. Тоже ничего не удаляет — см. upsert_categories.
 */
function upsert_products(PDO $pdo, array $products): int {
    if (!$products) return 0;
    // Длина: пустая ячейка — не трогаем (у нового товара — по правилам «Ручных корректировок»),
    // число — своя длина, «немер 9–12» — немерная. См. adj_parse_length_cell в lib/adjustments.php.
    require_once __DIR__ . '/lib/adjustments.php';
    $stmt = $pdo->prepare(
        'INSERT INTO catalog_products
            (id, category_slug, label, weight_per_meter, cost_price_per_ton, sort_order, param_value, in_stock, param1_value, param2_value)
         VALUES
            (:id, :category_slug, :label, :weight_per_meter, :cost_price_per_ton, :sort_order, :param_value, :in_stock, :param1_value, :param2_value)
         ON DUPLICATE KEY UPDATE
            category_slug = VALUES(category_slug), label = VALUES(label), weight_per_meter = VALUES(weight_per_meter),
            cost_price_per_ton = VALUES(cost_price_per_ton), sort_order = VALUES(sort_order),
            param_value = VALUES(param_value), in_stock = VALUES(in_stock),
            param1_value = VALUES(param1_value), param2_value = VALUES(param2_value)'
    );
    $setLength = $pdo->prepare('UPDATE catalog_products SET length_m = ?, length_nemer = ? WHERE id = ?');
    foreach ($products as $i => $p) {
        $cost = $p['cost_price_per_ton'] ?? 0;
        $stmt->execute([
            'id'                 => $p['id'],
            'category_slug'      => $p['category_slug'],
            'label'              => $p['label'],
            'weight_per_meter'   => (float)($p['weight_per_meter'] ?? 0),
            'cost_price_per_ton' => (int)$cost,
            'sort_order'         => $p['sort_order'] ?? $i,
            'param_value'        => isset($p['param_value']) && $p['param_value'] !== '' ? (float)$p['param_value'] : null,
            'in_stock'           => (int)($p['in_stock'] ?? 0),
            'param1_value'       => $p['param1_value'] ?? null,
            'param2_value'       => $p['param2_value'] ?? null,
        ]);
        if (isset($p['length_m']) && trim((string)$p['length_m']) !== '') {
            $len = adj_parse_length_cell($p['length_m']);
            $setLength->execute([$len['length_m'], $len['length_nemer'], $p['id']]);
        }
    }
    return count($products);
}
