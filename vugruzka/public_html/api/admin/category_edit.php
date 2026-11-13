<?php
require __DIR__ . '/_auth.php';
admin_require_login();
$active = 'categories';

$pdo = db_connect();
$editSlug = $_GET['slug'] ?? null;
$isNew = $editSlug === null;
$row = [
    'slug' => '', 'parent_slug' => '', 'title' => '', 'short_desc' => '', 'steel' => '',
    'gost_main' => '', 'price_from' => 0, 'markup_per_ton' => 0, 'in_stock' => 0, 'image' => '', 'sort_order' => 0,
    'filter1_label' => '', 'filter1_type' => '', 'filter1_values' => '',
    'filter2_label' => '', 'filter2_type' => '', 'filter2_values' => '',
];
$error = '';

if (!$isNew) {
    $stmt = $pdo->prepare('SELECT * FROM catalog_categories WHERE slug = ?');
    $stmt->execute([$editSlug]);
    $found = $stmt->fetch();
    if (!$found) { header('Location: categories.php'); exit; }
    $row = $found;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $slug = trim($_POST['slug'] ?? '');
    $data = [
        'slug'           => $slug,
        'parent_slug'    => trim($_POST['parent_slug'] ?? '') ?: null,
        'title'          => trim($_POST['title'] ?? ''),
        'short_desc'     => trim($_POST['short_desc'] ?? ''),
        'steel'          => trim($_POST['steel'] ?? ''),
        'gost_main'      => trim($_POST['gost_main'] ?? ''),
        'price_from'     => (int)($_POST['price_from'] ?? 0),
        'markup_per_ton' => (int)($_POST['markup_per_ton'] ?? 0),
        'in_stock'       => (int)($_POST['in_stock'] ?? 0),
        'image'          => trim($_POST['image'] ?? ''),
        'sort_order'     => (int)($_POST['sort_order'] ?? 0),
        'filter1_label'  => trim($_POST['filter1_label'] ?? '') ?: null,
        'filter1_type'   => trim($_POST['filter1_type'] ?? '') ?: null,
        'filter1_values' => trim($_POST['filter1_values'] ?? '') ?: null,
        'filter2_label'  => trim($_POST['filter2_label'] ?? '') ?: null,
        'filter2_type'   => trim($_POST['filter2_type'] ?? '') ?: null,
        'filter2_values' => trim($_POST['filter2_values'] ?? '') ?: null,
    ];

    if ($slug === '' || $data['title'] === '') {
        $error = 'Slug и название обязательны';
        $row = array_merge($row, $data);
    } else {
        try {
            if ($isNew) {
                $stmt = $pdo->prepare(
                    'INSERT INTO catalog_categories (slug, parent_slug, title, short_desc, steel, gost_main, price_from, markup_per_ton, in_stock, image, sort_order, filter1_label, filter1_type, filter1_values, filter2_label, filter2_type, filter2_values)
                     VALUES (:slug, :parent_slug, :title, :short_desc, :steel, :gost_main, :price_from, :markup_per_ton, :in_stock, :image, :sort_order, :filter1_label, :filter1_type, :filter1_values, :filter2_label, :filter2_type, :filter2_values)'
                );
                $stmt->execute($data);
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE catalog_categories SET parent_slug=:parent_slug, title=:title, short_desc=:short_desc, steel=:steel,
                     gost_main=:gost_main, price_from=:price_from, markup_per_ton=:markup_per_ton, in_stock=:in_stock, image=:image, sort_order=:sort_order,
                     filter1_label=:filter1_label, filter1_type=:filter1_type, filter1_values=:filter1_values,
                     filter2_label=:filter2_label, filter2_type=:filter2_type, filter2_values=:filter2_values
                     WHERE slug=:slug'
                );
                $stmt->execute($data);
            }
            header('Location: categories.php');
            exit;
        } catch (Throwable $e) {
            $error = 'Не удалось сохранить: ' . $e->getMessage();
            $row = array_merge($row, $data);
        }
    }
}

