<?php
require __DIR__ . '/_auth.php';
admin_require_login();
require __DIR__ . '/../lib/xlsx.php';
require __DIR__ . '/../lib/catalog_columns.php';
require_once __DIR__ . '/../lib/adjustments.php';

$pdo = db_connect();
$categories = $pdo->query('SELECT * FROM catalog_categories ORDER BY parent_slug IS NOT NULL, sort_order')->fetchAll();
$products   = $pdo->query('SELECT * FROM catalog_products ORDER BY category_slug, sort_order')->fetchAll();

$catCols = category_columns();
$catFieldMap = category_field_map();
$catRows = [$catCols];
foreach ($categories as $c) {
    $row = [];
    foreach ($catFieldMap as $header => $field) {
        $row[] = $c[$field] ?? '';
    }
    $catRows[] = $row;
}

$prodCols = product_columns();
$prodFieldMap = product_field_map();
$prodRows = [$prodCols];
foreach ($products as $p) {
    $row = [];
    // По заголовкам, а не по карте полей: в карте у закупки два заголовка (новый и старый),
    // и раньше закупка писалась дважды — остаток и параметры съезжали на колонку вправо.
    foreach ($prodCols as $header) {
        $field = $prodFieldMap[$header];
        // Длина: своя (число), «немер 9–12» или пусто — по правилам «Ручных корректировок»
        $row[] = $field === 'length_m' ? adj_length_cell($p) : ($p[$field] ?? '');
    }
    $prodRows[] = $row;
}

$tmpPath = tempnam(sys_get_temp_dir(), 'catalog_export_') . '.xlsx';
XlsxWriter::write(['Категории' => $catRows, 'Позиции' => $prodRows], $tmpPath);

$filename = 'unitprom-catalog-' . date('Y-m-d') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpPath));
readfile($tmpPath);
unlink($tmpPath);
exit;
