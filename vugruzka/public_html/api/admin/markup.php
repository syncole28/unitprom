<?php
require __DIR__ . '/_auth.php';
admin_require_login();
require_once __DIR__ . '/../lib/adjustments.php';
$active = 'markup';

$pdo = db_connect();
$error = '';
$success = '';

/** Число из поля формы: '' → null, «3,5» → 3.5, не число → false. */
function adj_form_num($v) {
    $s = str_replace([' ', ','], ['', '.'], trim((string)$v));
    if ($s === '') return null;
    return is_numeric($s) ? (float)$s : false;
}

$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_cats') {
    csrf_check();
    $markups = $_POST['markup'] ?? [];
    $pcts = $_POST['weight_pct'] ?? [];
    $known = array_column($pdo->query('SELECT slug, title FROM catalog_categories')->fetchAll(), 'title', 'slug');
    $updates = [];
    foreach ($known as $slug => $title) {
        if (!array_key_exists($slug, $markups) && !array_key_exists($slug, $pcts)) continue;
        $m = adj_form_num($markups[$slug] ?? '');
        $w = adj_form_num($pcts[$slug] ?? '');
        if ($m === false || $w === false) { $error = "«{$title}»: укажите число"; break; }
        if ($w !== null && ($w < -50 || $w > 50)) { $error = "«{$title}»: поправка веса должна быть от −50 до 50 %"; break; }
        $updates[$slug] = [(int)round($m ?? 0), $w];
    }
    if (!$error) {
        $stmt = $pdo->prepare('UPDATE catalog_categories SET markup_per_ton = ?, weight_pct = ? WHERE slug = ?');
        $pdo->beginTransaction();
        foreach ($updates as $slug => [$m, $w]) $stmt->execute([$m, $w, $slug]);
        $pdo->commit();
        $success = 'Наценка и поправка веса сохранены';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_rules') {
    csrf_check();
    $known = array_column($pdo->query('SELECT slug, title FROM catalog_categories')->fetchAll(), 'title', 'slug');
    $rows = [];
    $cats = $_POST['rule_cat'] ?? [];
    foreach ($cats as $i => $slug) {
        $slug = trim((string)$slug);
        if ($slug === '') continue;
        $n = count($rows) + 1;
        if (!isset($known[$slug])) { $error = "Правило {$n}: такой категории нет"; break; }
        $from = adj_form_num($_POST['rule_from'][$i] ?? '');
        $to = adj_form_num($_POST['rule_to'][$i] ?? '');
        if ($from === false || $to === false) { $error = "Правило {$n}: размер — числом"; break; }
        if ($from !== null && $to !== null && $from > $to) { $error = "Правило {$n}: «от» больше «до»"; break; }
        $kind = $_POST['rule_kind'][$i] ?? 'fixed';
        $a = adj_form_num($_POST['rule_len'][$i] ?? '');
        $b = adj_form_num($_POST['rule_len2'][$i] ?? '');
        if ($kind === 'nemer') {
            if (!$a || !$b || $a <= 0 || $b <= 0) { $error = "Правило {$n}: укажите, от скольких до скольких метров немерная"; break; }
            $len = null;
            $nemer = adj_norm_range($a . '-' . $b);
        } else {
            if (!$a || $a <= 0 || $a > 100) { $error = "Правило {$n}: укажите длину в метрах"; break; }
            $len = $a;
            $nemer = '';
        }
        $rows[] = [$slug, $from, $to, $len, $nemer];
    }
    $nemerCalc = adj_form_num($_POST['nemer_calc_m'] ?? '');
    if (!$error && ($nemerCalc === false || $nemerCalc === null || $nemerCalc <= 0 || $nemerCalc > 100)) {
        $error = 'Укажите, по какой длине считать 1 шт немерной позиции';
    }
    if (!$error) {
        $pdo->prepare('INSERT INTO admin_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')
            ->execute(['nemer_calc_m', (string)$nemerCalc]);
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM length_rules');
        $stmt = $pdo->prepare('INSERT INTO length_rules (category_slug, size_from, size_to, length_m, length_nemer) VALUES (?, ?, ?, ?, ?)');
        foreach ($rows as $r) $stmt->execute($r);
        $pdo->commit();
        $success = 'Правила длины сохранены и уже работают на сайте';
    }
}

$categories = adj_tree($pdo->query(
    'SELECT c.slug, c.title, c.parent_slug, c.markup_per_ton, c.weight_pct, c.sort_order,
            (SELECT COUNT(*) FROM catalog_products p WHERE p.category_slug = c.slug) AS n
     FROM catalog_categories c ORDER BY c.sort_order, c.title'
)->fetchAll());
$titleBySlug = array_column($categories, 'title', 'slug');

