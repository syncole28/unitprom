<?php
require __DIR__ . '/_auth.php';
admin_require_login();

$pdo = db_connect();
$catCount = (int)$pdo->query('SELECT COUNT(*) c FROM catalog_categories')->fetch()['c'];
$prodCount = (int)$pdo->query('SELECT COUNT(*) c FROM catalog_products')->fetch()['c'];
$newApps = (int)$pdo->query("SELECT COUNT(*) c FROM applications WHERE status = 'new'")->fetch()['c'];
$clientCount = (int)$pdo->query('SELECT COUNT(*) c FROM clients')->fetch()['c'];
$totalApps = (int)$pdo->query('SELECT COUNT(*) c FROM applications')->fetch()['c'];
$active = 'dashboard';
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Обзор — админка</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>
<div class="wrap">
  <h1>Обзор</h1>
  <div class="cards">
    <a href="categories.php" class="card">
      <div class="num"><?= $catCount ?></div>
      <div class="label">Категорий</div>
    </a>
    <a href="products.php" class="card">
      <div class="num"><?= $prodCount ?></div>
      <div class="label">Товаров</div>
    </a>
    <a href="applications.php?status=new" class="card">
      <div class="num"><?= $newApps ?></div>
      <div class="label">Новых заявок</div>
    </a>
    <a href="applications.php" class="card">
      <div class="num"><?= $totalApps ?></div>
      <div class="label">Заявок всего</div>
    </a>
    <a href="clients.php" class="card">
      <div class="num"><?= $clientCount ?></div>
      <div class="label">Клиентов</div>
    </a>
  </div>
</div>
</body>
</html>
