<?php
// Счётчик новых заявок в меню
$navNewApps = 0;
try {
    $navNewApps = (int)db_connect()->query("SELECT COUNT(*) FROM applications WHERE status = 'new'")->fetchColumn();
} catch (Throwable $e) {
    // до миграции шага 8 колонки status нет — просто без счётчика
}
?>
<nav class="nav">
  <span class="brand">Юнитпром · админка</span>
  <a href="index.php" class="<?= $active === 'dashboard' ? 'active' : '' ?>">Обзор</a>
  <a href="categories.php" class="<?= $active === 'categories' ? 'active' : '' ?>">Категории</a>
  <a href="products.php" class="<?= $active === 'products' ? 'active' : '' ?>">Товары</a>
  <a href="price_list.php" class="<?= $active === 'price' ? 'active' : '' ?>">Прайс-лист</a>
  <a href="supplier_price.php" class="<?= $active === 'supplier' ? 'active' : '' ?>">Прайс поставщика</a>
  <a href="markup.php" class="<?= $active === 'markup' ? 'active' : '' ?>">Ручные корректировки</a>
  <a href="featured.php" class="<?= $active === 'featured' ? 'active' : '' ?>">Ходовые позиции</a>
  <a href="applications.php" class="<?= $active === 'applications' ? 'active' : '' ?>">Заявки<?= $navNewApps ? '<span class="cnt">' . $navNewApps . '</span>' : '' ?></a>
  <a href="clients.php" class="<?= $active === 'clients' ? 'active' : '' ?>">Клиенты</a>
  <span class="spacer"></span>
  <a href="logout.php">Выйти</a>
</nav>