// Сколько товаров получает длину откуда — и какое правило сколько товаров покрывает
$parents = adj_parents($pdo);
$rulesBySlug = adj_load_rules($pdo);
$stats = ['label' => 0, 'manual' => 0, 'rule' => 0, '' => 0];
$perRule = [];
foreach ($pdo->query('SELECT id, label, category_slug, length_m, length_nemer FROM catalog_products')->fetchAll() as $p) {
    $len = adj_resolve_length($p, $rulesBySlug, $parents);
    $stats[$len['source']]++;
    if ($len['rule']) $perRule[$len['rule']['id']] = ($perRule[$len['rule']['id']] ?? 0) + 1;
}
// Правила в том порядке, в каком их вводили (id растёт с порядком строк)
$rules = $pdo->query('SELECT * FROM length_rules ORDER BY id')->fetchAll();

// После ошибки показываем то, что ввели, а не сохранённое
if ($error && $action === 'save_cats') {
    foreach ($categories as &$c) {
        if (isset($_POST['markup'][$c['slug']])) $c['markup_per_ton'] = $_POST['markup'][$c['slug']];
        if (isset($_POST['weight_pct'][$c['slug']])) $c['weight_pct'] = $_POST['weight_pct'][$c['slug']];
    }
    unset($c);
}
if ($error && $action === 'save_rules') {
    $rules = [];
    foreach ($_POST['rule_cat'] ?? [] as $i => $slug) {
        if (trim((string)$slug) === '') continue;
        $nemer = ($_POST['rule_kind'][$i] ?? '') === 'nemer';
        $rules[] = [
            'id' => 0, 'category_slug' => $slug,
            'size_from' => $_POST['rule_from'][$i] ?? '', 'size_to' => $_POST['rule_to'][$i] ?? '',
            'length_m' => $nemer ? null : ($_POST['rule_len'][$i] ?? ''),
            'length_nemer' => $nemer ? (($_POST['rule_len'][$i] ?? '') . '–' . ($_POST['rule_len2'][$i] ?? '')) : '',
        ];
    }
}

