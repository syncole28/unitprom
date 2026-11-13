<?php
require __DIR__ . '/_auth.php';
admin_require_login();
require_once __DIR__ . '/../lib/crm.php';
$active = 'clients';

$pdo = db_connect();
$isNew = isset($_GET['new']);
$id = (int)($_GET['id'] ?? 0);

$load = function (int $id) use ($pdo) {
    $st = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
};
$c = $isNew ? ['id' => 0, 'name' => '', 'company' => '', 'inn' => '', 'phone' => '', 'extra_phones' => '', 'email' => '', 'note' => '', 'regular_manual' => 0] : $load($id);
if (!$c) {
    http_response_code(404);
    echo 'Клиент не найден. <a href="clients.php">К списку клиентов</a>';
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $f = [];
        foreach (['name' => 255, 'company' => 255, 'phone' => 64, 'extra_phones' => 255, 'email' => 255] as $k => $max) {
            $f[$k] = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string)($_POST[$k] ?? '')) ?? ''), 0, $max);
        }
        $f['email'] = mb_strtolower($f['email']);
        $inn = preg_replace('/\D+/', '', (string)($_POST['inn'] ?? '')) ?? '';
        $note = trim((string)($_POST['note'] ?? ''));
        $regular = !empty($_POST['regular_manual']) ? 1 : 0;
        if ($inn !== '' && strlen($inn) !== 10 && strlen($inn) !== 12) {
            $error = 'ИНН — 10 цифр у организации или 12 у ИП';
        } elseif ($f['name'] === '' && $f['company'] === '') {
            $error = 'Укажите имя или компанию';
        } else {
            $vals = [$f['name'], $f['company'], $inn, $f['phone'], phone_key($f['phone']), $f['extra_phones'], phone_keys_list($f['extra_phones']),
                     $f['email'], $note === '' ? null : $note, $regular];
            if ($isNew) {
                $pdo->prepare('INSERT INTO clients (name, company, inn, phone, phone_key, extra_phones, extra_phone_keys, email, note, regular_manual)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute($vals);
                $id = (int)$pdo->lastInsertId();
            } else {
                $vals[] = $id;
                $pdo->prepare('UPDATE clients SET name = ?, company = ?, inn = ?, phone = ?, phone_key = ?, extra_phones = ?, extra_phone_keys = ?,
                               email = ?, note = ?, regular_manual = ? WHERE id = ?')->execute($vals);
            }
            $_SESSION['flash'] = 'Сохранено';
            header('Location: client.php?id=' . $id);
            exit;
        }
        $c = array_merge($c, $f, ['inn' => $inn, 'note' => $note, 'regular_manual' => $regular]);
    } elseif ($action === 'merge' && !$isNew) {
        // Второй клиент вливается в этого: заявки, пустые поля, телефоны, заметка; сам второй удаляется
        $otherId = preg_match('/^\s*#?(\d+)/', (string)($_POST['other'] ?? ''), $m) ? (int)$m[1] : 0;
        $o = $otherId !== $id ? $load($otherId) : null;
        if (!$o) {
            $error = 'Выберите другого клиента из подсказки';
        } else {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE applications SET client_id = ? WHERE client_id = ?')->execute([$id, $otherId]);
            $phones = array_filter([$c['extra_phones'], $o['phone'] !== $c['phone'] ? $o['phone'] : '', $o['extra_phones']]);
            $phones = implode(', ', array_unique(array_filter(array_map('trim', explode(',', implode(',', $phones))), fn($p) => $p !== '')));
            $note = trim(implode("\n", array_filter([(string)$c['note'], (string)$o['note']])));
            $pdo->prepare('UPDATE clients SET
                    name = IF(name = \'\', ?, name), company = IF(company = \'\', ?, company), inn = IF(inn = \'\', ?, inn),
                    email = IF(email = \'\', ?, email), extra_phones = ?, extra_phone_keys = ?, note = ?,
                    regular_manual = GREATEST(regular_manual, ?) WHERE id = ?')
                ->execute([$o['name'], $o['company'], $o['inn'], $o['email'], mb_substr($phones, 0, 255), phone_keys_list($phones),
                           $note === '' ? null : $note, (int)$o['regular_manual'], $id]);
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$otherId]);
            $pdo->commit();
            $_SESSION['flash'] = 'Клиент «' . ($o['name'] ?: $o['company']) . '» объединён с этим';
            header('Location: client.php?id=' . $id);
            exit;
        }
    } elseif ($action === 'delete' && !$isNew) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM applications WHERE client_id = ?');
        $st->execute([$id]);
        if ((int)$st->fetchColumn() > 0) {
            $error = 'У клиента есть заявки — удалить можно только клиента без заявок (или объедините его с другим)';
        } else {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
            header('Location: clients.php');
            exit;
        }
    }
}
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$apps = [];
$stats = ['count' => 0, 'total' => 0, 'first_at' => null, 'last_at' => null];
$top = [];
$others = [];
if (!$isNew) {
    $st = $pdo->prepare('SELECT a.*, ' . APP_SUM_SQL . ' AS s FROM applications a WHERE a.client_id = ? ORDER BY a.num DESC');
    $st->execute([$id]);
    $apps = $st->fetchAll();
    $stats = client_stats($pdo, $id);
    $top = client_top_products($pdo, $id);
    $st = $pdo->prepare('SELECT id, name, company, phone FROM clients WHERE id <> ? ORDER BY name LIMIT 3000');
    $st->execute([$id]);
    $others = $st->fetchAll();
}
[$kind, $kindLabel] = client_kind($stats['count'], (bool)$c['regular_manual']);
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $isNew ? 'Новый клиент' : h($c['name'] ?: $c['company']) ?> — админка</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>
<div class="wrap wide">
  <?php if ($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
  <a href="clients.php" class="muted">← Все клиенты</a>
  <h1 style="margin:6px 0 18px"><?= $isNew ? 'Новый клиент' : h($c['name'] ?: $c['company']) ?>
    <?php if (!$isNew): ?><span class="b c-<?= $kind ?>" style="vertical-align:middle"><?= h($kindLabel) ?></span>
    <span class="muted" style="font-size:14px;font-weight:400">· клиент #<?= $id ?></span><?php endif; ?></h1>

  <div class="grid2" style="grid-template-columns:minmax(0,1fr) minmax(0,1fr)">
    <div>
      <div class="box">
        <h2>Данные</h2>
        <form method="post" action="client.php?<?= $isNew ? 'new=1' : 'id=' . $id ?>">
          <?= csrf_field() ?><input type="hidden" name="action" value="save">
          <div class="form-grid">
            <div class="field-group"><label>Имя</label><input name="name" value="<?= h($c['name']) ?>"></div>
            <div class="field-group"><label>Компания</label><input name="company" value="<?= h($c['company']) ?>"></div>
            <div class="field-group"><label>ИНН</label><input name="inn" value="<?= h($c['inn']) ?>" inputmode="numeric"></div>
            <div class="field-group"><label>Почта</label><input name="email" value="<?= h($c['email']) ?>"></div>
            <div class="field-group"><label>Телефон</label><input name="phone" value="<?= h($c['phone']) ?>"></div>
            <div class="field-group"><label>Доп. телефоны</label><input name="extra_phones" value="<?= h($c['extra_phones']) ?>" placeholder="через запятую">
              <div class="hint">Заявки с этих номеров тоже попадут к клиенту</div></div>
            <div class="field-group full"><label>Заметка</label><textarea name="note" rows="4" placeholder="Как платит, куда возим, с кем говорить…"><?= h($c['note'] ?? '') ?></textarea></div>
            <div class="field-group full" style="margin-bottom:8px">
              <label style="display:flex;gap:8px;align-items:center;text-transform:none;letter-spacing:0;font-size:13px;color:#E8E9EC">
                <input type="checkbox" name="regular_manual" value="1" style="width:auto" <?= $c['regular_manual'] ? 'checked' : '' ?>>
                Постоянный клиент (отметить вручную; от <?= CLIENT_REGULAR_FROM ?> заявок — автоматически)
              </label>
            </div>
          </div>
          <button type="submit" class="btn"><?= $isNew ? 'Добавить клиента' : 'Сохранить' ?></button>
        </form>
      </div>

      <?php if (!$isNew): ?>
      <div class="box">
        <h2>Объединить с другим клиентом</h2>
        <div class="muted" style="margin-bottom:10px">Если один и тот же клиент писал с разных номеров или почт. Заявки, телефоны и заметка второго клиента перейдут сюда, второй клиент удалится.</div>
        <form method="post" action="client.php?id=<?= $id ?>" class="filters" onsubmit="return confirm('Объединить выбранного клиента с этим? Второй клиент будет удалён.')">
          <?= csrf_field() ?><input type="hidden" name="action" value="merge">
          <input name="other" list="others-list" placeholder="Начните вводить имя или телефон" style="width:300px">
          <button type="submit" class="btn secondary small">Объединить</button>
        </form>
        <datalist id="others-list">
          <?php foreach ($others as $o): ?>
            <option value="#<?= (int)$o['id'] ?> — <?= h($o['name']) ?><?= $o['company'] !== '' ? ', ' . h($o['company']) : '' ?> — <?= h($o['phone']) ?>">
          <?php endforeach; ?>
        </datalist>
      </div>
      <?php if (!$apps): ?>
        <form method="post" action="client.php?id=<?= $id ?>" onsubmit="return confirm('Удалить клиента?')">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete">
          <button type="submit" class="btn danger small">Удалить клиента</button>
        </form>
      <?php endif; ?>
      <?php endif; ?>
    </div>

    <?php if (!$isNew): ?>
    <div>
      <div class="box">
        <h2>Заявки (<?= $stats['count'] ?>) <span class="muted" style="font-weight:400">· на <?= h(fmt_rub($stats['total'])) ?><?= $stats['first_at'] ? ' · первая ' . date('d.m.Y', strtotime($stats['first_at'])) : '' ?></span></h2>
        <?php if (!$apps): ?>
          <div class="muted">Заявок пока нет</div>
        <?php else: ?>
        <table>
          <tbody>
          <?php foreach ($apps as $a):
              $items = app_items($a['calc_payload']);
              $what = $items ? $items[0]['label'] . (count($items) > 1 ? ' и ещё ' . (count($items) - 1) : '') : ($a['comment'] !== '' ? mb_strimwidth($a['comment'], 0, 60, '…') : ($a['attachment_path'] !== '' ? '📎 ' . ($a['spec_filename'] ?: 'файл') : '—'));
          ?>
            <tr>
              <td style="white-space:nowrap"><a href="application.php?id=<?= h(rawurlencode($a['id'])) ?>">№ <?= (int)$a['num'] ?></a> · <?= date('d.m.y', strtotime($a['created_at'])) ?></td>
              <td><?= h($what) ?></td>
              <td class="num"><?= $a['invoice_sum'] === null && $a['items_total'] === null ? '—' : h(fmt_rub((float)$a['s'])) ?><?= $a['invoice_sum'] !== null ? '<br><span class="muted">по счёту</span>' : '' ?></td>
              <td><span class="b s-<?= h($a['status']) ?>"><?= h(app_status_label($a['status'])) ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
        <?php if ($top): ?>
          <p class="muted" style="margin:12px 0 0">Чаще всего берёт: <?= h(implode(', ', array_map(fn($w, $n) => $w . ($n > 1 ? " ($n)" : ''), array_keys($top), $top))) ?>.</p>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
