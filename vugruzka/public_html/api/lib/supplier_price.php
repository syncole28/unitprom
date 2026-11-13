<?php
/**
 * Прайсы поставщиков «как есть»: разбор файла, ключ строки для привязки
 * к нашим товарам и заготовка нового товара из строки.
 *
 * СПК (spk.ru), .xlsm: по листу на группу товаров, на каждом листе шапка
 * «Наименование | Мерность | Марка стали | Гост | Вес 1 шт, кг | Вес 1 пм, кг | ЕИ |
 * Цена в зависимости от объема», под ней строка с подписями колонок цен
 * («до 1 тн», «от 1 тн до 3 тн», ...). Листы без такой шапки (оглавление) пропускаются.
 *
 * agrupp.com (Новосибирск), .xls из 1С: один лист, группы товаров идут заголовками
 * («Труба электросварная»), под каждым шапка «Наименование | Типоразмер, мм | НТД |
 * Доп. характеристика | Ценовая категория | ЕИ | Цена», таблица в две колонки.
 */

const SUPPLIERS = [
    'spk'    => 'СПК (spk.ru)',
    'agrupp' => 'agrupp.com (Новосибирск)',
];

/** Листы книги .xlsx/.xlsm или .xls — формат определяется по содержимому, не по расширению. */
function supplier_read_workbook(string $path): array {
    if (XlsReader::isXls($path)) return XlsReader::readAllSheetsRaw($path);
    return XlsxReader::readAllSheetsRaw($path);
}

/**
 * Возвращает ['tiers' => [подписи колонок цен], 'date' => 'дд.мм.гггг' | '',
 *             'rows' => [строка, ...]]
 * Строка: key, sheet, name, size, steel, gost, variant (у agrupp — ценовая категория:
 * в название товара не идёт, только различает одинаковые строки), weight_m, unit,
 * prices (по колонкам цен: число или null), price_text (текст вместо цены, например «по запросу»).
 */
function supplier_parse_file(string $supplier, string $path): array {
    $sheets = supplier_read_workbook($path);
    $parsed = $supplier === 'agrupp' ? supplier_parse_agrupp($sheets) : supplier_parse_spk($sheets);
    if (!$parsed['rows']) {
        throw new RuntimeException('Не нашёл в файле таблиц с колонкой «Наименование» — это точно прайс ' . (SUPPLIERS[$supplier] ?? 'поставщика') . '?');
    }
    $parsed['rows'] = supplier_assign_keys($parsed['rows']);
    $parsed['supplier'] = $supplier;
    return $parsed;
}

/** Ключи строк; одинаковые строки в прайсе (бывает) различаем порядковым номером. */
function supplier_assign_keys(array $rows): array {
    $keyCount = [];
    foreach ($rows as &$row) {
        $key = supplier_row_key($row);
        $keyCount[$key] = ($keyCount[$key] ?? 0) + 1;
        if ($keyCount[$key] > 1) $key .= '#' . $keyCount[$key];
        $row['key'] = $key;
    }
    unset($row);
    return $rows;
}

