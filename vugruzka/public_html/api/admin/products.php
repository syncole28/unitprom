<?php
require __DIR__ . '/_auth.php';
admin_require_login();
require_once __DIR__ . '/../lib/adjustments.php';
$active = 'products';

$pdo = db_connect();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_check();
    $stmt = $pdo->prepare('DELETE FROM catalog_products WHERE id = ?');
    $stmt->execute([$_POST['id'] ?? '']);
    header('Location: products.php' . (!empty($_POST['back_filter']) ? '?category=' . urlencode($_POST['back_filter']) : ''));
    exit;
}

$baseQuery = 'SELECT p.*, c.markup_per_ton, (p.cost_price_per_ton + c.markup_per_ton) AS sell_price_per_ton
              FROM catalog_products p JOIN catalog_categories c ON c.slug = p.category_slug';

$filterCategory = $_GET['category'] ?? '';
if ($filterCategory !== '') {
    $stmt = $pdo->prepare($baseQuery . ' WHERE p.category_slug = ? ORDER BY p.sort_order');
    $stmt->execute([$filterCategory]);
} else {
    $stmt = $pdo->query($baseQuery . ' ORDER BY p.category_slug, p.sort_order');
}
$rows = $stmt->fetchAll();

// Длина по старшинству (название → вручную → правило) и поправка веса категории
$parents = adj_parents($pdo);
$rulesBySlug = adj_load_rules($pdo);
$pcts = adj_weight_pcts($pdo);
foreach ($rows as &$r) {
    $r['len'] = adj_resolve_length($r, $rulesBySlug, $parents);
}
unset($r);
$filterLength = $_GET['length'] ?? '';
if ($filterLength === 'none') {
    $rows = array_values(array_filter($rows, fn($r) => $r['len']['kind'] === 'none'));
}
$sourceNames = ['label' => 'из названия', 'manual' => 'вручную', 'rule' => 'по правилу', '' => ''];

$categories = $pdo->query('SELECT slug, title FROM catalog_categories ORDER BY title')->fetchAll();
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Товары — админка</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .len-src { font-size: 11px; color: #6b7080; white-space: nowrap; }
    .len-none { color: #F2611B; white-space: nowrap; }
  </style>
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>
<div class="wrap">
  <div class="toolbar">
    <h1 style="margin:0">Товары <?= count($rows) ? '(' . count($rows) . ')' : '' ?></h1>
    <div style="display:flex;gap:10px">
      <form method="get" style="display:flex;gap:10px">
        <select name="category" onchange="this.form.submit()">
          <option value="">Все категории</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= h($c['slug']) ?>" <?= $filterCategory === $c['slug'] ? 'selected' : '' ?>><?= h($c['title']) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="length" onchange="this.form.submit()">
          <option value="">Любая длина</option>
          <option value="none" <?= $filterLength === 'none' ? 'selected' : '' ?>>Длина не задана</option>
        </select>
      </form>
      <a href="product_edit.php<?= $filterCategory ? '?category=' . urlencode($filterCategory) : '' ?>" class="btn">+ Добавить товар</a>
    </div>
  </div>

  <?php if (!$rows): ?>
    <div class="empty">Товаров не найдено</div>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th>ID</th><th>Название</th><th>Категория</th>
        <th class="num">Вес кг/м</th><th class="num">Длина</th>
        <th class="num">Закупка ₽/т</th><th class="num">Наценка</th><th class="num">Цена продажи ₽/т</th>
        <th class="num">Остаток</th><th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><code><?= h($r['id']) ?></code></td>
        <td><?= h($r['label']) ?></td>
        <td><?= h($r['category_slug']) ?></td>
        <td class="num"><?= rtrim(rtrim(number_format((float)$r['weight_per_meter'], 4, '.', ''), '0'), '.') ?>
          <?php if (!empty($pcts[$r['category_slug']])): $pc = $pcts[$r['category_slug']]; ?><div class="len-src"><?= $pc > 0 ? '+' : '' ?><?= adj_num($pc) ?>% на сайте</div><?php endif; ?></td>
        <td class="num">
          <?php if ($r['len']['kind'] === 'none'): ?>
            <span class="len-none">не задана</span>
          <?php else: ?>
            <?= h(adj_length_caption($r['len'])) ?>
            <div class="len-src"><?= h($sourceNames[$r['len']['source']]) ?></div>
          <?php endif; ?>
        </td>
        <td class="num"><?= number_format((float)$r['cost_price_per_ton'], 0, '', ' ') ?></td>
        <td class="num"><?= $r['markup_per_ton'] >= 0 ? '+' : '' ?><?= number_format((float)$r['markup_per_ton'], 0, '', ' ') ?></td>
        <td class="num"><strong><?= number_format((float)$r['sell_price_per_ton'], 0, '', ' ') ?></strong></td>
        <td class="num"><?= number_format((float)$r['in_stock'], 0, '', ' ') ?> т</td>
        <td class="actions">
          <a href="product_edit.php?id=<?= urlencode($r['id']) ?>" class="btn secondary small">Изменить</a>
          <form method="post" style="display:inline" onsubmit="return confirm('Удалить товар «<?= h($r['label']) ?>»?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= h($r['id']) ?>">
            <input type="hidden" name="back_filter" value="<?= h($filterCategory) ?>">
            <button type="submit" class="btn danger small">Удалить</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
</body>
</html>
