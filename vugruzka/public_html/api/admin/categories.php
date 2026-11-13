<?php
require __DIR__ . '/_auth.php';
admin_require_login();
$active = 'categories';

$pdo = db_connect();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_check();
    $slug = $_POST['slug'] ?? '';
    $stmt = $pdo->prepare('DELETE FROM catalog_categories WHERE slug = ?');
    $stmt->execute([$slug]);
    header('Location: categories.php');
    exit;
}

$rows = $pdo->query('SELECT * FROM catalog_categories ORDER BY parent_slug IS NOT NULL, sort_order')->fetchAll();
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Категории — админка</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>
<div class="wrap">
  <div class="toolbar">
    <h1 style="margin:0">Категории</h1>
    <a href="category_edit.php" class="btn">+ Добавить категорию</a>
  </div>

  <?php if (!$rows): ?>
    <div class="empty">Категорий пока нет</div>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th>Slug</th><th>Название</th><th>Родитель</th>
        <th class="num">Цена от</th><th class="num">Наценка ₽/т</th><th class="num">Остаток</th><th class="num">Порядок</th><th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><code><?= h($r['slug']) ?></code></td>
        <td><?= h($r['title']) ?></td>
        <td><?= h($r['parent_slug'] ?? '—') ?></td>
        <td class="num"><?= number_format((float)$r['price_from'], 0, '', ' ') ?> ₽</td>
        <td class="num"><?= $r['markup_per_ton'] >= 0 ? '+' : '' ?><?= number_format((float)$r['markup_per_ton'], 0, '', ' ') ?></td>
        <td class="num"><?= number_format((float)$r['in_stock'], 0, '', ' ') ?> т</td>
        <td class="num"><?= (int)$r['sort_order'] ?></td>
        <td class="actions">
          <a href="category_edit.php?slug=<?= urlencode($r['slug']) ?>" class="btn secondary small">Изменить</a>
          <form method="post" style="display:inline" onsubmit="return confirm('Удалить категорию «<?= h($r['title']) ?>»? Связанные товары тоже удалятся.');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="slug" value="<?= h($r['slug']) ?>">
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