function supplier_parse_spk(array $sheets): array {
    $tiers = [];
    $date = '';
    $rows = [];

    foreach ($sheets as $sheetName => $sheetRows) {
        $headerIdx = null;
        foreach ($sheetRows as $i => $cells) {
            if (isset($cells[0]) && mb_strtolower(trim((string)$cells[0])) === 'наименование') {
                $headerIdx = $i;
                break;
            }
            if ($date === '') {
                foreach ($cells as $v) {
                    if (is_string($v) && preg_match('/^\d{2}\.\d{2}\.\d{4}$/', trim($v))) {
                        $date = trim($v);
                    }
                }
            }
        }
        if ($headerIdx === null) continue;

        $header = $sheetRows[$headerIdx];
        $col = [];
        foreach ($header as $c => $v) {
            $h = mb_strtolower(trim((string)$v));
            if ($h === 'наименование') $col['name'] = $c;
            elseif ($h === 'мерность') $col['size'] = $c;
            elseif (str_starts_with($h, 'марка')) $col['steel'] = $c;
            elseif ($h === 'гост') $col['gost'] = $c;
            elseif (str_contains($h, 'пм')) $col['weight_m'] = $c;
            elseif ($h === 'еи') $col['unit'] = $c;
            elseif (str_starts_with($h, 'цена')) $col['price'] = $c;
        }
        if (!isset($col['name'], $col['price'])) continue;

        // Подписи колонок цен — в строке под шапкой, начиная со столбца «Цена ...»
        $tierRow = $sheetRows[$headerIdx + 1] ?? [];
        $priceCols = [];
        $sheetTiers = [];
        for ($c = $col['price']; isset($tierRow[$c]) && trim((string)$tierRow[$c]) !== ''; $c++) {
            $priceCols[] = $c;
            $sheetTiers[] = trim((string)$tierRow[$c]);
        }
        if (!$priceCols) {
            $priceCols = [$col['price']];
            $sheetTiers = ['Цена'];
        }
        if (!$tiers) $tiers = $sheetTiers;

        for ($i = $headerIdx + 2, $n = count($sheetRows); $i < $n; $i++) {
            $cells = $sheetRows[$i];
            $name = trim((string)($cells[$col['name']] ?? ''));
            if ($name === '') continue;

            $prices = [];
            $priceText = '';
            foreach ($priceCols as $c) {
                $v = $cells[$c] ?? null;
                if (is_int($v) || is_float($v)) {
                    $prices[] = $v > 0 ? (int)round($v) : null;
                } else {
                    $prices[] = null;
                    if ($priceText === '' && $v !== null && trim((string)$v) !== '') {
                        $priceText = trim((string)$v);
                    }
                }
            }

            $row = [
                'sheet'      => $sheetName,
                'name'       => $name,
                'size'       => supplier_cell_text($cells[$col['size'] ?? -1] ?? null),
                'steel'      => supplier_cell_text($cells[$col['steel'] ?? -1] ?? null),
                'gost'       => supplier_cell_text($cells[$col['gost'] ?? -1] ?? null),
                'weight_m'   => (float)($cells[$col['weight_m'] ?? -1] ?? 0),
                'unit'       => mb_strtoupper(supplier_cell_text($cells[$col['unit'] ?? -1] ?? null)),
                'prices'     => $prices,
                'price_text' => $priceText,
            ];

            // Сноски под таблицей («Подробную информацию уточняйте...») — не товар
            if ($row['size'] === '' && $row['steel'] === '' && $row['gost'] === '' && $row['unit'] === ''
                && $priceText === '' && !array_filter($prices)) {
                continue;
            }

            $rows[] = $row;
        }
    }

    return ['tiers' => $tiers, 'date' => $date, 'rows' => $rows];
}

/**
 * Прайс agrupp.com. Таблица может идти в несколько колонок: каждая ячейка «Наименование»
 * в строке шапки начинает свой блок столбцов. Группа (для разбивки в админке) — последний
 * заголовок над таблицей: строка, где заполнена одна ячейка.
 */
