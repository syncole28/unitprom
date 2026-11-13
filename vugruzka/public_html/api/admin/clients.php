<?php
require __DIR__ . '/_auth.php';
admin_require_login();
require_once __DIR__ . '/../lib/crm.php';
$active = 'clients';

$pdo = db_connect();
clients_backfill($pdo);

$tab = (string)($_GET['tab'] ?? '');
$tabs = ['' => 'Все', 'reg' => 'Постоянные', 'rep' => 'Повторные', 'new' => 'Новые', 'sleep' => 'Давно не заказывали'];
if (!isset($tabs[$tab])) $tab = '';
$q = trim((string)($_GET['q'] ?? ''));

$where = '';
$args = [];
if ($q !== '') {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $or = ['c.name LIKE ?', 'c.company LIKE ?', 'c.inn LIKE ?', 'c.email LIKE ?', 'c.phone LIKE ?', 'c.extra_phones LIKE ?', 'c.note LIKE ?'];
    $args = array_fill(0, count($or), $like);
    $digits = preg_replace('/\D+/', '', $q) ?? '';
    if (strlen($digits) >= 5) {
        $d = '%' . (strlen($digits) >= 10 ? substr($digits, -10) : $digits) . '%';
        $or[] = 'c.phone_key LIKE ?'; $or[] = 'c.extra_phone_keys LIKE ?';
        $args[] = $d; $args[] = $d;
    }
    $where = 'WHERE (' . implode(' OR ', $or) . ')';
}

$st = $pdo->prepare("SELECT c.*, COUNT(a.id) AS cnt,
        SUM(CASE WHEN a.status <> 'lost' THEN " . APP_SUM_SQL . " ELSE 0 END) AS total,
        SUM(CASE WHEN a.status <> 'lost' THEN 1 ELSE 0 END) AS cnt_ok,
        MAX(a.created_at) AS last_at, MAX(a.num) AS last_num
    FROM clients c LEFT JOIN applications a ON a.client_id = c.id
    $where GROUP BY c.id ORDER BY last_at DESC, c.id DESC");
$st->execute($args);
$all = $st->fetchAll();

$lastStatus = [];
$nums = array_filter(array_map(fn($r) => (int)$r['last_num'], $all));
if ($nums) {
    foreach ($pdo->query('SELECT num, status FROM applications WHERE num IN (' . implode(',', $nums) . ')') as $r) {
        $lastStatus[(int)$r['num']] = $r['status'];
    }
}

$sleepBefore = time() - CLIENT_SLEEPING_DAYS * 86400;
$counts = array_fill_keys(array_keys($tabs), 0);
$rows = [];
foreach ($all as $r) {
    [$kind, $kindLabel] = client_kind((int)$r['cnt'], (bool)$r['regular_manual']);
    $r['kind'] = $kind;
    $r['kind_label'] = $kindLabel;
    $sleeping = $kind !== 'new' && $r['last_at'] !== null && strtotime($r['last_at']) < $sleepBefore;
    $counts[''] += 1;
    $counts[$kind] += 1;
    if ($sleeping) $counts['sleep'] += 1;
    if ($tab === '' || $tab === $kind || ($tab === 'sleep' && $sleeping)) $rows[] = $r;
}

if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="klienty-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['№ клиента', 'Имя', 'Компания', 'ИНН', 'Телефон', 'Доп. телефоны', 'Почта', 'Метка', 'Заявок', 'Сумма заявок, ₽', 'Последняя заявка', 'Заметка'], ';');
    foreach ($rows as $r) {
        fputcsv($out, [$r['id'], $r['name'], $r['company'], $r['inn'], $r['phone'], $r['extra_phones'], $r['email'], $r['kind_label'],
                       $r['cnt'], (int)$r['total'], $r['last_at'] ? date('d.m.Y', strtotime($r['last_at'])) : '', $r['note'] ?? ''], ';');
    }
    exit;
}

$qs = fn(array $over) => 'clients.php?' . http_build_query(array_filter(array_merge(['tab' => $tab, 'q' => $q], $over), fn($v) => $v !== ''));
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Клиенты — админка</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>
<div class="wrap wide">
  <div class="toolbar">
    <h1 style="margin:0">Клиенты</h1>
    <form class="filters" method="get" action="clients.php">
      <?php if ($tab !== ''): ?><input type="hidden" name="tab" value="<?= h($tab) ?>"><?php endif; ?>
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="Имя, телефон, почта, компания, ИНН…">
      <button type="submit" class="btn secondary small">Найти</button>
      <a href="<?= h($qs(['export' => '1'])) ?>" class="btn secondary small">Выгрузить в Excel</a>
      <a href="client.php?new=1" class="btn small">+ Добавить клиента</a>
    </form>
  </div>

  <div class="tabs">
    <?php foreach ($tabs as $k => $label): ?>
      <a href="<?= h($qs(['tab' => $k])) ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= h($label) ?><b><?= $counts[$k] ?></b></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$rows): ?>
    <div class="empty"><?= $q !== '' || $tab !== '' ? 'Ничего не нашлось' : 'Клиентов пока нет — они появятся из заявок с сайта' ?></div>
  <?php else: ?>
  <table class="list">
    <thead><tr><th>Клиент</th><th>Контакты</th><th class="num">Заявок</th><th class="num">Сумма заявок</th><th class="num">Средний чек</th><th>Последняя заявка</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $url = 'client.php?id=' . (int)$r['id'];
        $orgParts = array_filter([$r['company'], $r['inn'] !== '' ? 'ИНН ' . $r['inn'] : '']);
        $st = $lastStatus[(int)$r['last_num']] ?? null;
    ?>
      <tr class="click" onclick="if(!event.target.closest('a'))location.href='<?= $url ?>'">
        <td><a href="<?= $url ?>"><?= h($r['name'] !== '' ? $r['name'] : 'без имени') ?></a> <span class="b c-<?= $r['kind'] ?>"><?= h($r['kind_label']) ?></span>
          <?php if ($orgParts): ?><br><span class="muted"><?= h(implode(' · ', $orgParts)) ?></span><?php endif; ?></td>
        <td><?= h($r['phone']) ?><?php if ($r['email'] !== ''): ?><br><span class="muted"><?= h($r['email']) ?></span><?php endif; ?></td>
        <td class="num"><?= (int)$r['cnt'] ?></td>
        <td class="num"><?= (int)$r['total'] ? h(fmt_rub((float)$r['total'])) : '—' ?></td>
        <td class="num"><?= (int)$r['cnt_ok'] && (int)$r['total'] ? h(fmt_rub($r['total'] / $r['cnt_ok'])) : '—' ?></td>
        <td style="white-space:nowrap"><?php if ($r['last_at']): ?><?= date('d.m.y', strtotime($r['last_at'])) ?>
          <?php if ($st): ?><span class="b s-<?= h($st) ?>"><?= h(app_status_label($st)) ?></span><?php endif; ?><?php else: ?>—<?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <p class="muted">Клиенты собираются из заявок сами: по телефону, затем по почте, затем по ИНН. Постоянный — от <?= CLIENT_REGULAR_FROM ?> заявок или по отметке в карточке. «Давно не заказывали» — повторные и постоянные без заявок больше <?= CLIENT_SLEEPING_DAYS ?> дней. Сумма — по счёту, если внесена, иначе с сайта; отказы не считаются.</p>
</div>
</body>
</html>
