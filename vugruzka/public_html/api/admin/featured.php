<?php
require __DIR__ . '/_auth.php';
admin_require_login();
$active = 'featured';

$pdo = db_connect();
$error = '';
$success = '';
$SLOTS = 8;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $picks = [];
    for ($i = 1; $i <= $SLOTS; $i++) {
        $val = trim($_POST['slot' . $i] ?? '');
        if ($val !== '') $picks[] = $val;
    }

    try {
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM featured_products');
        if ($picks) {
            $stmt = $pdo->prepare('INSERT INTO featured_products (sort_order, product_id) VALUES (?, ?)');
            foreach ($picks as $i => $productId) {
                $stmt->execute([$i + 1, $productId]);
            }
        }
        $pdo->commit();
        $success = 'Сохранено — ' . count($picks) . ' позици' . (count($picks) === 1 ? 'я' : (count($picks) < 5 ? 'и' : 'й')) . ' на главной.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = 'Не удалось сохранить: ' . $e->getMessage();
    }
}

$products = $pdo->query(
    'SELECT p.id, p.label, c.title AS cat_title
     FROM catalog_products p JOIN catalog_categories c ON c.slug = p.category_slug
     ORDER BY c.title, p.sort_order'
)->fetchAll();

$productsByCat = [];
foreach ($products as $p) {
    $productsByCat[$p['cat_title']][] = $p;
}

$current = $pdo->query('SELECT sort_order, product_id FROM featured_products ORDER BY sort_order')->fetchAll();
$currentBySlot = [];
foreach ($current as $c) {
    $currentBySlot[(int)$c['sort_order']] = $c['product_id'];
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Ходовые позиции — админка</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>
<div class="wrap" style="max-width:640px">
  <h1>Цены на ходовые позиции</h1>
  <p class="hint" style="margin:-8px 0 20px">
    Блок на главной странице сайта. Выберите товары из каталога — название и
    цена (уже с наценкой) подтянутся сами и всегда будут актуальными. Пустые
    строки просто не покажутся на сайте.
  </p>

  <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="card" style="border-color:#2d6a4f;background:rgba(52,199,89,.08);margin-bottom:16px;padding:12px 16px;color:#4ade80"><?= h($success) ?></div><?php endif; ?>

  <form method="post">
    <?= csrf_field() ?>
    <?php for ($i = 1; $i <= $SLOTS; $i++): $selected = $currentBySlot[$i] ?? ''; ?>
      <div class="field-group">
        <label>Строка <?= $i ?></label>
        <select name="slot<?= $i ?>">
          <option value="">— пусто —</option>
          <?php foreach ($productsByCat as $catTitle => $prods): ?>
            <optgroup label="<?= h($catTitle) ?>">
              <?php foreach ($prods as $p): ?>
                <option value="<?= h($p['id']) ?>" <?= $selected === $p['id'] ? 'selected' : '' ?>><?= h($p['label']) ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endfor; ?>

    <button type="submit" class="btn" style="margin-top:10px">Сохранить</button>
  </form>
</div>
</body>
</html>