function supplier_parse_agrupp(array $sheets): array {
    $date = '';
    $rows = [];
    $tiers = [];
    foreach ($sheets as $sheetName => $sheetRows) {
        $blocks = [];
        $group = (string)$sheetName;
        // Строки таблицы копим по блокам и выдаём блок за блоком (сначала левая колонка
        // целиком, потом правая) — так размеры идут по порядку, как читает человек
        $pending = [];
        $flush = function () use (&$pending, &$rows) {
            ksort($pending);
            foreach ($pending as $list) foreach ($list as $r) $rows[] = $r;
            $pending = [];
        };
        foreach ($sheetRows as $cells) {
            $filled = array_filter($cells, fn($v) => $v !== null && trim((string)$v) !== '');
            if (!$filled) continue;

            if ($date === '') {
                foreach ($filled as $v) {
                    if (is_string($v) && preg_match('/\b(\d{2}\.\d{2}\.\d{4})\b/u', $v, $m)) { $date = $m[1]; break; }
                }
            }

            $nameCols = [];
            foreach ($filled as $c => $v) {
                if (is_string($v) && mb_strtolower(trim($v)) === 'наименование') $nameCols[] = $c;
            }
            if ($nameCols) {
                $flush();
                $blocks = [];
                foreach ($nameCols as $i => $start) {
                    $end = $nameCols[$i + 1] ?? PHP_INT_MAX;
                    $bl = ['name' => $start];
                    foreach ($filled as $c => $v) {
                        if ($c <= $start || $c >= $end) continue;
                        $h = mb_strtolower(trim((string)$v));
                        if (str_starts_with($h, 'типоразмер') || str_starts_with($h, 'размер')) $bl['size'] = $c;
                        elseif (str_starts_with($h, 'нтд') || str_starts_with($h, 'гост')) $bl['gost'] = $c;
                        elseif (str_starts_with($h, 'доп') || str_starts_with($h, 'марка')) $bl['steel'] = $c;
                        elseif (str_starts_with($h, 'ценовая')) $bl['variant'] = $c;
                        elseif ($h === 'еи' || str_starts_with($h, 'ед')) $bl['unit'] = $c;
                        elseif (str_starts_with($h, 'цена')) { $bl['price'] = $c; if (!$tiers) $tiers = [trim((string)$v)]; }
                    }
                    if (isset($bl['price'])) $blocks[] = $bl;
                }
                continue;
            }

            if (count($filled) === 1 && is_string(reset($filled))) {
                $flush();
                $group = trim(reset($filled));
                continue;
            }

            foreach ($blocks as $bi => $bl) {
                $name = supplier_cell_text($cells[$bl['name']] ?? null);
                if ($name === '') continue;
                $v = $cells[$bl['price']] ?? null;
                $price = (is_int($v) || is_float($v)) && $v > 0 ? (int)round($v) : null;
                $priceText = $price === null && $v !== null && !is_int($v) && !is_float($v) ? trim((string)$v) : '';
                $row = [
                    'sheet'      => $group,
                    'name'       => $name,
                    'size'       => supplier_norm_size(supplier_cell_text($cells[$bl['size'] ?? -1] ?? null)),
                    'steel'      => supplier_cell_text($cells[$bl['steel'] ?? -1] ?? null),
                    'gost'       => supplier_cell_text($cells[$bl['gost'] ?? -1] ?? null),
                    'variant'    => supplier_cell_text($cells[$bl['variant'] ?? -1] ?? null),
                    'weight_m'   => 0.0,
                    'unit'       => mb_strtoupper(supplier_cell_text($cells[$bl['unit'] ?? -1] ?? null)),
                    'prices'     => [$price],
                    'price_text' => $priceText,
                ];
                if ($row['size'] === '' && $row['gost'] === '' && $row['unit'] === '' && $price === null && $priceText === '') continue;
                $pending[$bi][] = supplier_agrupp_sheet_size($row);
            }
        }
        $flush();
    }
    return ['tiers' => $tiers ?: ['Цена'], 'date' => $date, 'rows' => $rows];
}

/** «  15 х  2,8» → «15х2,8», «d   4» → «d4», « 20Б1» → «20Б1». */
function supplier_norm_size(string $s): string {
    $s = preg_replace('/\s+/u', ' ', trim($s));
    $s = preg_replace('/\s*([х×xX*])\s*(?=\d)/u', '$1', $s);
    return preg_replace('/^d\s+(?=\d)/u', 'd', $s);
}

/**
 * Лист в формате «толщина х ширина х длина» в мм («1,5х1250х2500») переводим в вид, как у СПК
 * и как его понимает сайт: толщина — в наименование («Лист г/к 1,5»), размеры — в метрах
 * («1.25м 2.5м»). Иначе калькулятор на сайте не найдёт размеры листа и посчитает площадь неверно.
 */
function supplier_agrupp_sheet_size(array $row): array {
    if (!preg_match('/^лист/u', mb_strtolower($row['name']))) return $row;
    if (!preg_match('/^(\d+(?:[.,]\d+)?)х(\d{3,5})х(\d{3,5})$/u', $row['size'], $m)) return $row;
    $t = preg_replace('/[.,]0+$/', '', $m[1]);
    $mToM = fn(string $mm) => rtrim(rtrim(number_format((int)$mm / 1000, 3, '.', ''), '0'), '.') . 'м';
    $row['name'] = $row['name'] . ' ' . $t;
    $row['size'] = $mToM($m[2]) . ' ' . $mToM($m[3]);
    return $row;
}

function supplier_cell_text($v): string {
    if ($v === null) return '';
    if (is_float($v) && floor($v) == $v) $v = (int)$v;
    return preg_replace('/\s+/u', ' ', trim((string)$v));
}

/**
 * Ключ строки прайса: наименование + мерность + марка + ГОСТ (+ ценовая категория, если есть),
 * без учёта регистра и пробелов.
 */
