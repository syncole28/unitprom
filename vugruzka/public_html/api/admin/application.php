<?php
require __DIR__ . '/_auth.php';
admin_require_login();
require_once __DIR__ . '/../lib/crm.php';
$active = 'applications';

$pdo = db_connect();
$id = (string)($_GET['id'] ?? $_POST['id'] ?? '');

$load = function () use ($pdo, $id) {
    $st = $pdo->prepare('SELECT * FROM applications WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch();
};
$app = $load();
if (!$app) {
    http_response_code(404);
    echo 'Заявка не найдена. <a href="applications.php">К списку заявок</a>';
    exit;
}
if (!$app['client_id']) {
    clients_backfill($pdo);
    $app = $load();
}
$self = 'application.php?id=' . rawurlencode($id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $flash = '';

    if ($action === 'status' || $action === 'next') {
        $new = $action === 'next' ? (APP_NEXT_STATUS[$app['status']][0] ?? '') : (string)($_POST['status'] ?? '');
        if (isset(APP_STATUSES[$new]) && $new !== $app['status']) {
            $pdo->prepare('UPDATE applications SET status = ?, processed = ? WHERE id = ?')
                ->execute([$new, $new === 'new' ? 0 : 1, $id]);
            app_event($pdo, $id, 'Статус: ' . app_status_label($app['status']) . ' → ' . app_status_label($new));
            $flash = 'Статус: ' . app_status_label($new);
        }
    } elseif ($action === 'save') {
        $note = trim((string)($_POST['manager_note'] ?? ''));
        $sumRaw = preg_replace('/[^\d]/', '', (string)($_POST['invoice_sum'] ?? '')) ?? '';
        $sum = $sumRaw === '' ? null : (int)$sumRaw;
        $pdo->prepare('UPDATE applications SET manager_note = ?, invoice_sum = ? WHERE id = ?')
            ->execute([$note === '' ? null : $note, $sum, $id]);
        if ((string)$app['invoice_sum'] !== (string)$sum) {
            app_event($pdo, $id, 'Сумма по счёту: ' . ($sum === null ? 'убрана' : fmt_rub($sum)));
        }
        if ((string)$app['manager_note'] !== $note) app_event($pdo, $id, 'Заметка изменена');
        $flash = 'Сохранено';
    } elseif ($action === 'client') {
        // «#12 — Иван…» из подсказки или просто номер клиента
        $cid = preg_match('/^\s*#?(\d+)/', (string)($_POST['client'] ?? ''), $m) ? (int)$m[1] : 0;
        $st = $pdo->prepare('SELECT id, name FROM clients WHERE id = ?');
        $st->execute([$cid]);
        if ($c = $st->fetch()) {
            $pdo->prepare('UPDATE applications SET client_id = ? WHERE id = ?')->execute([$cid, $id]);
            app_event($pdo, $id, 'Клиент заявки: ' . $c['name'] . ' (#' . $cid . ')');
            $flash = 'Заявка перенесена к клиенту ' . $c['name'];
        } else {
            $flash = 'Клиент не найден — выберите из подсказки';
        }
    } elseif ($action === 'delete') {
        $cfg = api_config();
        if ($app['attachment_path'] !== '') {
            $dir = realpath($cfg['uploads_dir'] ?? (__DIR__ . '/../uploads/applications'));
            $f = $dir ? $dir . '/' . basename($app['attachment_path']) : '';
            if ($f !== '' && is_file($f)) @unlink($f);
        }
        $pdo->prepare('DELETE FROM application_events WHERE application_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM applications WHERE id = ?')->execute([$id]);
        // Клиент без заявок и без своих данных (заведён этой заявкой) — тоже убираем
        if ($app['client_id']) {
            $pdo->prepare('DELETE FROM clients WHERE id = ? AND note IS NULL AND regular_manual = 0
                           AND NOT EXISTS (SELECT 1 FROM applications WHERE client_id = ?)')
                ->execute([$app['client_id'], $app['client_id']]);
        }
        header('Location: applications.php');
        exit;
    }
    $_SESSION['flash'] = $flash;
    header('Location: ' . $self);
    exit;
}

// Первое открытие — заявка больше не «непрочитанная»
if ($app['viewed_at'] === null) {
    $pdo->prepare('UPDATE applications SET viewed_at = NOW() WHERE id = ? AND viewed_at IS NULL')->execute([$id]);
    app_event($pdo, $id, 'Открыта в админке');
}
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$items = app_items($app['calc_payload']);
$client = null;
$stats = null;
$prev = [];
if ($app['client_id']) {
    $st = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
    $st->execute([$app['client_id']]);
    $client = $st->fetch() ?: null;
}
if ($client) {
    $stats = client_stats($pdo, (int)$client['id']);
    [$kind, $kindLabel] = client_kind($stats['count'], (bool)$client['regular_manual']);
    $seq = app_seq($pdo, (int)$client['id'], (int)$app['num']);
    $st = $pdo->prepare('SELECT id, num, created_at, status, ' . str_replace('a.', '', APP_SUM_SQL) . ' AS s
                         FROM applications WHERE client_id = ? AND id <> ? ORDER BY num DESC LIMIT 6');
    $st->execute([$client['id'], $id]);
    $prev = $st->fetchAll();
}
$st = $pdo->prepare('SELECT * FROM application_events WHERE application_id = ? ORDER BY id');
$st->execute([$id]);
$events = $st->fetchAll();
$allClients = $pdo->query('SELECT id, name, company, phone FROM clients ORDER BY name LIMIT 3000')->fetchAll();
$next = APP_NEXT_STATUS[$app['status']] ?? null;
$created = strtotime($app['created_at']);
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Заявка № <?= (int)$app['num'] ?> — админка</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>
<div class="wrap wide">
  <?php if ($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
  <div class="toolbar">
    <div>
      <a href="applications.php" class="muted">← Все заявки</a>
      <h1 style="margin:6px 0 0">Заявка № <?= (int)$app['num'] ?> <span class="muted" style="font-size:14px;font-weight:400">· <?= date('d.m.Y H:i', $created) ?> · с сайта</span></h1>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <form method="post" action="<?= h($self) ?>" class="filters">
        <?= csrf_field() ?><input type="hidden" name="action" value="status">
        <span class="muted">Статус:</span>
        <select name="status" onchange="this.form.submit()">
          <?php foreach (APP_STATUSES as $k => $label): ?>
            <option value="<?= $k ?>" <?= $app['status'] === $k ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php if ($next): ?>
        <form method="post" action="<?= h($self) ?>">
          <?= csrf_field() ?><input type="hidden" name="action" value="next">
          <button type="submit" class="btn"><?= h($next[1]) ?></button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="grid2">
    <div>
      <div class="box">
        <h2>Позиции</h2>
        <?php if ($items): [$sum, $kg] = app_items_totals($items); ?>
          <table>
            <thead><tr><th>Товар</th><th>Количество</th><th class="num">Вес</th><th class="num">Сумма</th></tr></thead>
            <tbody>
            <?php foreach ($items as $i): ?>
              <tr>
                <td><?= h($i['label']) ?><?php if ($i['cut'] !== ''): ?><div class="cut">↳ резка: <?= h($i['cut']) ?></div><?php endif; ?></td>
                <td style="white-space:nowrap"><?= h($i['qty_text']) ?></td>
                <td class="num"><?= h(fmt_weight($i['weight_kg'])) ?></td>
                <td class="num"><?= h(fmt_rub($i['price'])) ?></td>
              </tr>
            <?php endforeach; ?>
              <tr>
                <td colspan="2"><b>Итого, предварительно</b></td>
                <td class="num"><b><?= h(fmt_weight($kg)) ?></b></td>
                <td class="num"><b><?= h(fmt_rub($sum)) ?></b></td>
              </tr>
            </tbody>
          </table>
        <?php else: ?>
          <div class="muted">Корзина пустая — клиент написал в комментарии или приложил файл.</div>
        <?php endif; ?>
      </div>

      <div class="box">
        <h2>Комментарий клиента</h2>
        <div style="white-space:pre-wrap"><?= $app['comment'] !== '' ? h($app['comment']) : '<span class="muted">—</span>' ?></div>
        <?php if ($app['attachment_path'] !== ''): ?>
          <div style="margin-top:10px">📎 <a href="file.php?id=<?= h(rawurlencode($id)) ?>"><?= h($app['spec_filename'] ?: 'файл') ?></a></div>
        <?php endif; ?>
      </div>

      <div class="box">
        <h2>Заметка менеджера <span class="muted" style="font-weight:400">видно только в админке</span></h2>
        <form method="post" action="<?= h($self) ?>">
          <?= csrf_field() ?><input type="hidden" name="action" value="save">
          <textarea name="manager_note" class="inp" placeholder="Созвонились, договорились о…"><?= h($app['manager_note'] ?? '') ?></textarea>
          <div class="filters" style="margin-top:10px">
            <span class="muted">Сумма по счёту, ₽:</span>
            <input type="text" name="invoice_sum" inputmode="numeric" style="width:160px"
                   value="<?= $app['invoice_sum'] !== null ? h(number_format((float)$app['invoice_sum'], 0, '', ' ')) : '' ?>"
                   placeholder="<?= $app['items_total'] !== null ? h(number_format((float)$app['items_total'], 0, '', ' ')) : '' ?>">
            <button type="submit" class="btn small">Сохранить</button>
          </div>
        </form>
      </div>
    </div>

    <div>
      <div class="box">
        <?php if ($client): ?>
          <h2>Клиент <span class="b c-<?= $kind ?>"><?= h($kindLabel) ?></span></h2>
          <div class="big"><?= h($client['name'] !== '' ? $client['name'] : $app['name']) ?></div>
          <?php $orgParts = array_filter([$client['company'], $client['inn'] !== '' ? 'ИНН ' . $client['inn'] : '']); ?>
          <div class="muted" style="margin:2px 0 12px"><?= $orgParts ? h(implode(' · ', $orgParts)) : 'организация не указана' ?></div>
          <div class="kv">
            <span class="k">Телефон</span><span><a href="tel:<?= h(preg_replace('/[^\d+]/', '', $app['phone'])) ?>"><?= h($app['phone']) ?></a></span>
            <?php $mail = $app['email'] !== '' ? $app['email'] : $client['email']; ?>
            <span class="k">Почта</span><span><?= $mail !== '' ? '<a href="mailto:' . h($mail) . '">' . h($mail) . '</a>' : '—' ?></span>
            <?php if ($app['org'] !== ''): ?><span class="k">В заявке</span><span><?= h($app['org']) ?></span><?php endif; ?>
            <?php if ($app['name'] !== $client['name']): ?><span class="k">Имя в заявке</span><span><?= h($app['name']) ?></span><?php endif; ?>
            <span class="k">Эта заявка</span><span><?= $seq ?>-я у клиента</span>
            <span class="k">Всего</span><span><?= $stats['count'] ?> · на <?= h(fmt_rub($stats['total'])) ?></span>
            <span class="k">Первая</span><span><?= date('d.m.Y', strtotime($stats['first_at'])) ?></span>
          </div>
          <div style="margin-top:12px"><a class="btn secondary small" href="client.php?id=<?= (int)$client['id'] ?>">Открыть карточку клиента</a></div>
        <?php else: ?>
          <h2>Клиент</h2>
          <div class="big"><?= h($app['name']) ?></div>
          <div class="kv" style="margin-top:10px"><span class="k">Телефон</span><span><?= h($app['phone']) ?></span>
            <span class="k">Почта</span><span><?= h($app['email']) ?: '—' ?></span></div>
        <?php endif; ?>
        <details style="margin-top:12px">
          <summary class="muted" style="cursor:pointer">Это другой клиент…</summary>
          <form method="post" action="<?= h($self) ?>" class="filters" style="margin-top:8px">
            <?= csrf_field() ?><input type="hidden" name="action" value="client">
            <input name="client" list="clients-list" placeholder="Начните вводить имя или телефон" style="width:230px">
            <button type="submit" class="btn secondary small">Перенести</button>
          </form>
          <datalist id="clients-list">
            <?php foreach ($allClients as $c): ?>
              <option value="#<?= (int)$c['id'] ?> — <?= h($c['name']) ?><?= $c['company'] !== '' ? ', ' . h($c['company']) : '' ?> — <?= h($c['phone']) ?>">
            <?php endforeach; ?>
          </datalist>
          <div class="muted" style="margin-top:6px">Если клиента ещё нет — заведите его во вкладке «Клиенты».</div>
        </details>
      </div>

      <?php if ($prev): ?>
      <div class="box">
        <h2>Другие заявки клиента</h2>
        <div class="hist">
          <?php foreach (array_slice($prev, 0, 5) as $p): ?>
            <div><a href="application.php?id=<?= h(rawurlencode($p['id'])) ?>">№ <?= (int)$p['num'] ?></a>
              · <?= date('d.m.y', strtotime($p['created_at'])) ?><?= (int)$p['s'] ? ' · ' . h(fmt_rub((float)$p['s'])) : '' ?>
              <span class="b s-<?= h($p['status']) ?>"><?= h(app_status_label($p['status'])) ?></span></div>
          <?php endforeach; ?>
          <?php if (count($prev) > 5): ?><div><a href="client.php?id=<?= (int)$client['id'] ?>" class="muted">все заявки — в карточке клиента</a></div><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="box">
        <h2>История</h2>
        <div class="hist muted">
          <?php if (!$events): ?><div><?= date('d.m H:i', $created) ?> — заявка с сайта</div><?php endif; ?>
          <?php foreach ($events as $e): ?>
            <div><?= date('d.m H:i', strtotime($e['created_at'])) ?> — <?= h($e['text']) ?></div>
          <?php endforeach; ?>
        </div>
      </div>

      <form method="post" action="<?= h($self) ?>" onsubmit="return confirm('Удалить заявку № <?= (int)$app['num'] ?> вместе с файлом? Это нельзя отменить.')">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete">
        <button type="submit" class="btn danger small">Удалить заявку (спам, тест)</button>
      </form>
    </div>
  </div>
</div>
</body>
</html>