$num = fn($v) => ($v === null || $v === '') ? '' : (is_numeric($v) ? rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.') : (string)$v);

function cat_options(array $categories, string $selected): string {
    $html = '<option value="">— категория —</option>';
    foreach ($categories as $c) {
        $html .= '<option value="' . h($c['slug']) . '"' . ($c['slug'] === $selected ? ' selected' : '') . '>'
            . str_repeat('— ', (int)$c['depth']) . h($c['title']) . '</option>';
    }
    return $html;
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Ручные корректировки — админка</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .adj h2 { font-size: 17px; color: #fff; margin: 34px 0 6px; }
    .adj .lead { color: #A7ACB8; font-size: 13px; line-height: 1.5; margin: 0 0 14px; max-width: 760px; }
    .adj table input[type=text], .adj table select, .adj .bulk input, .adj .bulk select {
      padding: 7px 9px; background: #0F1115; border: 1px solid rgba(255,255,255,.15); border-radius: 6px;
      color: #fff; font-size: 13px; font-family: inherit;
    }
    .adj table input[type=text] { width: 90px; text-align: right; }
    .adj table input.f-pct { width: 150px; }
    .adj table input.changed { border-color: #F2611B; background: rgba(242,97,27,.08); }
    .adj input[type=checkbox] { width: 16px; height: 16px; margin: 0; accent-color: #F2611B; vertical-align: middle; cursor: pointer; }
    .adj td.cat { white-space: nowrap; }
    .adj td.cat label { cursor: pointer; }
    .adj tr.sub td.cat { padding-left: 34px; color: #C9CCD3; }
    .adj tr.top td.cat { font-weight: 600; color: #fff; }
    .adj .muted { color: #6b7080; font-size: 12px; }
    .adj .unit { color: #6b7080; font-size: 12px; margin-left: 4px; }
    .adj .bulk { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 12px 0 0; padding: 12px 14px;
      background: #15171C; border: 1px solid rgba(255,255,255,.08); border-radius: 10px; font-size: 13px; color: #A7ACB8; }
    .adj .bulk input { width: 110px; }
    .adj .actions-row { display: flex; gap: 10px; align-items: center; margin-top: 14px; }
    .adj .ok { border: 1px solid #2d6a4f; background: rgba(52,199,89,.08); color: #4ade80; border-radius: 6px; padding: 10px 14px; margin-bottom: 16px; font-size: 13px; }
    .adj table.rules td { vertical-align: middle; }
    .adj table.rules select.r-cat { max-width: 260px; }
    .adj table.rules .len { white-space: nowrap; }
    .adj table.rules .len input { width: 64px; }
    .adj table.rules .len .dash { color: #6b7080; margin: 0 4px; }
    .adj .stats { display: flex; flex-wrap: wrap; gap: 10px; margin: 0 0 14px; }
    .adj .stats div { background: #15171C; border: 1px solid rgba(255,255,255,.08); border-radius: 8px; padding: 8px 12px; font-size: 13px; }
    .adj .stats b { color: #fff; margin-right: 4px; }
    .adj .stats .warn b { color: #F2611B; }
    .adj .rm { background: none; border: 0; color: #e08585; cursor: pointer; font-size: 18px; line-height: 1; padding: 2px 6px; }
  </style>
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>
<div class="wrap adj" style="max-width:980px">
  <h1>Ручные корректировки</h1>

  <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="ok"><?= h($success) ?></div><?php endif; ?>

  <h2 style="margin-top:8px">Наценка и поправка веса по категориям</h2>
  <p class="lead">
    <b>Наценка</b> прибавляется к закупке каждого товара категории, в рублях за тонну.
    <b>Поправка веса</b> увеличивает вес на сайте на столько процентов: растут вес в калькуляторе
    и цены за штуку, цена за тонну не меняется. В базе остаётся теоретический вес.
    Пустая поправка у подкатегории — берётся как у родительской категории.
    Наценка у каждой подкатегории своя; галочка у родительской категории отмечает и её подкатегории.
  </p>

  <form method="post" id="cats-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_cats">
    <table>
      <thead>
        <tr>
          <th style="width:34px"><input type="checkbox" id="check-all" title="Отметить все"></th>
          <th>Категория</th>
          <th class="num">Товаров</th>
          <th class="num">Наценка, ₽/т</th>
          <th class="num">Поправка веса, %</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($categories as $c): $id = 'cat-' . md5($c['slug']); ?>
        <tr class="<?= $c['depth'] ? 'sub' : 'top' ?>" data-slug="<?= h($c['slug']) ?>" data-parent="<?= h((string)$c['parent_slug']) ?>">
          <td><input type="checkbox" class="pick" id="<?= $id ?>"></td>
          <td class="cat"><label for="<?= $id ?>"><?= $c['depth'] ? '— ' : '' ?><?= h($c['title']) ?></label></td>
          <td class="num muted"><?= (int)$c['n'] ?: '' ?></td>
          <td class="num"><input type="text" inputmode="numeric" class="f-markup" name="markup[<?= h($c['slug']) ?>]" value="<?= h($num($c['markup_per_ton'])) ?>" data-orig="<?= h($num($c['markup_per_ton'])) ?>"></td>
          <td class="num"><input type="text" inputmode="decimal" class="f-pct" name="weight_pct[<?= h($c['slug']) ?>]" value="<?= h($num($c['weight_pct'])) ?>" data-orig="<?= h($num($c['weight_pct'])) ?>"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <div class="bulk">
      Отмеченным категориям:
      <select id="bulk-mode">
        <option value="set">задать наценку</option>
        <option value="add">прибавить к наценке</option>
        <option value="pct">задать поправку веса</option>
      </select>
      <input type="text" id="bulk-value" inputmode="decimal" placeholder="300">
      <span class="unit" id="bulk-unit">₽/т</span>
      <button type="button" class="btn secondary small" id="bulk-apply">Подставить</button>
      <span class="muted">Значения появятся в таблице, затем нажмите «Сохранить».</span>
    </div>

    <div class="actions-row">
      <button type="submit" class="btn">Сохранить</button>
      <span class="muted" id="changed-note"></span>
    </div>
  </form>

  <h2>Длина</h2>
  <p class="lead">
    Длина нужна для цены за штуку. Она подставляется в конец названия товара на сайте («…, 12 м»).
    Откуда берётся, по старшинству: <b>из названия</b> (мерность прайса: «12м», «немер», «бухта», у листов размеры),
    затем <b>вручную у товара</b>, затем <b>правило</b> ниже. Если длины нет нигде, цену за штуку сайт не показывает.
  </p>
  <div class="stats">
    <div><b><?= $stats['label'] ?></b>из названия</div>
    <div><b><?= $stats['manual'] ?></b>вручную</div>
    <div><b><?= $stats['rule'] ?></b>по правилам</div>
    <div class="<?= $stats[''] ? 'warn' : '' ?>"><b><?= $stats[''] ?></b>не задана<?php if ($stats['']): ?> · <a href="products.php?length=none">показать</a><?php endif; ?></div>
  </div>
  <p class="lead">
    Правило работает для категории и её подкатегорий. Размер — первое число в названии товара
    (у «Труба э/с 720х8» это 720), границы включительно. Если подходят несколько правил, сработает
    правило подкатегории, а из правил одной категории — сначала с размером, потом без.
  </p>

  <form method="post" id="rules-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_rules">
    <div class="bulk" style="margin:0 0 14px">
      Немерные на сайте продаются штуками и тоннами. 1 шт считаем по длине
      <input type="text" inputmode="decimal" name="nemer_calc_m" style="width:70px;text-align:right"
             value="<?= h($error && $action === 'save_rules' ? (string)($_POST['nemer_calc_m'] ?? '') : adj_num(adj_nemer_calc_setting($pdo))) ?>">
      <span class="unit">м</span>
      <span class="muted">(в пределах диапазона позиции: у «немерная 4–8 м» будет 8 м)</span>
    </div>
    <table class="rules">
      <thead>
        <tr>
          <th>Категория</th>
          <th class="num">Размер от</th>
          <th class="num">до</th>
          <th>Длина</th>
          <th class="num">Товаров</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="rules-body">
      <?php foreach ($rules as $r):
          $isNemer = trim((string)$r['length_nemer']) !== '';
          $range = $isNemer ? preg_split('/[-–—]/u', (string)$r['length_nemer']) : [];
      ?>
        <tr>
          <td><select name="rule_cat[]" class="r-cat"><?= cat_options($categories, (string)$r['category_slug']) ?></select></td>
          <td class="num"><input type="text" inputmode="decimal" name="rule_from[]" value="<?= h($num($r['size_from'])) ?>" placeholder="—"></td>
          <td class="num"><input type="text" inputmode="decimal" name="rule_to[]" value="<?= h($num($r['size_to'])) ?>" placeholder="—"></td>
          <td class="len">
            <select name="rule_kind[]" class="r-kind">
              <option value="fixed" <?= $isNemer ? '' : 'selected' ?>>мерная</option>
              <option value="nemer" <?= $isNemer ? 'selected' : '' ?>>немерная</option>
            </select>
            <span class="r-from-lbl"<?= $isNemer ? '' : ' hidden' ?>>от</span>
            <input type="text" inputmode="decimal" name="rule_len[]" value="<?= h($isNemer ? trim($range[0] ?? '') : $num($r['length_m'])) ?>">
            <span class="r-to"<?= $isNemer ? '' : ' hidden' ?>><span class="dash">до</span><input type="text" inputmode="decimal" name="rule_len2[]" value="<?= h($isNemer ? trim($range[1] ?? '') : '') ?>"></span>
            <span class="unit">м</span>
          </td>
          <td class="num muted"><?= $r['id'] ? (int)($perRule[$r['id']] ?? 0) : '' ?></td>
          <td><button type="button" class="rm" title="Удалить правило">×</button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <template id="rule-tpl">
      <tr>
        <td><select name="rule_cat[]" class="r-cat"><?= cat_options($categories, '') ?></select></td>
        <td class="num"><input type="text" inputmode="decimal" name="rule_from[]" placeholder="—"></td>
        <td class="num"><input type="text" inputmode="decimal" name="rule_to[]" placeholder="—"></td>
        <td class="len">
          <select name="rule_kind[]" class="r-kind">
            <option value="fixed">мерная</option>
            <option value="nemer">немерная</option>
          </select>
          <span class="r-from-lbl" hidden>от</span>
          <input type="text" inputmode="decimal" name="rule_len[]" value="12">
          <span class="r-to" hidden><span class="dash">до</span><input type="text" inputmode="decimal" name="rule_len2[]" value="12"></span>
          <span class="unit">м</span>
        </td>
        <td class="num muted"></td>
        <td><button type="button" class="rm" title="Удалить правило">×</button></td>
      </tr>
    </template>
    <div class="actions-row">
      <button type="button" class="btn secondary" id="add-rule">+ Добавить правило</button>
      <button type="submit" class="btn">Сохранить правила</button>
    </div>
  </form>
</div>

<script>
(function () {
  // ---- Наценка и поправка веса ----
  var form = document.getElementById('cats-form');
  var rows = Array.prototype.slice.call(form.querySelectorAll('tbody tr'));
  var bySlug = {};
  rows.forEach(function (tr) { bySlug[tr.dataset.slug] = tr; });

  function num(s) { s = String(s).replace(/\s/g, '').replace(',', '.'); return s === '' ? null : parseFloat(s); }
  function fmt(v) { return String(Math.round(v * 100) / 100).replace('.', ','); }

  // Подсказка в пустой поправке подкатегории: сколько возьмётся от родителя
  function refreshPlaceholders() {
    rows.forEach(function (tr) {
      var input = tr.querySelector('.f-pct');
      var p = tr.dataset.parent, inherited = null, from = '';
      while (p && bySlug[p]) {
        var v = num(bySlug[p].querySelector('.f-pct').value);
        if (v !== null && !isNaN(v)) { inherited = v; from = bySlug[p].querySelector('.cat label').textContent.trim(); break; }
        p = bySlug[p].dataset.parent;
      }
      input.placeholder = inherited !== null ? 'как у родителя: ' + (inherited > 0 ? '+' : '') + fmt(inherited) : '0';
      input.title = inherited !== null ? 'Пусто — берётся поправка «' + from + '»' : 'Пусто — без поправки';
    });
  }

  function markChanged() {
    var n = 0;
    form.querySelectorAll('.f-markup, .f-pct').forEach(function (inp) {
      var ch = inp.value.trim() !== inp.dataset.orig;
      inp.classList.toggle('changed', ch);
      if (ch) n++;
    });
    document.getElementById('changed-note').textContent = n ? 'Изменено полей: ' + n + ' — не забудьте сохранить' : '';
  }

  form.addEventListener('input', function (e) {
    if (e.target.classList.contains('f-pct')) refreshPlaceholders();
    markChanged();
  });

  // Галочка у родительской категории отмечает и её подкатегории
  form.addEventListener('change', function (e) {
    if (!e.target.classList.contains('pick')) return;
    var slug = e.target.closest('tr').dataset.slug;
    rows.forEach(function (tr) {
      if (tr.dataset.parent === slug) tr.querySelector('.pick').checked = e.target.checked;
    });
  });

  document.getElementById('check-all').addEventListener('change', function () {
    var on = this.checked;
    form.querySelectorAll('.pick').forEach(function (c) { c.checked = on; });
  });

  var mode = document.getElementById('bulk-mode');
  var unit = document.getElementById('bulk-unit');
  var valueInput = document.getElementById('bulk-value');
  mode.addEventListener('change', function () {
    unit.textContent = mode.value === 'pct' ? '%' : '₽/т';
    valueInput.placeholder = mode.value === 'pct' ? '3' : '300';
  });

  document.getElementById('bulk-apply').addEventListener('click', function () {
    var v = num(valueInput.value);
    if (v === null || isNaN(v)) { valueInput.focus(); return; }
    var picked = rows.filter(function (tr) { return tr.querySelector('.pick').checked; });
    if (!picked.length) { alert('Отметьте категории галочками'); return; }
    picked.forEach(function (tr) {
      if (mode.value === 'pct') {
        tr.querySelector('.f-pct').value = fmt(v);
      } else {
        var inp = tr.querySelector('.f-markup');
        var cur = num(inp.value) || 0;
        inp.value = String(Math.round(mode.value === 'add' ? cur + v : v));
      }
    });
    refreshPlaceholders();
    markChanged();
  });

  refreshPlaceholders();

  // ---- Правила длины ----
  var body = document.getElementById('rules-body');
  function syncKind(tr) {
    var nemer = tr.querySelector('.r-kind').value === 'nemer';
    tr.querySelector('.r-to').hidden = !nemer;
    tr.querySelector('.r-from-lbl').hidden = !nemer;
  }
  body.addEventListener('change', function (e) {
    if (e.target.classList.contains('r-kind')) {
      var tr = e.target.closest('tr');
      var a = tr.querySelector('[name="rule_len[]"]'), b = tr.querySelector('[name="rule_len2[]"]');
      if (e.target.value === 'nemer') { a.value = '9'; b.value = '12'; } else { a.value = '12'; }
      syncKind(tr);
    }
  });
  body.addEventListener('click', function (e) {
    if (e.target.classList.contains('rm')) e.target.closest('tr').remove();
  });
  document.getElementById('add-rule').addEventListener('click', function () {
    var tr = document.getElementById('rule-tpl').content.firstElementChild.cloneNode(true);
    body.appendChild(tr);
    tr.querySelector('.r-cat').focus();
  });
  if (!body.children.length) document.getElementById('add-rule').click();
})();
</script>
</body>
</html>