function supplier_row_key(array $row): string {
    $parts = [$row['name'], $row['size'], $row['steel'], $row['gost']];
    if (($row['variant'] ?? '') !== '') $parts[] = $row['variant'];
    return mb_strtolower(implode('|', array_map(fn($p) => preg_replace('/\s+/u', ' ', trim((string)$p)), $parts)));
}

/** Цена строки по выбранной колонке (индекс в tiers) или null, если цены нет. */
function supplier_row_price(array $row, int $tier): ?int {
    return $row['prices'][$tier] ?? null;
}

/** Можно ли завести товар из строки: цена за тонну и число в выбранной колонке. */
function supplier_row_addable(array $row, int $tier): bool {
    return ($row['unit'] === 'Т' || $row['unit'] === '') && supplier_row_price($row, $tier) !== null;
}

/**
 * Длина из мерности: «12м» → 12.0, «20Б1 12м» → 12.0 (длину можно дописать в конец мерности);
 * «бухта», «1.25м рулон», «57х3,0» → null. У листа «1.25м 2.5м» — 2.5, но для листов сайт
 * всё равно берёт размеры из названия.
 */
function supplier_parse_length(string $size): ?float {
    if (preg_match('/(?:^|\s)(\d+(?:[.,]\d+)?)\s*м$/u', trim($size), $m)) {
        return (float)str_replace(',', '.', $m[1]);
    }
    return null;
}

/**
 * Название товара для сайта: наименование, затем мерность, марка стали и ГОСТ —
 * «Арматура 12, 12м, А500 С, ГОСТ 34028-16». Пустые части пропускаются, часть,
 * которая уже есть в наименовании, не повторяется. Та же логика — в JS предпросмотра
 * на странице «Прайс поставщика» (labelFor), их нужно менять вместе.
 */
function supplier_product_label(array $row): string {
    $name = trim($row['name']);
    $parts = [$name];
    foreach (['size', 'steel', 'gost'] as $field) {
        $v = trim((string)($row[$field] ?? ''));
        if ($v === '' || mb_stripos($name, $v) !== false) continue;
        if ($field === 'gost' && preg_match('/^\d/u', $v)) $v = 'ГОСТ ' . $v;
        $parts[] = $v;
    }
    return implode(', ', $parts);
}

/** Строка прайса для админки: название и, если есть, ценовая категория поставщика. */
function supplier_row_title(array $row): string {
    $label = supplier_product_label($row);
    return ($row['variant'] ?? '') !== '' ? $label . ' · ' . $row['variant'] : $label;
}

/** Строка прайса с ручными правками из формы (наименование, мерность, марка, ГОСТ). */
function supplier_apply_edits(array $row, array $edits): array {
    foreach (['name', 'size', 'steel', 'gost'] as $field) {
        if (isset($edits[$field]) && is_string($edits[$field])) {
            $row[$field] = preg_replace('/\s+/u', ' ', trim($edits[$field]));
        }
    }
    return $row;
}

