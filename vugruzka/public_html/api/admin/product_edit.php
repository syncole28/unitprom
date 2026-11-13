<?php
require __DIR__ . '/_auth.php';
admin_require_login();
require_once __DIR__ . '/../lib/adjustments.php';
$active = 'products';

$pdo = db_connect();
$editId = $_GET['id'] ?? null;
$isNew = $editId === null;
$row = [
    'id' => '', 'category_slug' => $_GET['category'] ?? '', 'label' => '', 'weight_per_meter' => 0,
    'length_m' => null, 'length_nemer' => '', 'cost_price_per_ton' => 0, 'sort_order' => 0, 'param_value' => '', 'in_stock' => 0,
    'param1_value' => '', 'param2_value' => '',
];
$error = '';

if (!$isNew) {
    $stmt = $pdo->prepare('SELECT * FROM catalog_products WHERE id = ?');
    $stmt->execute([$editId]);
    $found = $stmt->fetch();
    if (!$found) { header('Location: products.php'); exit; }
    $row = $found;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = trim($_POST['id'] ?? '');
    $data = [
        'id'               => $id,
        'category_slug'    => trim($_POST['category_slug'] ?? ''),
        'label'            => trim($_POST['label'] ?? ''),
        'weight_per_meter' => (float)($_POST['weight_per_meter'] ?? 0),
        'length_m'           => null,
        'length_nemer'       => '',
        'cost_price_per_ton' => (int)($_POST['cost_price_per_ton'] ?? 0),
        'sort_order'       => (int)($_POST['sort_order'] ?? 0),
        'param_value'      => $_POST['param_value'] !== '' ? (float)$_POST['param_value'] : null,
        'in_stock'         => (int)($_POST['in_stock'] ?? 0),
        'param1_value'     => trim($_POST['param1_value'] ?? '') ?: null,
        'param2_value'     => trim($_POST['param2_value'] ?? '') ?: null,
    ];

    // Длина: по правилам вкладки «Ручные корректировки» (пусто), своя или немерная
    $lengthMode = $_POST['length_mode'] ?? 'rule';
    $lenA = (float)str_replace(',', '.', (string)($_POST['length_m'] ?? ''));
    $lenB = (float)str_replace(',', '.', (string)($_POST['length_m2'] ?? ''));
    $lengthError = '';
    if ($lengthMode === 'fixed') {
        if ($lenA > 0 && $lenA <= 100) $data['length_m'] = $lenA;
        else $lengthError = 'Укажите длину в метрах';
    } elseif ($lengthMode === 'nemer') {
        if ($lenA > 0 && $lenB > 0) $data['length_nemer'] = adj_norm_range($lenA . '-' . $lenB);
        else $lengthError = 'Укажите, от скольких до скольких метров немерная';
    }

    if ($lengthError !== '') {
        $error = $lengthError;
        $row = array_merge($row, $data);
    } elseif ($id === '' || $data['category_slug'] === '' || $data['label'] === '') {
        $error = 'ID, категория и название обязательны';
        $row = array_merge($row, $data);
    } else {
        try {
            if ($isNew) {
                $stmt = $pdo->prepare(
                    'INSERT INTO catalog_products (id, category_slug, label, weight_per_meter, length_m, length_nemer, cost_price_per_ton, sort_order, param_value, in_stock, param1_value, param2_value)
                     VALUES (:id, :category_slug, :label, :weight_per_meter, :length_m, :length_nemer, :cost_price_per_ton, :sort_order, :param_value, :in_stock, :param1_value, :param2_value)'
                );
                $stmt->execute($data);
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE catalog_products SET category_slug=:category_slug, label=:label, weight_per_meter=:weight_per_meter,
                     length_m=:length_m, length_nemer=:length_nemer, cost_price_per_ton=:cost_price_per_ton, sort_order=:sort_order, param_value=:param_value, in_stock=:in_stock,
                     param1_value=:param1_value, param2_value=:param2_value WHERE id=:id'
                );
                $stmt->execute($data);
            }
            header('Location: products.php?category=' . urlencode($data['category_slug']));
            exit;
        } catch (Throwable $e) {
            $error = 'Не удалось сохранить: ' . $e->getMessage()
                . '. Если ошибка про unknown column — выполните api/migration_v2.sql в phpMyAdmin.';
            $row = array_merge($row, $data);
        }
    }
}

