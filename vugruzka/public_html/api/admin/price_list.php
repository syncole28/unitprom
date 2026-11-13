<?php
require __DIR__ . '/_auth.php';
admin_require_login();
require __DIR__ . '/../lib/xlsx.php';
require __DIR__ . '/../lib/catalog_columns.php';
require_once __DIR__ . '/../lib/adjustments.php';
$active = 'price';

$pdo = db_connect();
$step = 'upload';
$error = '';
$diff = null;
$payloadB64 = '';
$result = null;
$cleared = null;

function values_equal($a, $b): bool {
    // Пустая ячейка в загруженном файле значит «не указано» — не считаем это
    // изменением, иначе любой необязательный пустой столбец (та же длина,
    // если задана не для каждой позиции) завалит предпросмотр ложными «изменениями».
    if ($b === null || $b === '') return true;
    $a = $a ?? '';
    if (is_numeric($a) && is_numeric($b)) {
        return abs((float)$a - (float)$b) < 0.0001;
    }
    return trim((string)$a) === trim((string)$b);
}

function build_diff(array $incoming, array $dbByKey, string $keyField, string $labelField): array {
    $new = [];
    $changed = [];
    $unchanged = 0;
    $seenKeys = [];

    foreach ($incoming as $i => $row) {
        $key = (string)($row[$keyField] ?? '');
        if ($key === '') continue;
        $seenKeys[$key] = true;

        if (!isset($dbByKey[$key])) {
            $new[] = $row;
            continue;
        }

        $existing = $dbByKey[$key];
        $fieldChanges = [];
        foreach ($row as $field => $value) {
            if ($field === $keyField) continue;
            if (!values_equal($existing[$field] ?? null, $value)) {
                $fieldChanges[] = ['field' => $field, 'old' => $existing[$field] ?? '', 'new' => $value ?? ''];
            }
        }

        if ($fieldChanges) {
            $changed[] = ['key' => $key, 'label' => $row[$labelField] ?? $key, 'fields' => $fieldChanges];
        } else {
            $unchanged++;
        }
    }

    $missing = 0;
    foreach ($dbByKey as $key => $row) {
        if (!isset($seenKeys[$key])) $missing++;
    }

    return ['new' => $new, 'changed' => $changed, 'unchanged' => $unchanged, 'missing' => $missing];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'preview') {
    csrf_check();

    if (empty($_FILES['price_file']['tmp_name']) || $_FILES['price_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Файл не загружен';
    } else {
        try {
            $sheets = XlsxReader::readSheets($_FILES['price_file']['tmp_name'], ['Категории', 'Позиции']);

            $catFieldMap = category_field_map();
            $incomingCats = [];
            foreach ($sheets['Категории'] as $row) {
                $incomingCats[] = map_row($row, $catFieldMap);
            }

            $prodFieldMap = product_field_map();
            $incomingProds = [];
            foreach ($sheets['Позиции'] as $row) {
                $incomingProds[] = map_row($row, $prodFieldMap);
            }

            $dbCats = $pdo->query('SELECT * FROM catalog_categories')->fetchAll();
            $dbCatsBySlug = [];
            foreach ($dbCats as $c) { $dbCatsBySlug[$c['slug']] = $c; }

            $dbProds = $pdo->query('SELECT * FROM catalog_products')->fetchAll();
            $dbProdsById = [];
            // Длину сравниваем в том виде, в каком она в выгрузке: число, «немер 9–12» или пусто
            foreach ($dbProds as $p) { $p['length_m'] = adj_length_cell($p); $dbProdsById[$p['id']] = $p; }

            $diff = [
                'categories' => build_diff($incomingCats, $dbCatsBySlug, 'slug', 'title'),
                'products'   => build_diff($incomingProds, $dbProdsById, 'id', 'label'),
            ];

            $payloadB64 = base64_encode(json_encode(['categories' => $incomingCats, 'products' => $incomingProds], JSON_UNESCAPED_UNICODE));
            $step = 'preview';
        } catch (Throwable $e) {
            $error = 'Не получилось прочитать файл: ' . $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'clear_catalog') {
    csrf_check();
    if (mb_strtoupper(trim($_POST['confirm_word'] ?? '')) !== 'УДАЛИТЬ') {
        $error = 'Каталог не очищен: для подтверждения впишите слово УДАЛИТЬ';
    } else {
        try {
            // Привязки к прайсу поставщика и «Ходовые позиции» удаляются вместе
            // с товарами (ON DELETE CASCADE). Категории и наценки остаются.
            $cleared = $pdo->exec('DELETE FROM catalog_products');
        } catch (Throwable $e) {
            $error = 'Не удалось очистить каталог: ' . $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'confirm') {
    csrf_check();
    $payload = json_decode(base64_decode($_POST['payload'] ?? ''), true);

    if (!is_array($payload)) {
        $error = 'Не удалось прочитать данные формы — попробуйте загрузить файл заново';
        $step = 'upload';
    } else {
        try {
            $pdo->beginTransaction();
            $catCount  = upsert_categories($pdo, $payload['categories'] ?? []);
            $prodCount = upsert_products($pdo, $payload['products'] ?? []);
            $pdo->commit();
            $result = ['categories' => $catCount, 'products' => $prodCount];
            $step = 'done';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Ошибка сохранения: ' . $e->getMessage();
            $step = 'upload';
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Прайс-лист — админка</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .diff-table td.old { color: #e08585; text-decoration: line-through; opacity: .7; }
    .diff-table td.new { color: #4ade80; }
    .diff-section { margin-bottom: 28px; }
    .diff-section h3 { font-size: 14px; color: #A7ACB8; text-transform: uppercase; letter-spacing: .04em; margin: 0 0 10px; }
    .danger-zone { margin-top: 40px; border: 1px solid #8a2c2c; border-radius: 10px; padding: 18px 20px; max-width: 640px; }
    .danger-zone h3 { margin: 0 0 6px; font-size: 14px; color: #e08585; }
    .danger-zone p { margin: 0 0 14px; color: #A7ACB8; }
    .upload-box { border: 2px dashed rgba(255,255,255,.15); border-radius: 10px; padding: 32px; text-align: center; }
  </style>
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>
<div class="wrap">
  <h1>Прайс-лист</h1>

  <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
  <?php if ($cleared !== null): ?>
    <div class="card" style="border-color:#2d6a4f;background:rgba(52,199,89,.08);margin-bottom:16px;padding:12px 16px;color:#4ade80">
      Каталог очищен: удалено товаров — <?= (int)$cleared ?>. Категории и наценки остались.
    </div>
  <?php endif; ?>

  <?php if ($step === 'upload'): ?>
    <div class="toolbar">
      <p style="color:#A7ACB8;max-width:560px">
        Сначала скачайте текущий прайс (в нём уже будут все правки, сделанные вручную в
        админке) — обновите его у себя и загрузите обратно. Ничего не удалится: позиции,
        которых нет в файле, останутся как есть.
      </p>
      <a href="price_export.php" class="btn secondary">⬇ Скачать текущий прайс</a>
    </div>

    <form method="post" enctype="multipart/form-data" class="upload-box">
      <?= csrf_field() ?>
      <input type="hidden" name="step" value="preview">
      <p style="margin:0 0 16px;color:#A7ACB8">Выберите файл .xlsx с листами «Категории» и «Позиции»</p>
      <input type="file" name="price_file" accept=".xlsx,.xlsm" required style="margin:0 auto 16px;display:block;color:#fff">
      <button type="submit" class="btn">Загрузить и посмотреть изменения</button>
    </form>

    <?php $productCount = (int)$pdo->query('SELECT COUNT(*) FROM catalog_products')->fetchColumn(); ?>
    <div class="danger-zone">
      <h3>Очистить каталог</h3>
      <p>
        Удалит все товары сайта (сейчас: <?= $productCount ?>), их привязки к прайсу поставщика и блок
        «Ходовые позиции» на главной. Категории и наценки останутся. Отменить нельзя — если товары
        могут понадобиться, сначала скачайте текущий прайс.
      </p>
      <form method="post" onsubmit="return confirm('Удалить все товары каталога (<?= $productCount ?>)? Отменить будет нельзя.');"
            style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <?= csrf_field() ?>
        <input type="hidden" name="step" value="clear_catalog">
        <input name="confirm_word" placeholder="впишите УДАЛИТЬ" autocomplete="off" required
               style="padding:8px 12px;background:#0F1115;border:1px solid rgba(255,255,255,.15);border-radius:6px;color:#fff">
        <button type="submit" class="btn danger" <?= $productCount ? '' : 'disabled' ?>>Удалить все товары</button>
      </form>
    </div>
  <?php endif; ?>

  <?php if ($step === 'preview' && $diff): ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="step" value="confirm">
      <input type="hidden" name="payload" value="<?= h($payloadB64) ?>">

      <?php foreach (['categories' => 'Категории', 'products' => 'Товары'] as $key => $label): $d = $diff[$key]; ?>
        <div class="diff-section">
          <h3><?= $label ?> — новых: <?= count($d['new']) ?>, изменится: <?= count($d['changed']) ?>,
              без изменений: <?= $d['unchanged'] ?><?= $d['missing'] ? ", отсутствуют в файле: {$d['missing']} (останутся как есть)" : '' ?></h3>

          <?php if ($d['new']): ?>
            <table style="margin-bottom:10px">
              <thead><tr><th>Новое</th></tr></thead>
              <tbody>
                <?php foreach ($d['new'] as $row): ?>
                  <tr><td><?= h((string)($row['title'] ?? $row['label'] ?? '')) ?> <code style="color:#6b7080"><?= h((string)($row['slug'] ?? $row['id'] ?? '')) ?></code></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>

          <?php if ($d['changed']): ?>
            <table class="diff-table">
              <thead><tr><th>Позиция</th><th>Поле</th><th>Было</th><th>Станет</th></tr></thead>
              <tbody>
                <?php foreach ($d['changed'] as $c): foreach ($c['fields'] as $i => $f): ?>
                  <tr>
                    <td><?= $i === 0 ? h((string)$c['label']) : '' ?></td>
                    <td><?= h($f['field']) ?></td>
                    <td class="old"><?= h((string)$f['old']) ?></td>
                    <td class="new"><?= h((string)$f['new']) ?></td>
                  </tr>
                <?php endforeach; endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>

          <?php if (!$d['new'] && !$d['changed']): ?>
            <p style="color:#6b7080">Изменений нет</p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

      <div style="display:flex;gap:10px">
        <button type="submit" class="btn">Подтвердить и сохранить</button>
        <a href="price_list.php" class="btn secondary">Отмена</a>
      </div>
    </form>
  <?php endif; ?>

  <?php if ($step === 'done' && $result): ?>
    <div class="card" style="max-width:420px">
      <div class="num">✓</div>
      <div class="label">Готово</div>
    </div>
    <p style="color:#A7ACB8;margin-top:16px">
      Обработано категорий: <?= $result['categories'] ?>, товаров: <?= $result['products'] ?>.
    </p>
    <div style="display:flex;gap:10px;margin-top:16px">
      <a href="products.php" class="btn secondary">К товарам</a>
      <a href="price_list.php" class="btn secondary">Загрузить ещё файл</a>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