function supplier_translit(string $s): string {
    static $map = [
        'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i',
        'й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t',
        'у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'',
        'э'=>'e','ю'=>'yu','я'=>'ya',
    ];
    $s = strtr(mb_strtolower($s), $map);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

/** Свободный ID нового товара: spk-armatura-8-a400-12m, при совпадении — с -2, -3... */
function supplier_new_product_id(PDO $pdo, string $supplier, array $row): string {
    $base = substr(supplier_translit($supplier . ' ' . $row['name'] . ' ' . $row['steel'] . ' ' . $row['size']), 0, 58);
    $base = rtrim($base, '-');
    $stmt = $pdo->prepare('SELECT 1 FROM catalog_products WHERE id = ?');
    $id = $base;
    for ($n = 2; ; $n++) {
        $stmt->execute([$id]);
        if (!$stmt->fetchColumn()) return $id;
        $id = $base . '-' . $n;
    }
}

function admin_setting_get(PDO $pdo, string $name, ?string $default = null): ?string {
    $stmt = $pdo->prepare('SELECT value FROM admin_settings WHERE name = ?');
    $stmt->execute([$name]);
    $v = $stmt->fetchColumn();
    return $v === false ? $default : $v;
}

function admin_setting_set(PDO $pdo, string $name, string $value): void {
    $stmt = $pdo->prepare('INSERT INTO admin_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
    $stmt->execute([$name, $value]);
}


/** Условный проход (Ду) → наружный диаметр трубы ВГП по ГОСТ 3262, мм. */
const SUPPLIER_VGP_OUTER = [
    6 => 10.2, 8 => 13.5, 10 => 17.0, 15 => 21.3, 20 => 26.8, 25 => 33.5, 32 => 42.3,
    40 => 48.0, 50 => 60.0, 65 => 75.5, 80 => 88.5, 90 => 101.3, 100 => 114.0, 125 => 140.0, 150 => 165.0,
];

/**
 * Масса 1 м двутавра, кг, по обозначению профиля. Б, Ш, К — ГОСТ 35087 (значения как в прайсе СПК),
 * М — ГОСТ 19425, номер без буквы — ГОСТ 8239.
 */
const SUPPLIER_BEAM_KG_M = [
    '10Б1' => 8.1, '16Б1' => 12.7, '18Б1' => 15.4, '20Б1' => 21.3, '20Б2' => 25.3, '25Б1' => 25.7, '25Б2' => 29.6,
    '30Б1' => 32.0, '30Б2' => 36.7, '35Б1' => 41.4, '35Б2' => 49.6, '40Б1' => 56.6, '40Б2' => 66.0, '45Б1' => 66.2,
    '45Б2' => 76.0, '50Б1' => 72.5, '50Б2' => 79.5, '55Б1' => 89.0, '60Б1' => 94.6, '60Б2' => 105.5, '70Б1' => 129.3,
    '20Ш1' => 30.6, '20Ш2' => 38.8, '25Ш1' => 44.1, '25Ш2' => 53.8, '30Ш1' => 56.8, '30Ш2' => 68.6, '35Ш1' => 65.3,
    '35Ш2' => 79.7, '40Ш1' => 88.6, '40Ш2' => 106.7, '45Ш1' => 123.5, '45Ш2' => 144.7, '50Ш1' => 114.2, '50Ш2' => 138.4,
    '60Ш1' => 137.0, '60Ш2' => 170.7, '70Ш1' => 166.0,
    '20К1' => 41.4, '20К2' => 49.9, '25К1' => 62.6, '25К2' => 72.4, '30К1' => 87.0, '30К2' => 94.0, '35К1' => 109.1,
    '35К2' => 136.5, '40К1' => 146.6, '40К2' => 171.7,
    '24М' => 38.3, '30М' => 50.2, '36М' => 57.9, '45М' => 77.6,
    '10' => 9.46, '12' => 11.5, '14' => 13.7, '16' => 15.9, '18' => 18.4, '20' => 21.0, '22' => 24.0, '24' => 27.3,
    '27' => 31.5, '30' => 36.5, '33' => 42.2, '36' => 48.6, '40' => 57.0, '45' => 66.5, '50' => 78.5, '55' => 92.6, '60' => 108.0,
];

/** Масса 1 м швеллера серий У и П по ГОСТ 8240 (у них она одинакова), кг, по номеру профиля. */
const SUPPLIER_CHANNEL_KG_M = [
    '5' => 4.84, '6.5' => 5.9, '8' => 7.05, '10' => 8.59, '12' => 10.4, '14' => 12.3, '16' => 14.2, '16а' => 15.3,
    '18' => 16.3, '18а' => 17.4, '20' => 18.4, '22' => 21.0, '24' => 24.0, '27' => 27.7, '30' => 31.8, '33' => 36.5,
    '36' => 41.9, '40' => 48.3,
];

/**
 * Числа из наименования по порядку: «Уголок 25х25х3» → [25, 25, 3], «Проволока d3.0 ВР-1» → [3.0].
 * Берутся только отдельные числа: марки стали («09Г2С»), «Zn100», «ВР-1» числами не считаются.
 */
function supplier_name_numbers(string $name): array {
    $s = mb_strtolower($name);
    $s = preg_replace('/(\d),(\d)/u', '$1.$2', $s);
    $s = preg_replace('/(\d)\s*[х×x*]\s*(?=\d)/u', '$1 ', $s);
    $out = [];
    foreach (preg_split('/[\s\/]+/u', $s) as $token) {
        if (preg_match('/^(?:d|ø|ф)?(\d+(?:\.\d+)?)(?:мм)?$/u', $token, $m)) $out[] = (float)$m[1];
    }
    return $out;
}

/**
 * Теоретический вес 1 м (у листа — 1 м²) по ГОСТу из наименования и мерности (размеры бывают
 * и там, и там: «Уголок 25 25 3» + «6м» у СПК, «Угол» + «140х140х10» у agrupp):
 * ['value' => кг, 'unit' => 'кг/м' | 'кг/м²', 'how' => 'как посчитано'], или null, если формулы
 * для такого проката нет (гнутый швеллер, рифлёный и просечно-вытяжной лист, сетка и т.п.).
 * Балки и швеллеры — не по формуле, а по таблицам сортамента ниже.
 * Та же логика — в JS предпросмотра на странице «Прайс поставщика» (calcWeight), менять вместе.
 */
function supplier_calc_weight(string $name, string $size = ''): ?array {
    $n = mb_strtolower(trim($name));
    if ($n === '' || preg_match('/рифл|пвл|просечн|некондиц|сетка|металлолом/u', $n)) return null;
    $num = supplier_name_numbers($name . ' ' . $size);
    $fmt = fn(float $v) => rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
    $res = function (float $kg, string $unit, string $how) {
        return $kg > 0 ? ['value' => round($kg, 3), 'unit' => $unit, 'how' => $how] : null;
    };

    // Двутавр: обозначение «20Б1», «30Ш2», «40К1», «24М» или номер без буквы — сначала в наименовании,
    // потом в мерности (у СПК «Балка 20Б1» + «12м», у agrupp «Двутавр» + «20Б1»)
    if (preg_match('/^(балка|двутавр)/u', $n)) {
        foreach ([$name, $size] as $i => $src) {
            $s = str_replace(',', '.', mb_strtolower($src));
            if (preg_match('/(?:^|[^\d.])(\d+)\s*([бшкм])(\d?)(?![а-яё\d])/u', $s, $m)) {
                if ($m[2] !== 'м' && $m[3] === '') return null; // «20Б» без номера — не понять какой
                $code = $m[1] . mb_strtoupper($m[2]) . $m[3];
                return isset(SUPPLIER_BEAM_KG_M[$code]) ? $res(SUPPLIER_BEAM_KG_M[$code], 'кг/м', 'двутавр ' . $code . ': по сортаменту') : null;
            }
            $plain = supplier_name_numbers($src);
            if ($plain) {
                $code = $fmt($plain[0]);
                return isset(SUPPLIER_BEAM_KG_M[$code]) ? $res(SUPPLIER_BEAM_KG_M[$code], 'кг/м', 'двутавр ' . $code . ' (ГОСТ 8239): по сортаменту') : null;
            }
        }
        return null;
    }
    // Швеллер У/П: «Швеллер 20 П», «Швеллер» + «16аУ»; гнутый — по своим таблицам, здесь не считаем
    if (str_starts_with($n, 'швеллер')) {
        if (str_contains($n, 'гнут')) return null;
        $s = str_replace(',', '.', mb_strtolower($name . ' ' . $size));
        if (!preg_match('/(?:^|[^\d.])(\d+(?:\.\d+)?)\s*(а?)\s*([пу])(?![а-яё])/u', $s, $m)) return null;
        $code = $m[1] . $m[2];
        return isset(SUPPLIER_CHANNEL_KG_M[$code]) ? $res(SUPPLIER_CHANNEL_KG_M[$code], 'кг/м', 'швеллер ' . $code . mb_strtoupper($m[3]) . ': по сортаменту ГОСТ 8240') : null;
    }

    if (preg_match('/^(арматура|круг|проволока|катанка)/u', $n)) {
        if (!$num) return null;
        $d = $num[0];
        return $res(0.006165 * $d * $d, 'кг/м', 'круглый ⌀' . $fmt($d) . ': 0,006165 × d²');
    }
    if (str_starts_with($n, 'квадрат')) {
        if (!$num) return null;
        $a = $num[0];
        return $res(0.00785 * $a * $a, 'кг/м', 'квадрат ' . $fmt($a) . ': 0,00785 × a²');
    }
    if (preg_match('/^(полоса|лента)/u', $n)) {
        // У ленты в наименовании только толщина, ширина (мм) — в мерности: «Лента ОЦ 0.45» + «137»
        $t = $num[0] ?? null;
        $b = $num[1] ?? null;
        if ($t === null || $b === null) return null;
        return $res(0.00785 * $t * $b, 'кг/м', 'полоса ' . $fmt($t) . '×' . $fmt($b) . ': 0,00785 × t × b');
    }
    if (str_starts_with($n, 'угол')) {
        // «Уголок 25 25 3» или равнополочный «Уголок 50 5»; полка не тоньше трёх толщин
        if (count($num) >= 3 && min($num[0], $num[1]) >= 3 * $num[2]) [$a, $b, $t] = $num;
        elseif (count($num) >= 2 && $num[0] >= 3 * $num[1]) [$a, $b, $t] = [$num[0], $num[0], $num[1]];
        else return null;
        return $res(0.00785 * $t * ($a + $b - $t), 'кг/м', 'уголок ' . $fmt($a) . '×' . $fmt($b) . '×' . $fmt($t) . ': 0,00785 × t × (a + b − t)');
    }
    if (str_starts_with($n, 'труба') || preg_match('/^профиль\s+замкн/u', $n)) {
        if (str_contains($n, 'профил')) {
            if (count($num) >= 3 && min($num[0], $num[1]) >= 3 * $num[2]) [$a, $b, $t] = $num;
            elseif (count($num) >= 2 && $num[0] >= 3 * $num[1]) [$a, $b, $t] = [$num[0], $num[0], $num[1]];
            else return null;
            return $res(0.0157 * $t * ($a + $b - 2.86 * $t), 'кг/м', 'профильная ' . $fmt($a) . '×' . $fmt($b) . '×' . $fmt($t) . ': 0,0157 × t × (a + b − 2,86t)');
        }
        // ВГП: «Труба ВГП 15ДУ 2.8», «Труба ВГП Ду 15 2.8», «Труба ВГП 20 2.8» — Ду в наружный диаметр по ГОСТ 3262
        $full = mb_strtolower($name . ' ' . $size);
        if (str_contains($n, 'вгп') || preg_match('/\dду|ду\s*\d/u', $full)) {
            $dn = null;
            if (preg_match('/(\d+)\s*ду|ду\s*(\d+)/u', $full, $m)) {
                $dn = (int)($m[1] !== '' ? $m[1] : $m[2]);
                $t = supplier_name_numbers(preg_replace('/(\d+)\s*ду|ду\s*(\d+)/u', ' ', $full))[0] ?? null;
            } elseif (count($num) >= 2 && isset(SUPPLIER_VGP_OUTER[(int)$num[0]]) && (float)(int)$num[0] === $num[0]) {
                [$dn, $t] = [(int)$num[0], $num[1]];
            } else {
                $t = null;
            }
            if ($dn !== null && isset(SUPPLIER_VGP_OUTER[$dn]) && $t !== null) {
                $D = SUPPLIER_VGP_OUTER[$dn];
                if ($D <= 2.5 * $t) return null;
                return $res(0.02466 * $t * ($D - $t), 'кг/м', 'ВГП Ду' . $dn . ' (⌀' . $fmt($D) . ') × ' . $fmt($t) . ': 0,02466 × t × (D − t)');
            }
            if ($dn !== null) return null;
        }
        // Круглая труба (электросварная, оцинкованная, бесшовная): «Труба Э/С 57 3.5»
        if (count($num) >= 2 && $num[0] > 2.5 * $num[1]) {
            [$D, $t] = $num;
            return $res(0.02466 * $t * ($D - $t), 'кг/м', 'труба ⌀' . $fmt($D) . '×' . $fmt($t) . ': 0,02466 × t × (D − t)');
        }
        return null;
    }
    if (str_starts_with($n, 'лист')) {
        if (!$num) return null;
        $t = $num[0];
        return $res(7.85 * $t, 'кг/м²', 'лист ' . $fmt($t) . ' мм: 7,85 × t');
    }
    return null;
}

/** Вес из поля формы («0,888», «1.5») или null, если поле пустое или там не число. */
function supplier_parse_weight($v): ?float {
    if (!is_string($v) && !is_int($v) && !is_float($v)) return null;
    $v = str_replace([',', ' '], ['.', ''], trim((string)$v));
    if ($v === '' || !is_numeric($v)) return null;
    $f = (float)$v;
    return $f > 0 && $f < 100000 ? $f : null;
}