$categories = $pdo->query('SELECT slug, title, markup_per_ton, filter1_label, filter1_type, filter1_values, filter2_label, filter2_type, filter2_values FROM catalog_categories ORDER BY title')->fetchAll();
$markupBySlug = array_column($categories, 'markup_per_ton', 'slug');
// Подсказка, какой фильтр каталога на сайте читает «Параметр 1» и «Параметр 2» в этой категории
$filterHints = [];
foreach ($categories as $c) {
    foreach ([1, 2] as $n) {
        if (trim((string)$c["filter{$n}_label"]) === '' || trim((string)$c["filter{$n}_values"]) === '') continue;
        $filterHints[$c['slug']][$n] = 'Фильтр на сайте «' . trim($c["filter{$n}_label"]) . '»: '
            . (trim((string)$c["filter{$n}_type"]) === 'range' ? 'число из диапазонов ' : 'одно из ')
            . trim($c["filter{$n}_values"]);
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $isNew ? 'Новый товар' : 'Изменить товар' ?> — админка</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .len-edit { display: flex; align-items: center; gap: 6px; color: #A7ACB8; }
    .len-edit > span { white-space: nowrap; }
    .field-group .len-edit select { width: auto; flex: 1 1 0; min-width: 0; }
    .field-group .len-edit input { width: 50px; padding-left: 7px; padding-right: 7px; }
  </style>
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>
<div class="wrap" style="max-width:720px">
  <h1><?= $isNew ? 'Новый товар' : 'Изменить: ' . h($row['label']) ?></h1>
  <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="form-grid">
      <div class="field-group">
        <label>ID</label>
        <input name="id" value="<?= h($row['id']) ?>" <?= $isNew ? 'required' : 'readonly' ?>>
        <?php if (!$isNew): ?><div class="hint">ID нельзя менять после создания</div><?php endif; ?>
      </div>
      <div class="field-group">
        <label>Категория</label>
        <select name="category_slug" id="category_slug" required>
          <?php foreach ($categories as $c): ?>
            <option value="<?= h($c['slug']) ?>" <?= $row['category_slug'] === $c['slug'] ? 'selected' : '' ?>><?= h($c['title']) ?> (<?= h($c['slug']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field-group full">
        <label>Название</label>
        <input name="label" value="<?= h($row['label']) ?>" required>
      </div>
      <div class="field-group">
        <label>Вес, кг/м</label>
        <input type="number" step="0.0001" name="weight_per_meter" value="<?= h((string)$row['weight_per_meter']) ?>">
      </div>
      <div class="field-group">
        <label>Длина</label>
        <?php
          $isNemer = trim((string)($row['length_nemer'] ?? '')) !== '';
          $hasLen = !$isNemer && $row['length_m'] !== null && $row['length_m'] !== '' && (float)$row['length_m'] > 0;
          $mode = $isNemer ? 'nemer' : ($hasLen ? 'fixed' : 'rule');
          if (isset($lengthMode)) $mode = $lengthMode; // после ошибки — как выбрали
          $range = $isNemer ? preg_split('/[-–—]/u', (string)$row['length_nemer']) : [];
          $lenVal = $isNemer ? trim($range[0] ?? '') : ($hasLen ? rtrim(rtrim(number_format((float)$row['length_m'], 2, '.', ''), '0'), '.') : '');
          $lenVal2 = $isNemer ? trim($range[1] ?? '') : '';
          if (isset($lengthMode)) { $lenVal = (string)($_POST['length_m'] ?? ''); $lenVal2 = (string)($_POST['length_m2'] ?? ''); }
        ?>
        <div class="len-edit">
          <select name="length_mode" id="length_mode">
            <option value="rule" <?= $mode === 'rule' ? 'selected' : '' ?>>По правилам категории</option>
            <option value="fixed" <?= $mode === 'fixed' ? 'selected' : '' ?>>Своя длина</option>
            <option value="nemer" <?= $mode === 'nemer' ? 'selected' : '' ?>>Немерная</option>
          </select>
          <span id="len-a"><span id="len-from">от</span> <input type="text" inputmode="decimal" name="length_m" value="<?= h($lenVal) ?>"></span>
          <span id="len-b">до <input type="text" inputmode="decimal" name="length_m2" value="<?= h($lenVal2) ?>"></span>
          <span id="len-unit">м</span>
        </div>
        <?php if (!$isNew):
            $len = adj_resolve_length($row, adj_load_rules($pdo), adj_parents($pdo));
            $srcText = [
                'label' => 'из названия — она главнее, поле выше не действует',
                'manual' => 'задана вручную',
                'rule' => 'по правилу вкладки «Ручные корректировки»',
            ][$len['source']] ?? '';
        ?>
          <div class="hint">Сейчас на сайте:
            <?= $len['kind'] === 'none' ? '<b style="color:#F2611B">не задана</b>, цены за штуку нет' : h(adj_length_caption($len)) . ', ' . h($srcText) ?>
          </div>
        <?php endif; ?>
      </div>
      <div class="field-group">
        <label>Закупка, ₽/т</label>
        <input type="number" id="cost_price_per_ton" name="cost_price_per_ton" value="<?= h((string)$row['cost_price_per_ton']) ?>">
        <div class="hint" id="sell-price-preview"></div>
      </div>
      <div class="field-group">
        <label>Остаток, т</label>
        <input type="number" name="in_stock" value="<?= h((string)$row['in_stock']) ?>">
      </div>
      <div class="field-group">
        <label>Порядок сортировки</label>
        <input type="number" name="sort_order" value="<?= h((string)$row['sort_order']) ?>">
      </div>
      <div class="field-group">
        <label>Параметр 1</label>
        <input name="param1_value" value="<?= h((string)($row['param1_value'] ?? '')) ?>">
        <div class="hint" id="param1-hint"></div>
      </div>
      <div class="field-group">
        <label>Параметр 2</label>
        <input name="param2_value" value="<?= h((string)($row['param2_value'] ?? '')) ?>">
        <div class="hint" id="param2-hint"></div>
      </div>
      <div class="field-group">
        <label>Числовой параметр (param_value)</label>
        <input type="number" step="0.0001" name="param_value" value="<?= h((string)($row['param_value'] ?? '')) ?>">
      </div>
    </div>
    <div style="margin-top:20px;display:flex;gap:10px">
      <button type="submit" class="btn">Сохранить</button>
      <a href="products.php" class="btn secondary">Отмена</a>
    </div>
  </form>
</div>
<script>
  var markupBySlug = <?= json_encode($markupBySlug, JSON_UNESCAPED_UNICODE) ?>;
  var catSelect = document.getElementById('category_slug');
  var costInput = document.getElementById('cost_price_per_ton');
  var preview = document.getElementById('sell-price-preview');

  function updatePreview() {
    var markup = markupBySlug[catSelect.value] || 0;
    var cost = parseFloat(costInput.value) || 0;
    var sell = cost + markup;
    preview.textContent = 'Цена продажи при текущей наценке категории (' +
      (markup >= 0 ? '+' : '') + markup + ' ₽/т): ' + sell.toLocaleString('ru-RU') + ' ₽/т';
  }

  var filterHints = <?= json_encode($filterHints, JSON_UNESCAPED_UNICODE) ?>;
  function updateFilterHints() {
    var hints = filterHints[catSelect.value] || {};
    document.getElementById('param1-hint').textContent = hints[1] || 'В этой категории фильтр 1 не настроен';
    document.getElementById('param2-hint').textContent = hints[2] || 'В этой категории фильтр 2 не настроен';
  }
  catSelect.addEventListener('change', updateFilterHints);
  updateFilterHints();

  var lengthMode = document.getElementById('length_mode');
  function updateLengthFields() {
    var m = lengthMode.value;
    document.getElementById('len-a').hidden = m === 'rule';
    document.getElementById('len-unit').hidden = m === 'rule';
    document.getElementById('len-from').hidden = m !== 'nemer';
    document.getElementById('len-b').hidden = m !== 'nemer';
  }
  lengthMode.addEventListener('change', function () {
    var a = document.querySelector('[name=length_m]'), b = document.querySelector('[name=length_m2]');
    if (lengthMode.value === 'nemer' && !b.value) { a.value = '9'; b.value = '12'; }
    if (lengthMode.value === 'fixed' && !a.value) a.value = '12';
    updateLengthFields();
  });
  updateLengthFields();

  catSelect.addEventListener('change', updatePreview);
  costInput.addEventListener('input', updatePreview);
  updatePreview();
</script>
</body>
</html>