$allCats = $pdo->query('SELECT slug, title FROM catalog_categories WHERE parent_slug IS NULL ORDER BY sort_order')->fetchAll();
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $isNew ? 'Новая категория' : 'Изменить категорию' ?> — админка</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>
<div class="wrap" style="max-width:720px">
  <h1><?= $isNew ? 'Новая категория' : 'Изменить: ' . h($row['title']) ?></h1>
  <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="form-grid">
      <div class="field-group">
        <label>Slug</label>
        <input name="slug" value="<?= h($row['slug']) ?>" <?= $isNew ? 'required' : 'readonly' ?>>
        <?php if (!$isNew): ?><div class="hint">Slug нельзя менять после создания (на него ссылаются товары)</div><?php endif; ?>
      </div>
      <div class="field-group">
        <label>Родительская категория</label>
        <select name="parent_slug">
          <option value="">— нет (это категория верхнего уровня) —</option>
          <?php foreach ($allCats as $c): if ($c['slug'] === $row['slug']) continue; ?>
            <option value="<?= h($c['slug']) ?>" <?= $row['parent_slug'] === $c['slug'] ? 'selected' : '' ?>><?= h($c['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field-group full">
        <label>Название</label>
        <input name="title" value="<?= h($row['title']) ?>" required>
      </div>
      <div class="field-group full">
        <label>Описание</label>
        <input name="short_desc" value="<?= h($row['short_desc']) ?>">
      </div>
      <div class="field-group">
        <label>Марка стали</label>
        <input name="steel" value="<?= h($row['steel']) ?>">
      </div>
      <div class="field-group">
        <label>ГОСТ</label>
        <input name="gost_main" value="<?= h($row['gost_main']) ?>">
      </div>
      <div class="field-group">
        <label>Цена от, ₽/т</label>
        <input type="number" name="price_from" value="<?= h((string)$row['price_from']) ?>">
        <div class="hint">На сайте показывается автоматически — минимальная цена товаров категории с учётом наценки. Это значение используется, только если в категории нет товаров с закупкой.</div>
      </div>
      <div class="field-group">
        <label>Наценка, ₽/т</label>
        <input type="number" name="markup_per_ton" value="<?= h((string)$row['markup_per_ton']) ?>">
        <div class="hint">Прибавляется к закупочной цене каждого товара категории при показе на сайте. Прибавлением, не процентом — чтобы итоговая цена оставалась круглой.</div>
      </div>
      <div class="field-group">
        <label>Остаток, т</label>
        <input type="number" name="in_stock" value="<?= h((string)$row['in_stock']) ?>">
      </div>
      <div class="field-group full">
        <label>Ссылка на фото</label>
        <input name="image" value="<?= h($row['image']) ?>">
      </div>
      <div class="field-group">
        <label>Порядок сортировки</label>
        <input type="number" name="sort_order" value="<?= h((string)$row['sort_order']) ?>">
      </div>
    </div>

    <h1 style="font-size:16px;margin-top:24px">Фильтры подкатегории (необязательно)</h1>
    <div class="form-grid">
      <div class="field-group"><label>Фильтр 1 — название</label><input name="filter1_label" value="<?= h($row['filter1_label']) ?>"></div>
      <div class="field-group"><label>Фильтр 1 — тип (range / enum)</label><input name="filter1_type" value="<?= h($row['filter1_type']) ?>"></div>
      <div class="field-group full"><label>Фильтр 1 — значения (через запятую)</label><input name="filter1_values" value="<?= h($row['filter1_values']) ?>"></div>
      <div class="field-group"><label>Фильтр 2 — название</label><input name="filter2_label" value="<?= h($row['filter2_label']) ?>"></div>
      <div class="field-group"><label>Фильтр 2 — тип (range / enum)</label><input name="filter2_type" value="<?= h($row['filter2_type']) ?>"></div>
      <div class="field-group full"><label>Фильтр 2 — значения (через запятую)</label><input name="filter2_values" value="<?= h($row['filter2_values']) ?>"></div>
    </div>

    <div style="margin-top:20px;display:flex;gap:10px">
      <button type="submit" class="btn">Сохранить</button>
      <a href="categories.php" class="btn secondary">Отмена</a>
    </div>
  </form>
</div>
</body>
</html>
