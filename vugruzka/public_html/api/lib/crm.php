<?php
/**
 * Заявки и клиенты: статусы, разбор позиций корзины, привязка заявки к клиенту.
 * Используется приёмом заявки (api/applications.php), уведомлениями и админкой.
 */

const APP_STATUSES = [
    'new'     => 'новая',
    'work'    => 'в работе',
    'invoice' => 'счёт выставлен',
    'paid'    => 'оплачена',
    'shipped' => 'отгружена',
    'lost'    => 'отказ',
];

/** Следующий шаг для кнопки в карточке заявки. */
const APP_NEXT_STATUS = [
    'new'     => ['work', 'Взять в работу'],
    'work'    => ['invoice', 'Счёт выставлен'],
    'invoice' => ['paid', 'Оплачена'],
    'paid'    => ['shipped', 'Отгружена'],
];

/** С какого числа заявок клиент считается постоянным (решение Максима, 2026-11-12). */
const CLIENT_REGULAR_FROM = 3;

/** Клиенты, которые не заказывали дольше этого числа дней, — во вкладке «Давно не заказывали». */
const CLIENT_SLEEPING_DAYS = 60;

function app_status_label(string $s): string {
    return APP_STATUSES[$s] ?? $s;
}

/** Ключ телефона для поиска клиента: последние 10 цифр (8 913… и +7 913… совпадают). */
function phone_key(string $phone): string {
    $d = preg_replace('/\D+/', '', $phone) ?? '';
    return strlen($d) >= 10 ? substr($d, -10) : $d;
}

/** Ключи нескольких телефонов, записанных через запятую. */
function phone_keys_list(string $phones): string {
    $keys = [];
    foreach (preg_split('/[,;\n]+/', $phones) ?: [] as $p) {
        $k = phone_key($p);
        if (strlen($k) >= 6) $keys[$k] = true;
    }
    return implode(',', array_keys($keys));
}

/**
 * Поле формы «Организация или ИНН»: ИНН — 10 или 12 цифр подряд (пробелы внутри допускаются),
 * остальное — название. Возвращает ['company' => ..., 'inn' => ...].
 */
function parse_org(string $org): array {
    $org = trim($org);
    $inn = '';
    if (preg_match('/(?<!\d)(\d[\d ]{8,14}\d)(?!\d)/u', $org, $m)) {
        $digits = str_replace(' ', '', $m[1]);
        if (strlen($digits) === 10 || strlen($digits) === 12) {
            $inn = $digits;
            $org = str_replace($m[1], '', $org);
        }
    }
    $org = preg_replace('/\bИНН\b[:№\s]*/iu', '', $org) ?? $org;
    $org = trim($org, " \t,;:/-—()");
    return ['company' => $org, 'inn' => $inn];
}

function fmt_num(float $n, int $digits = 3): string {
    $s = number_format($n, $digits, ',', ' ');
    if ($digits > 0) $s = rtrim(rtrim($s, '0'), ',');
    return $s;
}

function fmt_rub(?float $n): string {
    return $n === null ? '—' : number_format($n, 0, ',', ' ') . ' ₽';
}

/** Вес: до тонны — в кг, дальше — в тоннах. */
function fmt_weight(?float $kg): string {
    if ($kg === null || $kg <= 0) return '—';
    return $kg < 1000 ? fmt_num($kg, 0) . ' кг' : fmt_num($kg / 1000, 2) . ' т';
}

/**
 * Позиции корзины из calc_payload (старый формат — один объект, новый — массив).
 * Каждая: label, qty_text, qty_short, weight_kg, price, cut.
 */
