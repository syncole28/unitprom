<?php
require __DIR__ . '/_auth.php';
admin_require_login();
require __DIR__ . '/../lib/xlsx.php';
require __DIR__ . '/../lib/xls.php';
require __DIR__ . '/../lib/supplier_price.php';
$active = 'supplier';

$pdo = db_connect();
// Поставщик — из формы; загруженный прайс каждого хранится в сессии отдельно,
// чтобы два открытых окна с разными прайсами не мешали друг другу
$supplier = $_POST['supplier'] ?? 'spk';
if (!isset(SUPPLIERS[$supplier])) $supplier = 'spk';
$supplierTitle = SUPPLIERS[$supplier];
$step = 'upload';
$error = '';
$result = null;

$tier = (int)admin_setting_get($pdo, "price_tier_$supplier", '0');
$sheetCats = json_decode(admin_setting_get($pdo, "sheet_categories_$supplier", '{}'), true) ?: [];

$postStep = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['step'] ?? '') : '';
if ($postStep !== '') csrf_check();

if ($postStep === 'preview') {
    $tier = (int)($_POST['tier'] ?? $tier);
    if (empty($_FILES['price_file']['tmp_name']) || $_FILES['price_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Файл не загружен';
    } else {
        try {
            $parsed = supplier_parse_file($supplier, $_FILES['price_file']['tmp_name']);
            $parsed['file'] = $_FILES['price_file']['name'];
            $_SESSION['supplier_import'][$supplier] = $parsed;
            $step = 'preview';
        } catch (Throwable $e) {
            $error = 'Не получилось прочитать файл ' . $supplierTitle . ': ' . $e->getMessage();
        }
    }
} elseif ($postStep === 'retier' || $postStep === 'confirm') {
    $tier = (int)($_POST['tier'] ?? $tier);
    if (empty($_SESSION['supplier_import'][$supplier]['rows'])) {
        $error = 'Загруженный прайс потерялся (истекла сессия) — загрузите файл ещё раз';
    } else {
        $step = 'preview';
    }
}

$data = $step === 'preview' ? $_SESSION['supplier_import'][$supplier] : null;
if ($data) {
    $tierCount = count($data['tiers']);
    if ($tier < 0 || $tier >= $tierCount) $tier = 0;
}

/** Привязанные к поставщику товары вместе с текущей закупкой и наценкой. */
function load_links(PDO $pdo, string $supplier): array {
    $stmt = $pdo->prepare(
        'SELECT l.id AS link_id, l.supplier_key, l.supplier_label, l.product_id, l.last_price,
                p.label, p.category_slug, p.cost_price_per_ton, p.weight_per_meter, c.markup_per_ton
         FROM supplier_links l
         JOIN catalog_products p ON p.id = l.product_id
         JOIN catalog_categories c ON c.slug = p.category_slug
         WHERE l.supplier = ?
         ORDER BY p.category_slug, p.sort_order'
    );
    $stmt->execute([$supplier]);
    return $stmt->fetchAll();
}

if ($postStep === 'confirm' && $data) {
    $rowsByKey = [];
    foreach ($data['rows'] as $r) $rowsByKey[$r['key']] = $r;

    $selection = json_decode($_POST['selection'] ?? '[]', true);
    $unlink = array_map('intval', (array)($_POST['unlink'] ?? []));
    if (!is_array($selection)) $selection = [];
    $sheetDefaults = json_decode($_POST['sheet_defaults'] ?? '{}', true);
    if (is_array($sheetDefaults)) {
        foreach ($sheetDefaults as $sheetName => $slug) {
            if (is_string($slug) && $slug !== '') $sheetCats[$sheetName] = $slug;
        }
    }

    $catExists = array_flip($pdo->query('SELECT slug FROM catalog_categories')->fetchAll(PDO::FETCH_COLUMN));
    $problems = [];
    foreach ($selection as $s) {
        $row = $rowsByKey[$s['key'] ?? ''] ?? null;
        if (!$row) { $problems[] = 'строка прайса не найдена — загрузите файл заново'; continue; }
        if (!supplier_row_addable($row, $tier)) { $problems[] = "«{$row['name']}»: нет цены в выбранной колонке"; continue; }
        if (($s['link_to'] ?? '') === '') {
            if (!isset($catExists[$s['category'] ?? ''])) {
                $problems[] = "«{$row['name']}» (лист «{$row['sheet']}»): выберите категорию сайта";
            }
            if (supplier_apply_edits($row, $s)['name'] === '') {
                $problems[] = "«{$row['name']}» (лист «{$row['sheet']}»): пустое наименование";
            }
        }
    }

    if ($problems) {
        $error = "Не сохранено:\n" . implode("\n", array_unique($problems));
    } else {
        try {
            $pdo->beginTransaction();
            $now = date('Y-m-d H:i:s');
            $res = ['updated' => 0, 'created' => 0, 'linked' => 0, 'unlinked' => 0];

            if ($unlink) {
                $ph = implode(',', array_fill(0, count($unlink), '?'));
                $stmt = $pdo->prepare("DELETE FROM supplier_links WHERE supplier = ? AND id IN ($ph)");
                $stmt->execute(array_merge([$supplier], $unlink));
                $res['unlinked'] = $stmt->rowCount();
            }

            // 1. Обновляем закупку у уже привязанных товаров
            $updCost = $pdo->prepare('UPDATE catalog_products SET cost_price_per_ton = ? WHERE id = ?');
            $updLink = $pdo->prepare('UPDATE supplier_links SET last_price = ?, last_seen_at = ?, supplier_label = ? WHERE id = ?');
            foreach (load_links($pdo, $supplier) as $l) {
                $row = $rowsByKey[$l['supplier_key']] ?? null;
                if (!$row) continue;
                $price = supplier_row_price($row, $tier);
                if ($price !== null && $price !== (int)$l['cost_price_per_ton']) {
                    $updCost->execute([$price, $l['product_id']]);
                    $res['updated']++;
                }
                $updLink->execute([$price, $now, supplier_row_title($row), $l['link_id']]);
            }

            // 2. Новые товары и привязки к существующим
            $insLink = $pdo->prepare(
                'INSERT INTO supplier_links (supplier, supplier_key, supplier_label, product_id, last_price, last_seen_at)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE supplier_key = VALUES(supplier_key), supplier_label = VALUES(supplier_label),
                    product_id = VALUES(product_id), last_price = VALUES(last_price), last_seen_at = VALUES(last_seen_at)'
            );
            $insProduct = $pdo->prepare(
                'INSERT INTO catalog_products (id, category_slug, label, weight_per_meter, length_m, cost_price_per_ton, sort_order, in_stock, param1_value, param2_value)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)'
            );
            $maxSort = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM catalog_products WHERE category_slug = ?');
            $findProduct = $pdo->prepare('SELECT id, weight_per_meter FROM catalog_products WHERE id = ?');
            $fillWeight = $pdo->prepare('UPDATE catalog_products SET weight_per_meter = ? WHERE id = ? AND weight_per_meter = 0');
            // Привязка к товару, который раньше был привязан к другой строке, заменяет старую
            $dropOld = $pdo->prepare('DELETE FROM supplier_links WHERE supplier = ? AND product_id = ? AND supplier_key <> ?');

            foreach ($selection as $s) {
                $row = $rowsByKey[$s['key']];
                $price = supplier_row_price($row, $tier);
                $linkTo = trim((string)($s['link_to'] ?? ''));
                $edited = supplier_apply_edits($row, $s);
                // Вес 1 м (у листа — 1 м²) — из строки на странице (там уже прайс или расчёт по ГОСТу,
                // его могли поправить). Если поля не было — из прайса, иначе расчётный.
                if (array_key_exists('weight', $s)) {
                    $weight = supplier_parse_weight($s['weight']) ?? 0.0;
                } else {
                    $weight = $row['weight_m'] > 0 ? (float)$row['weight_m']
                        : (supplier_calc_weight($edited['name'], $edited['size'])['value'] ?? 0.0);
                }

                if ($linkTo !== '') {
                    $findProduct->execute([$linkTo]);
                    if (!$findProduct->fetch()) {
                        throw new RuntimeException("товара с ID «{$linkTo}» нет");
                    }
                    $updCost->execute([$price, $linkTo]);
                    if ($weight > 0) $fillWeight->execute([$weight, $linkTo]);
                    $productId = $linkTo;
                    $res['linked']++;
                } else {
                    // Название и ID — по исправленным вручную полям
                    $productId = supplier_new_product_id($pdo, $supplier, $edited);
                    $maxSort->execute([$s['category']]);
                    $insProduct->execute([
                        $productId, $s['category'], supplier_product_label($edited),
                        $weight,
                        // Длину сайт берёт из мерности в названии, иначе — по правилам «Ручных корректировок»
                        null,
                        $price, (int)$maxSort->fetchColumn(),
                        // Значения для фильтров каталога на сайте (Параметр 1 / Параметр 2)
                        trim((string)($s['param1'] ?? '')) ?: null,
                        trim((string)($s['param2'] ?? '')) ?: null,
                    ]);
                    $res['created']++;
                }
                $dropOld->execute([$supplier, $productId, $row['key']]);
                $insLink->execute([$supplier, $row['key'], supplier_row_title($row), $productId, $price, $now]);
            }

            admin_setting_set($pdo, "price_tier_$supplier", (string)$tier);
            admin_setting_set($pdo, "tiers_$supplier", json_encode($data['tiers'], JSON_UNESCAPED_UNICODE));
            admin_setting_set($pdo, "sheet_categories_$supplier", json_encode($sheetCats, JSON_UNESCAPED_UNICODE));
            $pdo->commit();

            unset($_SESSION['supplier_import'][$supplier]);
            $result = $res;
            $step = 'done';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Ошибка сохранения: ' . $e->getMessage();
        }
    }
}

// ---- Данные для предпросмотра ----
if ($step === 'preview') {
    $rowsByKey = [];
    foreach ($data['rows'] as $r) $rowsByKey[$r['key']] = $r;

    $linked = [];
    $counts = ['changed' => 0, 'same' => 0, 'noprice' => 0, 'missing' => 0];
    $linkedKeys = [];
    foreach (load_links($pdo, $supplier) as $l) {
        $row = $rowsByKey[$l['supplier_key']] ?? null;
        $linkedKeys[$l['supplier_key']] = true;
        $price = $row ? supplier_row_price($row, $tier) : null;
        if (!$row) $status = 'missing';
        elseif ($price === null) $status = 'noprice';
        elseif ($price !== (int)$l['cost_price_per_ton']) $status = 'changed';
        else $status = 'same';
        $counts[$status]++;
        $linked[] = $l + ['row' => $row, 'price' => $price, 'status' => $status];
    }
    // Сначала то, что требует внимания
    $order = ['changed' => 0, 'noprice' => 1, 'missing' => 2, 'same' => 3];
    usort($linked, fn($a, $b) => $order[$a['status']] <=> $order[$b['status']]);

    $sheets = [];
    foreach ($data['rows'] as $r) {
        if (isset($linkedKeys[$r['key']])) continue;
        $sheets[$r['sheet']][] = $r;
    }

    $categories = categories_tree($pdo->query(
        'SELECT slug, title, parent_slug, filter1_label, filter1_type, filter1_values, filter2_label, filter2_type, filter2_values
         FROM catalog_categories ORDER BY sort_order, title'
    )->fetchAll());

    // Фильтры категорий — так же, как их понимает каталог на сайте (buildFilter в Catalog.tsx):
    // нужны название, тип range/enum и значения через запятую; range — «от-до».
    $catFilters = [];
    foreach ($categories as $c) {
        foreach ([1, 2] as $n) {
            $label = trim((string)$c["filter{$n}_label"]);
            $type = trim((string)$c["filter{$n}_type"]);
            $values = array_values(array_filter(array_map('trim', explode(',', (string)$c["filter{$n}_values"])), 'strlen'));
            if ($label === '' || !in_array($type, ['range', 'enum'], true) || !$values) continue;
            $catFilters[$c['slug']][] = ['n' => $n, 'label' => $label, 'type' => $type, 'values' => $values];
        }
    }
    $linkedIds = array_flip(array_column($linked, 'product_id'));
    $products = array_filter(
        $pdo->query('SELECT id, label FROM catalog_products ORDER BY label')->fetchAll(),
        fn($p) => !isset($linkedIds[$p['id']])
    );
}

/** Категории деревом: родительская, под ней её дочерние (с уровнем вложенности depth), и так далее. */
function categories_tree(array $rows): array {
    $bySlug = array_column($rows, null, 'slug');
    $children = [];
    foreach ($rows as $r) {
        $parent = $r['parent_slug'] !== null && isset($bySlug[$r['parent_slug']]) ? $r['parent_slug'] : '';
        $children[$parent][] = $r;
    }
    $out = [];
    $seen = [];
    $walk = function (string $parent, int $depth) use (&$walk, &$out, &$seen, $children) {
        foreach ($children[$parent] ?? [] as $r) {
            if (isset($seen[$r['slug']])) continue;
            $seen[$r['slug']] = true;
            $out[] = $r + ['depth' => $depth];
            $walk($r['slug'], $depth + 1);
        }
    };
    $walk('', 0);
    // Категории из «кольца» родителей (ошибка в данных) не теряем
    foreach ($rows as $r) {
        if (!isset($seen[$r['slug']])) $out[] = $r + ['depth' => 0];
    }
    return $out;
}

function rub(?int $v): string {
    return $v === null ? '—' : number_format($v, 0, '', ' ');
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Прайс поставщика — админка</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .upload-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 16px; max-width: 1000px; }
    .upload-box { border: 2px dashed rgba(255,255,255,.15); border-radius: 10px; padding: 24px 28px; text-align: center; display: flex; flex-direction: column; }
    .upload-box h2 { font-size: 16px; color: #fff; margin: 0 0 4px; }
    .upload-box .linked-count { color: #A7ACB8; font-size: 13px; margin: 0 0 16px; }
    .upload-box .spacer { flex: 1; }
    .variant { font-size: 11px; color: #6b7080; margin-top: 3px; }
    .section { margin-bottom: 32px; }
    .section h2 { font-size: 16px; color: #fff; margin: 0 0 6px; }
    .section .hint { color: #A7ACB8; margin: 0 0 14px; max-width: 760px; }
    tr.st-changed td.price-new { color: #4ade80; font-weight: 600; }
    tr.st-changed.down td.price-new { color: #F2611B; }
    tr.st-noprice td, tr.st-missing td { background: rgba(242,180,27,.06); }
    .status { font-size: 12px; white-space: nowrap; }
    .status.warn { color: #f2b41b; }
    .status.muted { color: #6b7080; }
    details.sheet { background: #15171C; border: 1px solid rgba(255,255,255,.08); border-radius: 10px; margin-bottom: 8px; }
    details.sheet > summary { padding: 12px 16px; cursor: pointer; display: flex; gap: 12px; align-items: center; }
    details.sheet > summary .count { color: #6b7080; font-size: 12px; }
    details.sheet > summary .picked { color: #F2611B; font-size: 12px; font-weight: 600; }
    details.sheet .sheet-tools { display: flex; gap: 12px; align-items: center; padding: 0 16px 12px; flex-wrap: wrap; }
    details.sheet .sheet-tools select { padding: 6px 10px; background: #0F1115; border: 1px solid rgba(255,255,255,.15); border-radius: 6px; color: #fff; }
    details.sheet table { border-radius: 0; border-left: none; border-right: none; border-bottom: none; }
    tr.disabled td { color: #6b7080; }
    tr.picked td { background: rgba(242,97,27,.07); }
    .wrap.wide { max-width: 1480px; }
    table.add-table td { padding: 6px 8px; vertical-align: top; }
    table.add-table td.num { padding-top: 12px; }
    table.add-table input.ed, table.add-table input.link-to, table.add-table select.row-cat {
      width: 100%; padding: 5px 7px; background: #0F1115; border: 1px solid rgba(255,255,255,.12);
      border-radius: 6px; color: #fff; font-size: 13px; font-family: inherit;
    }
    table.add-table td.f-name { min-width: 220px; }
    table.add-table td.f-size, table.add-table td.f-steel { width: 110px; }
    table.add-table td.f-gost { width: 120px; }
    table.add-table td.f-cat { width: 200px; }
    table.add-table td.f-filters { width: 170px; }
    .flt { display: flex; flex-direction: column; gap: 2px; margin-bottom: 4px; }
    .flt span { font-size: 10px; color: #6b7080; text-transform: uppercase; letter-spacing: .03em; }
    .flt select, .flt input { width: 100%; padding: 4px 6px; background: #0F1115; border: 1px solid rgba(255,255,255,.12); border-radius: 6px; color: #fff; font-size: 12px; }
    .flt.unset select, .flt.unset input { border-color: #f2b41b; }
    .no-filters { font-size: 11px; color: #6b7080; }
    table.add-table td.f-link { width: 150px; }
    table.add-table td.f-weight { width: 130px; text-align: right; white-space: nowrap; }
    table.add-table td.f-weight .w-unit { margin-left: 4px; font-size: 11px; color: #6b7080; }
    table.add-table input.ed-w {
      width: 70px; padding: 5px 7px; background: #0F1115; border: 1px solid rgba(255,255,255,.12);
      border-radius: 6px; color: #fff; font-size: 13px; font-family: inherit; text-align: right;
    }
    td.f-weight.w-none input.ed-w, td.f-weight.w-diff input.ed-w { border-color: #f2b41b; }
    .w-note { margin-top: 3px; font-size: 11px; line-height: 1.3; color: #6b7080; white-space: normal; }
    td.f-weight.w-diff .w-note, td.f-weight.w-none .w-note { color: #f2b41b; }
    .w-note button { margin-top: 2px; padding: 2px 6px; font-size: 11px; background: transparent; color: #F2611B;
      border: 1px solid rgba(242,97,27,.5); border-radius: 4px; cursor: pointer; font-family: inherit; }
    table.add-table tr.disabled td { padding-top: 10px; padding-bottom: 10px; }
    .site-label { display: none; margin-top: 4px; font-size: 11px; color: #A7ACB8; }
    tr.picked .site-label { display: block; }
    tr.linked input.ed, tr.linked select.row-cat { opacity: .4; }
    details.linked-box > summary { cursor: pointer; list-style: none; padding: 14px 16px; background: #15171C; border: 1px solid rgba(255,255,255,.08); border-radius: 10px; }
    details.linked-box > summary h2 { margin: 0 0 4px; }
    details.linked-box > summary .hint { margin: 0; }
    details.linked-box > summary::-webkit-details-marker { display: none; }
    details.linked-box > summary h2::before { content: '▸ '; color: #6b7080; }
    details.linked-box[open] > summary h2::before { content: '▾ '; }
    details.linked-box > summary .hint { display: block; }
    details.linked-box > summary .toggle-hint { color: #F2611B; margin-left: 6px; }
    details.linked-box[open] > summary .toggle-hint { display: none; }
    details.linked-box[open] > summary { margin-bottom: 14px; }
    .sticky-bar { position: sticky; bottom: 0; background: #0F1115; border-top: 1px solid rgba(255,255,255,.08); padding: 14px 0; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
    .search { width: 320px; padding: 9px 12px; background: #0F1115; border: 1px solid rgba(255,255,255,.15); border-radius: 6px; color: #fff; }
    .stats { display: flex; gap: 18px; flex-wrap: wrap; color: #A7ACB8; margin-bottom: 14px; font-size: 13px; }
    .stats b { color: #fff; }
    .error { white-space: pre-line; }
  </style>
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>
<div class="wrap<?= $step === 'preview' ? ' wide' : '' ?>">
  <h1>Прайс поставщика<?= $step === 'upload' ? '' : ' · ' . h($supplierTitle) ?></h1>

  <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>

  <?php if ($step === 'upload'): ?>
    <p style="color:#A7ACB8;max-width:760px">
      Загрузите прайс поставщика как есть, без переделки (.xlsx, .xlsm или .xls). Закупка обновится только у товаров,
      привязанных к строкам этого прайса. Остальные строки на сайт не попадают — новые позиции вы отмечаете сами
      на следующем шаге. Товары от других поставщиков не затрагиваются.
    </p>
    <div class="upload-grid">
    <?php foreach (SUPPLIERS as $code => $title):
        $linkCount = $pdo->prepare('SELECT COUNT(*) FROM supplier_links WHERE supplier = ?');
        $linkCount->execute([$code]);
        $tiersFor = json_decode(admin_setting_get($pdo, "tiers_$code", '[]'), true)
            ?: ($code === 'spk' ? ['до 1 тн', 'от 1 тн до 3 тн', 'от 3 тн до 10 тн', 'свыше 10 тн'] : []);
        $tierFor = (int)admin_setting_get($pdo, "price_tier_$code", '0'); ?>
      <form method="post" enctype="multipart/form-data" class="upload-box">
        <?= csrf_field() ?>
        <input type="hidden" name="step" value="preview">
        <input type="hidden" name="supplier" value="<?= h($code) ?>">
        <h2>Прайс <?= h($title) ?></h2>
        <p class="linked-count">Привязано товаров: <b style="color:#fff"><?= (int)$linkCount->fetchColumn() ?></b></p>
        <?php if (count($tiersFor) > 1): ?>
          <div class="field-group" style="width:100%;max-width:320px;margin:0 auto 16px;text-align:left">
            <label>Какую цену считать закупкой</label>
            <select name="tier">
              <?php foreach ($tiersFor as $i => $t): ?>
                <option value="<?= $i ?>" <?= $i === $tierFor ? 'selected' : '' ?>><?= h($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
        <div class="spacer"></div>
        <input type="file" name="price_file" accept=".xlsx,.xlsm,.xls" required style="margin:0 auto 16px;display:block;color:#fff">
        <button type="submit" class="btn">Загрузить и посмотреть изменения</button>
      </form>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($step === 'preview'): ?>
    <div class="stats">
      <span>Файл: <b><?= h($data['file']) ?></b></span>
      <?php if ($data['date']): ?><span>Прайс от <b><?= h($data['date']) ?></b></span><?php endif; ?>
      <span>Строк в прайсе: <b><?= count($data['rows']) ?></b></span>
      <?php if (count($data['tiers']) > 1): ?>
      <form method="post" style="display:inline-flex;gap:8px;align-items:center">
        <?= csrf_field() ?>
        <input type="hidden" name="step" value="retier">
        <input type="hidden" name="supplier" value="<?= h($supplier) ?>">
        Закупка по колонке
        <select name="tier" onchange="this.form.submit()" style="padding:5px 8px;background:#0F1115;border:1px solid rgba(255,255,255,.15);border-radius:6px;color:#fff">
          <?php foreach ($data['tiers'] as $i => $t): ?>
            <option value="<?= $i ?>" <?= $i === $tier ? 'selected' : '' ?>><?= h($t) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php endif; ?>
    </div>

    <form method="post" id="confirm-form">
      <?= csrf_field() ?>
      <input type="hidden" name="step" value="confirm">
      <input type="hidden" name="supplier" value="<?= h($supplier) ?>">
      <input type="hidden" name="tier" value="<?= $tier ?>">
      <input type="hidden" name="selection" id="selection" value="[]">
      <input type="hidden" name="sheet_defaults" id="sheet_defaults" value="{}">

      <details class="section linked-box">
        <summary>
          <h2>Ваши товары от этого поставщика (<?= count($linked) ?>)</h2>
          <span class="hint">
            Изменится закупка: <b style="color:#fff"><?= $counts['changed'] ?></b>,
            без изменений: <?= $counts['same'] ?>,
            «по запросу» (закупка останется прежней): <?= $counts['noprice'] ?>,
            нет в этом прайсе: <?= $counts['missing'] ?>.
            <span class="toggle-hint">Нажмите, чтобы развернуть список</span>
          </span>
        </summary>
        <?php if (!$linked): ?>
          <div class="empty" style="padding:20px;text-align:left">Пока ни один товар не привязан к прайсу — отметьте нужные позиции ниже.</div>
        <?php else: ?>
        <table>
          <thead><tr>
            <th>Товар на сайте</th><th>Строка прайса</th>
            <th class="num">Закупка сейчас</th><th class="num">В прайсе</th><th class="num">Цена на сайте станет</th>
            <th></th><th title="Отвязать от прайса (товар останется на сайте)">Отвязать</th>
          </tr></thead>
          <tbody>
          <?php foreach ($linked as $l):
              $cost = (int)$l['cost_price_per_ton'];
              $newCost = $l['status'] === 'changed' ? $l['price'] : $cost; ?>
            <tr class="st-<?= $l['status'] ?><?= $l['status'] === 'changed' && $l['price'] < $cost ? ' down' : '' ?>">
              <td><a href="product_edit.php?id=<?= urlencode($l['product_id']) ?>"><?= h($l['label']) ?></a></td>
              <td style="color:#A7ACB8"><?= h($l['row'] ? supplier_row_title($l['row']) : $l['supplier_label']) ?></td>
              <td class="num"><?= rub($cost) ?></td>
              <td class="num price-new"><?= $l['row'] ? ($l['price'] !== null ? rub($l['price']) : h($l['row']['price_text'] ?: '—')) : '—' ?></td>
              <td class="num"><?= rub($newCost + (int)$l['markup_per_ton']) ?></td>
              <td>
                <?php if ($l['status'] === 'changed'): ?>
                  <span class="status"><?= $l['price'] > $cost ? '▲ +' : '▼ ' ?><?= rub($l['price'] - $cost) ?></span>
                <?php elseif ($l['status'] === 'noprice'): ?>
                  <span class="status warn">по запросу — закупка не меняется</span>
                <?php elseif ($l['status'] === 'missing'): ?>
                  <span class="status warn">нет в прайсе</span>
                <?php else: ?>
                  <span class="status muted">без изменений</span>
                <?php endif; ?>
              </td>
              <td style="text-align:center"><input type="checkbox" name="unlink[]" value="<?= (int)$l['link_id'] ?>"></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </details>

      <div class="section">
        <h2>Добавить позиции из прайса</h2>
        <p class="hint">
          Отметьте то, что продаёте. Наименование, мерность, марку и ГОСТ можно поправить прямо в строке —
          из них собирается название товара на сайте (правка сама отмечает строку). Длину можно дописать в конец
          мерности («20Б1 12м»), иначе у товара будет 6 м. Категория по умолчанию — категория группы, у любой строки
          её можно сменить. Если такой товар у вас уже есть, впишите его ID в «или привязать к товару» — тогда новый
          не создаётся. «Фильтры на сайте» — значения для фильтров категории (диаметр, марка и т.п.), подставляются
          сами по наименованию, мерности и марке, проверьте их.
          «Вес 1 пм» (у листов — вес 1 м²) тоже можно поправить. Для арматуры, круга, квадрата, полосы, уголка,
          труб и листа вес сверяется с ГОСТом, для балок и швеллеров — с таблицей сортамента: если в прайсе веса нет,
          подставляется расчётный, если отличается больше чем на 5% — поле подсвечено и расчётный можно взять кнопкой.
          Позиции «по запросу» и с ценой не за тонну отметить нельзя.
        </p>
        <input type="search" class="search" id="search" placeholder="Поиск по прайсу: например, «арматура 12 а500»">
        <div style="height:12px"></div>

        <select id="cat-template" hidden>
          <option value="">— выберите —</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= h($c['slug']) ?>"><?= str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $c['depth']) ?><?= $c['depth'] ? '└ ' : '' ?><?= h($c['title']) ?></option>
          <?php endforeach; ?>
        </select>

        <?php foreach ($sheets as $sheetName => $rows): ?>
          <details class="sheet" data-sheet-name="<?= h($sheetName) ?>">
            <summary>
              <b><?= h($sheetName) ?></b>
              <span class="count"><?= count($rows) ?> поз.</span>
              <span class="picked"></span>
            </summary>
            <div class="sheet-tools">
              <label style="color:#A7ACB8;font-size:12px">Категория по умолчанию для всей группы:</label>
              <select class="sheet-cat" data-default="<?= h($sheetCats[$sheetName] ?? '') ?>"></select>
              <button type="button" class="btn secondary small pick-all">Отметить все с ценой</button>
              <button type="button" class="btn secondary small unpick-all">Снять отметки</button>
            </div>
            <table class="add-table">
              <thead><tr>
                <th style="width:30px"></th><th>Наименование</th><th>Мерность</th><th>Марка</th><th>ГОСТ</th>
                <th>Категория на сайте</th><th>Фильтры на сайте</th>
                <th class="num" title="Для листов — вес 1 м²">Вес 1 пм, кг</th><th class="num"><?= h($data['tiers'][$tier] ?? 'Цена') ?></th>
                <th>или привязать к товару</th>
              </tr></thead>
              <tbody>
              <?php foreach ($rows as $r):
                  $ok = supplier_row_addable($r, $tier);
                  $price = supplier_row_price($r, $tier); ?>
                <tr class="<?= $ok ? 'addable' : 'disabled' ?>" data-key="<?= h($r['key']) ?>"
                    data-name="<?= h($r['name']) ?>" data-size="<?= h($r['size']) ?>"
                    data-steel="<?= h($r['steel']) ?>" data-gost="<?= h($r['gost']) ?>"
                    data-weight="<?= $r['weight_m'] > 0 ? h((string)round($r['weight_m'], 3)) : '' ?>"
                    data-text="<?= h(mb_strtolower($r['name'] . ' ' . $r['size'] . ' ' . $r['steel'] . ' ' . $r['gost'] . ' ' . ($r['variant'] ?? ''))) ?>">
                  <td><input type="checkbox" class="pick" <?= $ok ? '' : 'disabled' ?>></td>
                  <td class="f-name"><?= h($r['name']) ?></td>
                  <td class="f-size"><?= h($r['size']) ?></td>
                  <td class="f-steel"><?= h($r['steel']) ?></td>
                  <td class="f-gost"><?= h($r['gost']) ?></td>
                  <td class="f-cat"></td>
                  <td class="f-filters"></td>
                  <td class="num f-weight"><?= $r['weight_m'] > 0 ? h(rtrim(rtrim(number_format($r['weight_m'], 3, '.', ''), '0'), '.')) : '—' ?></td>
                  <td class="num"><?php
                    if ($price !== null && $r['unit'] !== 'Т' && $r['unit'] !== '') echo rub($price) . ' ₽/' . h(mb_strtolower($r['unit']));
                    elseif ($price !== null) echo rub($price);
                    else echo '<span class="status muted">' . h($r['price_text'] ?: '—') . '</span>';
                    // Ценовая категория поставщика (Премиум/Стандарт/Дисконт): в название не идёт,
                    // показана только чтобы отличать одинаковые строки с разной ценой
                    if (($r['variant'] ?? '') !== '') echo '<div class="variant">' . h($r['variant']) . '</div>';
                  ?></td>
                  <td class="f-link"></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </details>
        <?php endforeach; ?>

        <datalist id="products-list">
          <?php foreach ($products as $p): ?>
            <option value="<?= h($p['id']) ?>"><?= h($p['label']) ?></option>
          <?php endforeach; ?>
        </datalist>
      </div>

      <div class="sticky-bar">
        <button type="submit" class="btn">Подтвердить и сохранить</button>
        <a href="supplier_price.php" class="btn secondary">Отмена</a>
        <span id="summary" style="color:#A7ACB8"></span>
      </div>
    </form>

    <script>
    (function () {
      var form = document.getElementById('confirm-form');
      var summary = document.getElementById('summary');
      var catTemplate = document.getElementById('cat-template');
      var changed = <?= (int)$counts['changed'] ?>;
      var FIELDS = ['name', 'size', 'steel', 'gost'];
      var CAT_FILTERS = <?= json_encode($catFilters, JSON_UNESCAPED_UNICODE) ?>;

      // Сравнение без регистра, пробелов и с латиницей, похожей на кириллицу (A500C = А500С)
      function norm(s) {
        var lat = 'aceopxkmtbh', cyr = 'асеорхкмтвн';
        return String(s).toLowerCase().replace(/\s+/g, '').replace(/[aceopxkmtbh]/g, function (ch) { return cyr[lat.indexOf(ch)]; });
      }

      // Догадка значения фильтра по полям строки: для списка — значение, совпавшее
      // с мерностью, маркой, ГОСТом или словом наименования; для диапазона — первое число наименования
      function guessParam(flt, f) {
        if (flt.type === 'range') {
          // Первое число наименования, а если там чисел нет — мерности («Труба э/с» + «57х3,0» → 57)
          var m = f.name.match(/\d+(?:[.,]\d+)?/) || f.size.match(/\d+(?:[.,]\d+)?/);
          return m ? m[0].replace(',', '.') : '';
        }
        var cands = [f.size, f.steel, f.gost, f.name];
        [f.name, f.size, f.steel].forEach(function (v) { cands = cands.concat(v.split(/[\s,]+/)); });
        cands = cands.filter(Boolean).map(norm);
        for (var i = 0; i < flt.values.length; i++) {
          if (cands.indexOf(norm(flt.values[i])) !== -1) return flt.values[i];
        }
        return '';
      }

      function renderFilters(tr) {
        var td = tr.querySelector('td.f-filters');
        if (!td) return;
        var sel = tr.querySelector('select.row-cat');
        var filters = CAT_FILTERS[sel ? sel.value : ''] || [];
        var f = fieldsOf(tr);
        // Ручной выбор сохраняем, пока категория та же
        var keep = {};
        if (td.getAttribute('data-cat') === (sel ? sel.value : '')) {
          td.querySelectorAll('[data-param][data-touched]').forEach(function (c) { keep[c.getAttribute('data-param')] = c.value; });
        }
        td.setAttribute('data-cat', sel ? sel.value : '');
        td.textContent = '';
        if (!filters.length) {
          if (sel && sel.value) { var n = document.createElement('div'); n.className = 'no-filters'; n.textContent = 'нет фильтров'; td.appendChild(n); }
          return;
        }
        filters.forEach(function (flt) {
          var box = document.createElement('label');
          box.className = 'flt';
          var cap = document.createElement('span');
          cap.textContent = flt.label;
          box.appendChild(cap);
          var ctl;
          var value = keep.hasOwnProperty(flt.n) ? keep[flt.n] : guessParam(flt, f);
          if (flt.type === 'enum') {
            ctl = document.createElement('select');
            ctl.appendChild(new Option('—', ''));
            flt.values.forEach(function (v) { ctl.appendChild(new Option(v, v)); });
          } else {
            ctl = document.createElement('input');
            ctl.inputMode = 'decimal';
            ctl.title = 'Число; диапазоны фильтра: ' + flt.values.join(', ');
          }
          ctl.setAttribute('data-param', flt.n);
          ctl.value = value;
          if (keep.hasOwnProperty(flt.n)) ctl.setAttribute('data-touched', '1');
          box.classList.toggle('unset', !ctl.value);
          box.appendChild(ctl);
          td.appendChild(box);
        });
      }

      function paramsOf(tr) {
        var out = { param1: '', param2: '', missing: 0 };
        tr.querySelectorAll('td.f-filters [data-param]').forEach(function (c) {
          out['param' + c.getAttribute('data-param')] = c.value.trim();
          if (!c.value.trim()) out.missing++;
        });
        return out;
      }

      // Та же логика, что supplier_product_label() в lib/supplier_price.php
      function labelFor(f) {
        var name = f.name.trim();
        var parts = [name];
        ['size', 'steel', 'gost'].forEach(function (k) {
          var v = f[k].trim();
          if (!v || name.toLowerCase().indexOf(v.toLowerCase()) !== -1) return;
          if (k === 'gost' && /^\d/.test(v)) v = 'ГОСТ ' + v;
          parts.push(v);
        });
        return parts.join(', ');
      }

      // Та же логика, что supplier_calc_weight() в lib/supplier_price.php: теоретический вес
      // 1 м (у листа — 1 м²) по ГОСТу из наименования и мерности
      var VGP_OUTER = { 6: 10.2, 8: 13.5, 10: 17, 15: 21.3, 20: 26.8, 25: 33.5, 32: 42.3, 40: 48, 50: 60,
                        65: 75.5, 80: 88.5, 90: 101.3, 100: 114, 125: 140, 150: 165 };
      var BEAM_KG_M = <?= json_encode(SUPPLIER_BEAM_KG_M, JSON_UNESCAPED_UNICODE) ?>;
      var CHANNEL_KG_M = <?= json_encode(SUPPLIER_CHANNEL_KG_M, JSON_UNESCAPED_UNICODE) ?>;

      function nameNumbers(name) {
        var s = String(name).toLowerCase().replace(/(\d),(\d)/g, '$1.$2').replace(/(\d)\s*[х×x*]\s*(?=\d)/g, '$1 ');
        var out = [];
        s.split(/[\s\/]+/).forEach(function (t) {
          var m = t.match(/^(?:d|ø|ф)?(\d+(?:\.\d+)?)(?:мм)?$/);
          if (m) out.push(parseFloat(m[1]));
        });
        return out;
      }

      function calcWeight(name, size) {
        var n = String(name).trim().toLowerCase();
        if (!n || /рифл|пвл|просечн|некондиц|сетка|металлолом/.test(n)) return null;
        var num = nameNumbers(name + ' ' + size);
        var a, b, t, d, D, m, code, s;
        function fmt(v) { return v.toFixed(2).replace('.', ',').replace(/0+$/, '').replace(/,$/, ''); }
        // Округление до 0,001 как round() в PHP: 2,7475 → 2,748 (а не 2,747 из-за двоичной записи)
        function res(kg, unit, how) { return kg > 0 ? { value: Math.round(parseFloat((kg * 1000).toPrecision(12))) / 1000, unit: unit, how: how } : null; }
        // Двутавр и швеллер — по таблицам сортамента (они же в lib/supplier_price.php)
        if (/^(балка|двутавр)/.test(n)) {
          var srcs = [name, size];
          for (var i = 0; i < srcs.length; i++) {
            s = String(srcs[i]).toLowerCase().replace(/,/g, '.');
            m = s.match(/(?:^|[^\d.])(\d+)\s*([бшкм])(\d?)(?![а-яё\d])/);
            if (m) {
              if (m[2] !== 'м' && m[3] === '') return null;
              code = m[1] + m[2].toUpperCase() + m[3];
              return BEAM_KG_M[code] ? res(BEAM_KG_M[code], 'кг/м', 'двутавр ' + code + ': по сортаменту') : null;
            }
            var plain = nameNumbers(srcs[i]);
            if (plain.length) {
              code = fmt(plain[0]);
              return BEAM_KG_M[code] ? res(BEAM_KG_M[code], 'кг/м', 'двутавр ' + code + ' (ГОСТ 8239): по сортаменту') : null;
            }
          }
          return null;
        }
        if (n.indexOf('швеллер') === 0) {
          if (n.indexOf('гнут') !== -1) return null;
          s = (name + ' ' + size).toLowerCase().replace(/,/g, '.');
          m = s.match(/(?:^|[^\d.])(\d+(?:\.\d+)?)\s*(а?)\s*([пу])(?![а-яё])/);
          if (!m) return null;
          code = m[1] + m[2];
          return CHANNEL_KG_M[code] ? res(CHANNEL_KG_M[code], 'кг/м', 'швеллер ' + code + m[3].toUpperCase() + ': по сортаменту ГОСТ 8240') : null;
        }
        // Полки уголка и стенки профильной трубы: «a b t» или квадратные «a t»; полка не тоньше трёх толщин
        function abt() {
          if (num.length >= 3 && Math.min(num[0], num[1]) >= 3 * num[2]) return [num[0], num[1], num[2]];
          if (num.length >= 2 && num[0] >= 3 * num[1]) return [num[0], num[0], num[1]];
          return null;
        }
        if (/^(арматура|круг|проволока|катанка)/.test(n)) {
          if (!num.length) return null;
          d = num[0];
          return res(0.006165 * d * d, 'кг/м', 'круглый ⌀' + fmt(d) + ': 0,006165 × d²');
        }
        if (n.indexOf('квадрат') === 0) {
          if (!num.length) return null;
          a = num[0];
          return res(0.00785 * a * a, 'кг/м', 'квадрат ' + fmt(a) + ': 0,00785 × a²');
        }
        if (/^(полоса|лента)/.test(n)) {
          t = num.length > 0 ? num[0] : null;
          b = num.length > 1 ? num[1] : null;
          if (t === null || b === null) return null;
          return res(0.00785 * t * b, 'кг/м', 'полоса ' + fmt(t) + '×' + fmt(b) + ': 0,00785 × t × b');
        }
        if (n.indexOf('угол') === 0) {
          m = abt();
          if (!m) return null;
          a = m[0]; b = m[1]; t = m[2];
          return res(0.00785 * t * (a + b - t), 'кг/м', 'уголок ' + fmt(a) + '×' + fmt(b) + '×' + fmt(t) + ': 0,00785 × t × (a + b − t)');
        }
        if (n.indexOf('труба') === 0 || /^профиль\s+замкн/.test(n)) {
          if (n.indexOf('профил') !== -1) {
            m = abt();
            if (!m) return null;
            a = m[0]; b = m[1]; t = m[2];
            return res(0.0157 * t * (a + b - 2.86 * t), 'кг/м', 'профильная ' + fmt(a) + '×' + fmt(b) + '×' + fmt(t) + ': 0,0157 × t × (a + b − 2,86t)');
          }
          var full = (name + ' ' + size).toLowerCase();
          if (n.indexOf('вгп') !== -1 || /\dду|ду\s*\d/.test(full)) {
            var dn = null;
            t = null;
            m = full.match(/(\d+)\s*ду|ду\s*(\d+)/);
            if (m) {
              dn = parseInt(m[1] || m[2], 10);
              var rest = nameNumbers(full.replace(/(\d+)\s*ду|ду\s*(\d+)/g, ' '));
              t = rest.length ? rest[0] : null;
            } else if (num.length >= 2 && Number.isInteger(num[0]) && VGP_OUTER[num[0]]) {
              dn = num[0];
              t = num[1];
            }
            if (dn !== null && VGP_OUTER[dn] && t !== null) {
              D = VGP_OUTER[dn];
              if (D <= 2.5 * t) return null;
              return res(0.02466 * t * (D - t), 'кг/м', 'ВГП Ду' + dn + ' (⌀' + fmt(D) + ') × ' + fmt(t) + ': 0,02466 × t × (D − t)');
            }
            if (dn !== null) return null;
          }
          if (num.length >= 2 && num[0] > 2.5 * num[1]) {
            D = num[0]; t = num[1];
            return res(0.02466 * t * (D - t), 'кг/м', 'труба ⌀' + fmt(D) + '×' + fmt(t) + ': 0,02466 × t × (D − t)');
          }
          return null;
        }
        if (n.indexOf('лист') === 0) {
          if (!num.length) return null;
          t = num[0];
          return res(7.85 * t, 'кг/м²', 'лист ' + fmt(t) + ' мм: 7,85 × t');
        }
        return null;
      }

      function parseWeight(v) {
        v = String(v).replace(/\s/g, '').replace(',', '.');
        if (!/^\d+(\.\d+)?$|^\.\d+$/.test(v)) return null;
        var f = parseFloat(v);
        return f > 0 && f < 100000 ? f : null;
      }

      // Вес в строке: пока его не правили — из прайса, а если там пусто — расчётный по ГОСТу.
      // Подсветка, если веса нет или он расходится с ГОСТом больше чем на 5%.
      function renderWeight(tr) {
        var td = tr.querySelector('td.f-weight');
        var inp = td && td.querySelector('input.ed-w');
        if (!inp) return;
        var f = fieldsOf(tr);
        var calc = calcWeight(f.name, f.size);
        var fromFile = tr.getAttribute('data-weight');
        var touched = inp.hasAttribute('data-touched');
        if (!touched) inp.value = fromFile || (calc ? String(calc.value) : '');
        td.querySelector('.w-unit').textContent = /^\s*лист/i.test(f.name) ? 'кг/м²' : 'кг/м';
        var w = parseWeight(inp.value);
        var note = td.querySelector('.w-note');
        note.textContent = '';
        note.title = calc ? calc.how + ' = ' + String(calc.value).replace('.', ',') + ' ' + calc.unit : '';
        td.classList.remove('w-none', 'w-diff');
        var take = false;
        if (w === null) {
          td.classList.add('w-none');
          note.textContent = inp.value.trim() ? 'не число' : 'нет веса';
          take = !!calc;
        } else if (calc && Math.abs(w - calc.value) / calc.value > 0.05) {
          td.classList.add('w-diff');
          note.textContent = 'по ГОСТу ' + String(calc.value).replace('.', ',');
          take = true;
        } else if (calc) {
          note.textContent = !fromFile && !touched ? 'расчёт по ГОСТу' : 'ГОСТ ✓';
        }
        if (take) {
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'w-take';
          btn.textContent = 'взять ' + String(calc.value).replace('.', ',');
          note.appendChild(document.createElement('br'));
          note.appendChild(btn);
        }
      }

      function catSelect(value) {
        var s = catTemplate.cloneNode(true);
        s.removeAttribute('id');
        s.hidden = false;
        s.value = value || '';
        return s;
      }

      // Поля ввода в строках создаются, когда лист открывают впервые, —
      // так страница с сотнями строк остаётся лёгкой
      function buildSheet(d) {
        if (d.getAttribute('data-built')) return;
        d.setAttribute('data-built', '1');
        var sheetCat = d.querySelector('select.sheet-cat').value;
        d.querySelectorAll('tr.addable').forEach(function (tr) {
          FIELDS.forEach(function (f) {
            var td = tr.querySelector('td.f-' + f);
            var inp = document.createElement('input');
            inp.className = 'ed';
            inp.setAttribute('data-field', f);
            inp.value = tr.getAttribute('data-' + f);
            td.textContent = '';
            td.appendChild(inp);
          });
          var lbl = document.createElement('div');
          lbl.className = 'site-label';
          tr.querySelector('td.f-name').appendChild(lbl);
          var sel = catSelect(sheetCat);
          sel.className = 'row-cat';
          tr.querySelector('td.f-cat').appendChild(sel);
          var link = document.createElement('input');
          link.className = 'link-to';
          link.setAttribute('list', 'products-list');
          link.placeholder = 'ID товара';
          tr.querySelector('td.f-link').appendChild(link);
          var wtd = tr.querySelector('td.f-weight');
          wtd.textContent = '';
          var w = document.createElement('input');
          w.className = 'ed-w';
          w.inputMode = 'decimal';
          wtd.appendChild(w);
          wtd.appendChild(document.createElement('span')).className = 'w-unit';
          wtd.appendChild(document.createElement('div')).className = 'w-note';
          updateLabel(tr);
          renderFilters(tr);
          renderWeight(tr);
        });
      }

      function fieldsOf(tr) {
        var f = {};
        FIELDS.forEach(function (k) {
          var inp = tr.querySelector('input.ed[data-field="' + k + '"]');
          f[k] = inp ? inp.value : tr.getAttribute('data-' + k);
        });
        return f;
      }

      function updateLabel(tr) {
        var lbl = tr.querySelector('.site-label');
        if (!lbl) return;
        var link = tr.querySelector('input.link-to');
        var linkTo = link ? link.value.trim() : '';
        lbl.textContent = linkTo
          ? 'закупка запишется в товар ' + linkTo + ', его название не меняется, вес — только если у товара его нет'
          : 'на сайте: ' + (labelFor(fieldsOf(tr)) || '—');
        tr.classList.toggle('linked', !!linkTo);
      }

      function setPicked(tr, on) {
        tr.querySelector('input.pick').checked = on;
        tr.classList.toggle('picked', on);
      }

      function refresh() {
        var total = 0;
        document.querySelectorAll('details.sheet').forEach(function (d) {
          var n = d.querySelectorAll('input.pick:checked').length;
          total += n;
          d.querySelector('.picked').textContent = n ? 'отмечено: ' + n : '';
        });
        var unl = form.querySelectorAll('input[name="unlink[]"]:checked').length;
        summary.textContent = 'Изменится закупка: ' + changed + ' · добавить из прайса: ' + total + (unl ? ' · отвязать: ' + unl : '');
      }

      document.querySelectorAll('details.sheet').forEach(function (d) {
        var sheetSel = d.querySelector('select.sheet-cat');
        var fresh = catSelect(sheetSel.getAttribute('data-default'));
        fresh.className = 'sheet-cat';
        sheetSel.replaceWith(fresh);
        // Смена категории листа применяется к строкам, где категорию не меняли вручную
        fresh.addEventListener('change', function () {
          d.querySelectorAll('select.row-cat:not([data-touched])').forEach(function (s) {
            s.value = fresh.value;
            renderFilters(s.closest('tr'));
          });
        });
        d.addEventListener('toggle', function () { if (d.open) buildSheet(d); });
        d.querySelector('.pick-all').addEventListener('click', function () {
          d.querySelectorAll('tr.addable').forEach(function (tr) {
            if (tr.style.display !== 'none') setPicked(tr, true);
          });
          refresh();
        });
        d.querySelector('.unpick-all').addEventListener('click', function () {
          d.querySelectorAll('tr.addable').forEach(function (tr) { setPicked(tr, false); });
          refresh();
        });
      });

      form.addEventListener('change', function (e) {
        var tr = e.target.closest('tr.addable');
        if (tr && e.target.classList.contains('pick')) setPicked(tr, e.target.checked);
        if (tr && e.target.classList.contains('row-cat')) {
          e.target.setAttribute('data-touched', '1');
          setPicked(tr, true);
          renderFilters(tr);
        }
        if (tr && e.target.hasAttribute('data-param')) {
          e.target.setAttribute('data-touched', '1');
          e.target.closest('.flt').classList.toggle('unset', !e.target.value.trim());
          setPicked(tr, true);
        }
        refresh();
      });
      form.addEventListener('input', function (e) {
        var tr = e.target.closest('tr.addable');
        if (!tr) return;
        if (e.target.classList.contains('ed-w')) {
          e.target.setAttribute('data-touched', '1');
          setPicked(tr, true);
          renderWeight(tr);
          refresh();
          return;
        }
        if (e.target.hasAttribute('data-param')) {
          e.target.setAttribute('data-touched', '1');
          e.target.closest('.flt').classList.toggle('unset', !e.target.value.trim());
          setPicked(tr, true);
          refresh();
          return;
        }
        if (!(e.target.classList.contains('ed') || e.target.classList.contains('link-to'))) return;
        setPicked(tr, true);
        updateLabel(tr);
        // Подсказки фильтров и расчётный вес пересчитываются по исправленным полям (ручной выбор не трогаем)
        if (e.target.classList.contains('ed')) {
          renderFilters(tr);
          renderWeight(tr);
        }
        refresh();
      });

      form.addEventListener('click', function (e) {
        if (!e.target.classList.contains('w-take')) return;
        var tr = e.target.closest('tr.addable');
        var f = fieldsOf(tr);
        var calc = calcWeight(f.name, f.size);
        var inp = tr.querySelector('input.ed-w');
        if (!calc || !inp) return;
        inp.value = String(calc.value);
        inp.setAttribute('data-touched', '1');
        setPicked(tr, true);
        renderWeight(tr);
        refresh();
      });

      document.getElementById('search').addEventListener('input', function () {
        var words = this.value.toLowerCase().split(/\s+/).filter(Boolean);
        document.querySelectorAll('details.sheet').forEach(function (d) {
          var visible = 0;
          d.querySelectorAll('tr[data-key]').forEach(function (tr) {
            var text = tr.getAttribute('data-text');
            var ok = words.every(function (w) { return text.indexOf(w) !== -1; });
            tr.style.display = ok ? '' : 'none';
            if (ok) visible++;
          });
          d.style.display = visible ? '' : 'none';
          d.open = words.length > 0 && visible > 0;
        });
      });

      form.addEventListener('submit', function (e) {
        var selection = [];
        var problems = [];
        var sheetDefaults = {};
        var noFilter = 0;
        var noWeight = 0;
        document.querySelectorAll('details.sheet').forEach(function (d) {
          var sheet = d.getAttribute('data-sheet-name');
          var sheetCat = d.querySelector('select.sheet-cat').value;
          sheetDefaults[sheet] = sheetCat;
          d.querySelectorAll('tr.addable').forEach(function (tr) {
            if (!tr.querySelector('input.pick').checked) return;
            var f = fieldsOf(tr);
            var rowCat = tr.querySelector('select.row-cat');
            var link = tr.querySelector('input.link-to');
            var item = {
              key: tr.getAttribute('data-key'),
              category: rowCat ? rowCat.value : sheetCat,
              link_to: link ? link.value.trim() : '',
              name: f.name, size: f.size, steel: f.steel, gost: f.gost
            };
            var prm = paramsOf(tr);
            item.param1 = prm.param1;
            item.param2 = prm.param2;
            if (!item.link_to && prm.missing) noFilter++;
            var w = tr.querySelector('input.ed-w');
            if (w) {
              item.weight = w.value.trim();
              if (item.weight && parseWeight(item.weight) === null) problems.push(sheet + ': «' + (f.name || tr.getAttribute('data-name')) + '» — вес «' + item.weight + '» не число');
              else if (!item.link_to && !item.weight) noWeight++;
            }
            if (!item.link_to && !item.category) problems.push(sheet + ': «' + (f.name || tr.getAttribute('data-name')) + '» — не выбрана категория');
            if (!item.link_to && !f.name.trim()) problems.push(sheet + ': пустое наименование');
            selection.push(item);
          });
        });
        if (problems.length) {
          e.preventDefault();
          alert('Не сохранено:\n' + problems.slice(0, 15).join('\n') + (problems.length > 15 ? '\n…и ещё ' + (problems.length - 15) : ''));
          return;
        }
        if (noWeight && !confirm('У ' + noWeight + ' товар(ов) не указан вес 1 пм (подсвечен жёлтым). ' +
            'На сайте для них не посчитаются вес и цена за штуку. Сохранить так?')) {
          e.preventDefault();
          return;
        }
        if (noFilter && !confirm('У ' + noFilter + ' товар(ов) не заполнены значения фильтров (подсвечены жёлтым). ' +
            'На сайте они будут видны только при фильтре «Все». Сохранить так?')) {
          e.preventDefault();
          return;
        }
        document.getElementById('selection').value = JSON.stringify(selection);
        document.getElementById('sheet_defaults').value = JSON.stringify(sheetDefaults);
      });

      refresh();
    })();
    </script>
  <?php endif; ?>

  <?php if ($step === 'done' && $result): ?>
    <div class="card" style="max-width:420px">
      <div class="num">✓</div>
      <div class="label">Сохранено</div>
    </div>
    <p style="color:#A7ACB8;margin-top:16px">
      Обновлена закупка: <?= $result['updated'] ?>,
      создано товаров: <?= $result['created'] ?>,
      привязано к существующим: <?= $result['linked'] ?><?= $result['unlinked'] ? ', отвязано: ' . $result['unlinked'] : '' ?>.
      <?php if ($result['created']): ?><br>У новых товаров остаток 0 т — при необходимости поправьте его в «Товарах».<?php endif; ?>
    </p>
    <div style="display:flex;gap:10px;margin-top:16px">
      <a href="products.php" class="btn secondary">К товарам</a>
      <a href="supplier_price.php" class="btn secondary">Загрузить ещё файл</a>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
