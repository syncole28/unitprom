<?php
require __DIR__ . '/_auth.php';
admin_require_login();
require_once __DIR__ . '/../lib/crm.php';
$active = 'applications';

$pdo = db_connect();
clients_backfill($pdo); // старые заявки без клиента — разложить по клиентам

$status = (string)($_GET['status'] ?? '');
if ($status !== '' && !isset(APP_STATUSES[$status])) $status = '';
// Старая ссылка «Только новые» (с главной и из закладок)
if (($_GET['filter'] ?? '') === 'new') $status = 'new';
$q = trim((string)($_GET['q'] ?? ''));
$period = (string)($_GET['period'] ?? '');
$periods = ['' => 'За всё время', '7' => '7 дней', '30' => '30 дней', '90' => '90 дней', '365' => 'Год'];
if (!isset($periods[$period])) $period = '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 100;

// Условия без статуса — для счётчиков на вкладках
$where = [];
$args = [];
if ($period !== '') {
    $where[] = 'a.created_at > NOW() - INTERVAL ' . (int)$period . ' DAY';
}
if ($q !== '') {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $or = ['a.name LIKE ?', 'a.phone LIKE ?', 'a.email LIKE ?', 'a.org LIKE ?', 'a.comment LIKE ?', 'LOWER(CAST(a.calc_payload AS CHAR)) LIKE LOWER(?)',
           'a.manager_note LIKE ?', 'c.name LIKE ?', 'c.company LIKE ?', 'c.inn LIKE ?', 'c.email LIKE ?', 'c.extra_phones LIKE ?'];
    $args = array_merge($args, array_fill(0, count($or), $like));
    $digits = preg_replace('/\D+/', '', $q) ?? '';
    if (strlen($digits) >= 5) {
        $or[] = 'c.phone_key LIKE ?';
        $or[] = 'c.extra_phone_keys LIKE ?';
        $d = '%' . (strlen($digits) >= 10 ? substr($digits, -10) : $digits) . '%';
        $args[] = $d; $args[] = $d;
    }
    if (preg_match('/^(№\s*)?(\d{1,7})$/u', $q, $m)) {
        $or[] = 'a.num = ?';
        $args[] = (int)$m[2];
    }
    $where[] = '(' . implode(' OR ', $or) . ')';
}
$from = 'FROM applications a LEFT JOIN clients c ON c.id = a.client_id';
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$st = $pdo->prepare("SELECT a.status, COUNT(*) n $from $whereSql GROUP BY a.status");
$st->execute($args);
$counts = array_fill_keys(array_keys(APP_STATUSES), 0);
foreach ($st->fetchAll() as $r) $counts[$r['status']] = (int)$r['n'];
$countAll = array_sum($counts);

$listWhere = $where;
$listArgs = $args;
if ($status !== '') { $listWhere[] = 'a.status = ?'; $listArgs[] = $status; }
$listWhereSql = $listWhere ? 'WHERE ' . implode(' AND ', $listWhere) : '';
$total = $status !== '' ? $counts[$status] : $countAll;

$select = "SELECT a.*, c.name AS c_name, c.company AS c_company, c.inn AS c_inn, c.regular_manual,
        (SELECT COUNT(*) FROM applications x WHERE x.client_id = a.client_id) AS c_count,
        (SELECT COUNT(*) FROM applications x WHERE x.client_id = a.client_id AND x.num <= a.num) AS c_seq
    $from $listWhereSql ORDER BY a.num DESC";

// Выгрузка в Excel (CSV, открывается двойным щелчком)
if (isset($_GET['export'])) {
    $st = $pdo->prepare($select);
    $st->execute($listArgs);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="zayavki-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['№', 'Дата', 'Статус', 'Имя', 'Телефон', 'Почта', 'Организация / ИНН', 'Клиент', 'Заявка клиента по счёту',
                   'Позиции', 'Вес, кг', 'Сумма с сайта, ₽', 'Сумма по счёту, ₽', 'Комментарий', 'Заметка менеджера'], ';');
    while ($r = $st->fetch()) {
        [, $kindLabel] = client_kind((int)$r['c_count'], (bool)$r['regular_manual']);
        $items = array_map(fn($i) => $i['label'] . ' — ' . $i['qty_text'] . ($i['cut'] !== '' ? ' (резка: ' . $i['cut'] . ')' : ''), app_items($r['calc_payload']));
        fputcsv($out, [
            $r['num'], date('d.m.Y H:i', strtotime($r['created_at'])), app_status_label($r['status']),
            $r['name'], $r['phone'], $r['email'], $r['org'], $kindLabel, $r['c_seq'],
            implode("\n", $items),
            $r['items_weight_kg'] !== null ? str_replace('.', ',', (string)$r['items_weight_kg']) : '',
            $r['items_total'] ?? '', $r['invoice_sum'] ?? '', $r['comment'], $r['manager_note'] ?? '',
        ], ';');
    }
    exit;
}