function app_items($payload): array {
    if (is_string($payload)) $payload = $payload !== '' ? json_decode($payload, true) : null;
    if (!is_array($payload) || !$payload) return [];
    $raw = isset($payload['productLabel']) ? [$payload] : $payload;
    $out = [];
    foreach ($raw as $c) {
        if (!is_array($c) || count($out) >= 200) continue;
        $qty = (float)($c['qty'] ?? 0);
        if ($qty > 0) {
            $per = isset($c['areaM2'])
                ? fmt_num((float)$c['areaM2']) . ' м²'
                : (!empty($c['nemer']) ? '≈' : '') . fmt_num((float)($c['lengthM'] ?? 0)) . ' м';
            $qtyText = fmt_num($qty) . ' шт × ' . $per;
            $qtyShort = fmt_num($qty) . ' шт';
        } else {
            $unit = mb_substr(trim((string)($c['amountUnit'] ?? 'м')), 0, 10);
            $qtyText = fmt_num((float)($c['amount'] ?? 0)) . ' ' . $unit;
            $qtyShort = $qtyText;
        }
        $cut = (is_array($c['cut'] ?? null) && is_string($c['cut']['text'] ?? null)) ? trim($c['cut']['text']) : '';
        $out[] = [
            'label'     => mb_substr(trim((string)($c['productLabel'] ?? '')), 0, 300),
            'qty_text'  => $qtyText,
            'qty_short' => $qtyShort,
            'weight_kg' => (float)($c['weightKg'] ?? 0),
            'price'     => (float)($c['totalPrice'] ?? 0),
            'cut'       => mb_substr($cut, 0, 500),
        ];
    }
    return $out;
}

/** [сумма ₽ или null, вес кг или null] по позициям. */
function app_items_totals(array $items): array {
    if (!$items) return [null, null];
    $sum = 0.0; $kg = 0.0;
    foreach ($items as $i) { $sum += $i['price']; $kg += $i['weight_kg']; }
    return [(int)round($sum), round($kg, 1)];
}

function app_event(PDO $pdo, string $appId, string $text): void {
    try {
        $pdo->prepare('INSERT INTO application_events (application_id, text) VALUES (?, ?)')
            ->execute([$appId, mb_substr($text, 0, 500)]);
    } catch (Throwable $e) {
        error_log('app_event: ' . $e->getMessage());
    }
}

/**
 * Находит клиента по телефону, потом по почте, потом по ИНН; если нет — заводит нового.
 * Пустые поля найденного клиента дополняет данными заявки (заполненные не трогает).
 */
function client_find_or_create(PDO $pdo, string $name, string $phone, string $email, string $org): int {
    $pk = phone_key($phone);
    $email = mb_strtolower(trim($email));
    $o = parse_org($org);
    $id = null;

    if (strlen($pk) >= 6) {
        $st = $pdo->prepare('SELECT id FROM clients WHERE phone_key = ? OR FIND_IN_SET(?, extra_phone_keys) ORDER BY id LIMIT 1');
        $st->execute([$pk, $pk]);
        $id = $st->fetchColumn() ?: null;
    }
    if (!$id && $email !== '') {
        $st = $pdo->prepare('SELECT id FROM clients WHERE email = ? ORDER BY id LIMIT 1');
        $st->execute([$email]);
        $id = $st->fetchColumn() ?: null;
    }
    if (!$id && $o['inn'] !== '') {
        $st = $pdo->prepare('SELECT id FROM clients WHERE inn = ? ORDER BY id LIMIT 1');
        $st->execute([$o['inn']]);
        $id = $st->fetchColumn() ?: null;
    }

    if (!$id) {
        $pdo->prepare('INSERT INTO clients (name, company, inn, phone, phone_key, email) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([mb_substr($name, 0, 255), mb_substr($o['company'], 0, 255), $o['inn'], mb_substr($phone, 0, 64), $pk, mb_substr($email, 0, 255)]);
        return (int)$pdo->lastInsertId();
    }

    $st = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
    $st->execute([$id]);
    $c = $st->fetch();
    $set = [];
    $args = [];
    if ($c['name'] === '' && $name !== '') { $set[] = 'name = ?'; $args[] = mb_substr($name, 0, 255); }
    if ($c['email'] === '' && $email !== '') { $set[] = 'email = ?'; $args[] = mb_substr($email, 0, 255); }
    if ($c['company'] === '' && $o['company'] !== '') { $set[] = 'company = ?'; $args[] = mb_substr($o['company'], 0, 255); }
    if ($c['inn'] === '' && $o['inn'] !== '') { $set[] = 'inn = ?'; $args[] = $o['inn']; }
    if ($c['phone_key'] === '' && strlen($pk) >= 6) {
        $set[] = 'phone = ?'; $args[] = mb_substr($phone, 0, 64);
        $set[] = 'phone_key = ?'; $args[] = $pk;
    } elseif (strlen($pk) >= 6 && $c['phone_key'] !== $pk && !in_array($pk, explode(',', $c['extra_phone_keys']), true)) {
        // Нашли по почте/ИНН, а телефон другой — запоминаем как дополнительный
        $phones = trim($c['extra_phones'] . ($c['extra_phones'] !== '' ? ', ' : '') . $phone);
        $set[] = 'extra_phones = ?'; $args[] = mb_substr($phones, 0, 255);
        $set[] = 'extra_phone_keys = ?'; $args[] = phone_keys_list($phones);
    }
    if ($set) {
        $args[] = $id;
        $pdo->prepare('UPDATE clients SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
    }
    return (int)$id;
}

/** Сумма заявки для статистики: по счёту, если внесена, иначе предварительная с сайта. */
const APP_SUM_SQL = 'COALESCE(a.invoice_sum, a.items_total, 0)';

/** Сводка по клиенту: заявок, сумма (без отказов), первая и последняя дата. */
function client_stats(PDO $pdo, int $clientId): array {
    $st = $pdo->prepare('SELECT COUNT(*) cnt,
            SUM(CASE WHEN a.status <> \'lost\' THEN ' . APP_SUM_SQL . ' ELSE 0 END) total,
            MIN(a.created_at) first_at, MAX(a.created_at) last_at
        FROM applications a WHERE a.client_id = ?');
    $st->execute([$clientId]);
    $r = $st->fetch();
    return ['count' => (int)$r['cnt'], 'total' => (int)$r['total'], 'first_at' => $r['first_at'], 'last_at' => $r['last_at']];
}

/** Метка клиента: ['new'|'rep'|'reg', 'новый'|'повторный'|'постоянный']. */
function client_kind(int $count, bool $manual): array {
    if ($manual || $count >= CLIENT_REGULAR_FROM) return ['reg', 'постоянный'];
    if ($count >= 2) return ['rep', 'повторный'];
    return ['new', 'новый'];
}

/** Какая по счёту эта заявка у клиента (1, 2, 3…). */
function app_seq(PDO $pdo, int $clientId, int $num): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM applications WHERE client_id = ? AND num <= ?');
    $st->execute([$clientId, $num]);
    return (int)$st->fetchColumn();
}

/**
 * Старые заявки (до базы клиентов): считает итоги позиций и раскладывает по клиентам.
 * Вызывается админкой; после первого раза почти ничего не делает.
 */
function clients_backfill(PDO $pdo): int {
    $rows = $pdo->query('SELECT id, name, phone, email, org, calc_payload, items_total FROM applications
                         WHERE client_id IS NULL ORDER BY created_at, num LIMIT 2000')->fetchAll();
    $upd = $pdo->prepare('UPDATE applications SET client_id = ?, items_total = ?, items_weight_kg = ? WHERE id = ?');
    foreach ($rows as $r) {
        [$sum, $kg] = app_items_totals(app_items($r['calc_payload']));
        $cid = client_find_or_create($pdo, $r['name'], $r['phone'], $r['email'], $r['org']);
        $upd->execute([$cid, $r['items_total'] ?? $sum, $sum === null ? null : $kg, $r['id']]);
    }
    return count($rows);
}

/** Самые частые товары клиента: первое слово названия («Труба», «Лист»…) с числом заявок. */
function client_top_products(PDO $pdo, int $clientId, int $limit = 4): array {
    $st = $pdo->prepare('SELECT calc_payload FROM applications WHERE client_id = ? AND calc_payload IS NOT NULL');
    $st->execute([$clientId]);
    $freq = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) {
        $seen = [];
        foreach (app_items($p) as $i) {
            // Слова названия до первого с цифрой («Арматура А500С 12» → «арматура»), не больше двух
            $words = [];
            foreach (preg_split('/\s+/u', trim($i['label'])) ?: [] as $word) {
                if (preg_match('/\d/u', $word) || count($words) >= 2) break;
                $words[] = trim($word, ',.');
            }
            $w = mb_strtolower(implode(' ', $words));
            if ($w === '' || isset($seen[$w])) continue;
            $seen[$w] = true;
            $freq[$w] = ($freq[$w] ?? 0) + 1;
        }
    }
    arsort($freq);
    return array_slice($freq, 0, $limit, true);
}