$st = $pdo->prepare($select . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
$st->execute($listArgs);
$rows = $st->fetchAll();

$qs = fn(array $over) => 'applications.php?' . http_build_query(array_filter(array_merge(
    ['status' => $status, 'q' => $q, 'period' => $period], $over), fn($v) => $v !== '' && $v !== null && $v !== 1));
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Заявки — админка</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>
<div class="wrap wide">
  <div class="toolbar">
    <h1 style="margin:0">Заявки</h1>
    <form class="filters" method="get" action="applications.php">
      <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= h($status) ?>"><?php endif; ?>
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="Имя, телефон, почта, ИНН, товар, № заявки…">
      <select name="period" onchange="this.form.submit()">
        <?php foreach ($periods as $k => $v): ?>
          <option value="<?= h((string)$k) ?>" <?= $period === (string)$k ? 'selected' : '' ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn secondary small">Найти</button>
      <a href="<?= h($qs(['export' => '1'])) ?>" class="btn secondary small">Выгрузить в Excel</a>
    </form>
  </div>

  <div class="tabs">
    <a href="<?= h($qs(['status' => ''])) ?>" class="<?= $status === '' ? 'on' : '' ?>">Все<b><?= $countAll ?></b></a>
    <?php foreach (APP_STATUSES as $k => $label): ?>
      <a href="<?= h($qs(['status' => $k])) ?>" class="<?= $status === $k ? 'on' : '' ?>"><?= h(mb_strtoupper(mb_substr($label, 0, 1)) . mb_substr($label, 1)) ?><b><?= $counts[$k] ?></b></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$rows): ?>
    <div class="empty"><?= $q !== '' || $period !== '' || $status !== '' ? 'Ничего не нашлось' : 'Заявок пока нет' ?></div>
  <?php else: ?>
  <table class="list">
    <thead>
      <tr><th>№ / дата</th><th>Клиент</th><th>Что заказали</th><th class="num">Вес</th><th class="num">Сумма</th><th>Статус</th></tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r):
        $url = 'application.php?id=' . rawurlencode($r['id']);
        $items = app_items($r['calc_payload']);
        [$kind, $kindLabel] = client_kind((int)$r['c_count'], (bool)$r['regular_manual']);
        $seq = (int)$r['c_seq'];
        $orgLine = ($r['c_company'] ?? '') !== '' ? $r['c_company'] : $r['org'];
      ?>
      <tr class="click <?= $r['viewed_at'] === null ? 'unread' : '' ?>" onclick="if(!event.target.closest('a'))location.href='<?= h($url) ?>'">
        <td style="white-space:nowrap"><a href="<?= h($url) ?>"><b>№ <?= (int)$r['num'] ?></b></a><br><span class="muted"><?= date('d.m.y H:i', strtotime($r['created_at'])) ?></span></td>
        <td>
          <?php if ($r['client_id']): ?><a href="client.php?id=<?= (int)$r['client_id'] ?>"><?= h($r['name']) ?></a><?php else: ?><?= h($r['name']) ?><?php endif; ?>
          <span class="b c-<?= $kind ?>"><?= h($kindLabel) ?><?= $seq > 1 ? ' · ' . $seq . '-я' : '' ?></span><br>
          <span class="muted"><?= h($r['phone']) ?><?= $orgLine !== '' ? ' · ' . h($orgLine) : ($r['email'] !== '' ? ' · ' . h($r['email']) : '') ?></span>
        </td>
        <td style="max-width:420px">
          <?php if ($items): $first = $items[0]; ?>
            <?= h($first['label']) ?> — <?= h($first['qty_short']) ?>
            <?php
              $more = [];
              if (isset($items[1])) $more[] = h($items[1]['label']) . ' — ' . h($items[1]['qty_short']);
              if (array_filter($items, fn($i) => $i['cut'] !== '')) $more[] = '↳ резка';
              if (count($items) > 2) $more[] = 'ещё ' . (count($items) - 2) . ' поз.';
              if ($r['attachment_path'] !== '') $more[] = '📎 ' . h($r['spec_filename'] ?: 'файл');
            ?>
            <?php if ($more): ?><br><span class="muted"><?= implode(' · ', $more) ?></span><?php endif; ?>
          <?php else: ?>
            <span class="muted"><?= $r['comment'] !== '' ? h(mb_strimwidth($r['comment'], 0, 120, '…')) : '—' ?><?= $r['attachment_path'] !== '' ? ' · 📎 ' . h($r['spec_filename'] ?: 'файл') : '' ?></span>
          <?php endif; ?>
        </td>
        <td class="num"><?= h(fmt_weight($r['items_weight_kg'] !== null ? (float)$r['items_weight_kg'] : null)) ?></td>
        <td class="num">
          <?php if ($r['invoice_sum'] !== null): ?>
            <b><?= h(fmt_rub((float)$r['invoice_sum'])) ?></b><br><span class="muted">по счёту</span>
          <?php else: ?>
            <b><?= h(fmt_rub($r['items_total'] !== null ? (float)$r['items_total'] : null)) ?></b>
          <?php endif; ?>
        </td>
        <td><span class="b s-<?= h($r['status']) ?>"><?= h(app_status_label($r['status'])) ?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($total > $perPage): ?>
    <div class="pager">
      <?php if ($page > 1): ?><a class="btn secondary small" href="<?= h($qs(['page' => $page - 1])) ?>">← Новее</a><?php endif; ?>
      <span class="muted">Стр. <?= $page ?> из <?= (int)ceil($total / $perPage) ?></span>
      <?php if ($page * $perPage < $total): ?><a class="btn secondary small" href="<?= h($qs(['page' => $page + 1])) ?>">Старше →</a><?php endif; ?>
    </div>
  <?php endif; ?>
  <p class="muted">Новые (ещё не открытые) заявки подсвечены. Статус, заметка и сумма по счёту — в карточке заявки.</p>
  <?php endif; ?>
</div>
</body>
</html>
