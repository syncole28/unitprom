<?php
/**
 * «Ручные корректировки»: поправка веса в % по категориям и длина товара.
 *
 * Длина товара, по старшинству:
 *   1. из названия (у СПК мерность «12м», «немер», «бухта», у листов размеры «1.25м 2.5м») — её никто не перебивает;
 *   2. вручную у товара (catalog_products.length_m или length_nemer);
 *   3. правило вкладки «Ручные корректировки» (таблица length_rules): категория + размер от/до;
 *   4. не задана — тогда на сайте нет цены за штуку, а калькулятор считает только метры и тонны.
 * Длину из пп. 2–3 сайт дописывает в конец названия («…, 12 м», «…, немерная 9–12 м»), в базе
 * название не меняется. Используется в catalog.php, «Товарах», карточке товара и на вкладке.
 */

const ADJ_SHEET_ROOT = 'listovoy';
// Немерные продаются штуками: в калькуляторе 1 шт считается по этой длине (меняется на вкладке)
const ADJ_NEMER_CALC_DEFAULT = 11.5;

/** «11.7» → «11,7». */
function adj_num(float $v): string {
    return rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
}

/** «9–12» из «9-12», «9 – 12», «9—12»; '' если не похоже на диапазон. */
function adj_norm_range(string $s): string {
    if (preg_match('/^\s*(\d+(?:[.,]\d+)?)\s*[-–—]\s*(\d+(?:[.,]\d+)?)\s*$/u', $s, $m)) {
        $a = (float)str_replace(',', '.', $m[1]);
        $b = (float)str_replace(',', '.', $m[2]);
        if ($a > 0 && $b > 0) return adj_num(min($a, $b)) . '–' . adj_num(max($a, $b));
    }
    return '';
}

/** Числа из названия (как для фильтров каталога): «Труба э/с 720х8» → [720, 8]. */
function adj_label_numbers(string $label): array {
    $s = mb_strtolower($label);
    $s = preg_replace('/(\d),(\d)/u', '$1.$2', $s);
    $s = preg_replace('/(\d)\s*[х×x*]\s*(?=\d)/u', '$1 ', $s);
    $out = [];
    foreach (preg_split('/[\s\/,;()]+/u', $s) as $token) {
        if (preg_match('/^(?:d|ø|ф)?(\d+(?:\.\d+)?)(?:мм)?$/u', $token, $m)) $out[] = (float)$m[1];
    }
    return $out;
}

/** Размер для условия правила — первое число названия, или null. */
function adj_label_size(string $label): ?float {
    $n = adj_label_numbers($label);
    return $n ? $n[0] : null;
}

/**
 * Длина, которая уже написана в названии, или null.
 * ['kind' => 'fixed'|'nemer'|'coil', 'm' => ?float, 'text' => строка для колонки «Цена за шт»]
 */
function adj_label_length(string $label, bool $isSheet): ?array {
    if (preg_match('/немер/iu', $label)) {
        $text = 'немерная';
        $range = '';
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*[-–—]\s*(\d+(?:[.,]\d+)?)\s*м(?![а-яёa-z²³0-9])/u', $label, $m)) {
            $range = adj_norm_range($m[1] . '-' . $m[2]);
            $text .= ' ' . $range . ' м';
        }
        return ['kind' => 'nemer', 'm' => null, 'text' => $text, 'range' => $range];
    }
    if (preg_match('/бухт|моток|мотк/iu', $label)) return ['kind' => 'coil', 'm' => null, 'text' => 'в бухте'];
    if (preg_match('/рулон/iu', $label)) return ['kind' => 'coil', 'm' => null, 'text' => 'в рулоне'];

    if ($isSheet) {
        // У листа длина — в размерах «1.25м 2.5м» / «1,5×6 м» (так же их читает сайт)
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*м\s*[x×хX*]?\s*(\d+(?:[.,]\d+)?)\s*м/u', $label, $m)) {
            $a = (float)str_replace(',', '.', $m[1]);
            $b = (float)str_replace(',', '.', $m[2]);
            if ($a > 0 && $b > 0) return ['kind' => 'fixed', 'm' => max($a, $b), 'text' => ''];
        }
        return null;
    }
    // «12м», «11.7 м» — отдельным словом, не «мм» и не «м²»
    if (preg_match_all('/(?<![\d.,])(\d+(?:[.,]\d+)?)\s*м(?![а-яёa-z²³0-9])/u', $label, $m)) {
        $v = (float)str_replace(',', '.', end($m[1]));
        if ($v > 0) return ['kind' => 'fixed', 'm' => $v, 'text' => ''];
    }
    return null;
}

/** Длина по значениям length_m / length_nemer (товара или правила), или null. */
function adj_stored_length($lengthM, $nemer): ?array {
    $nemer = trim((string)$nemer);
    if ($nemer !== '') {
        $range = adj_norm_range($nemer);
        return ['kind' => 'nemer', 'm' => null, 'text' => 'немерная' . ($range !== '' ? " $range м" : ''), 'range' => $range];
    }
    if ($lengthM !== null && $lengthM !== '' && (float)$lengthM > 0) {
        return ['kind' => 'fixed', 'm' => (float)$lengthM, 'text' => ''];
    }
    return null;
}

/** Расчётная длина 1 шт немерной позиции, м (настройка вкладки «Ручные корректировки»). */
function adj_nemer_calc_setting(PDO $pdo): float {
    try {
        $stmt = $pdo->prepare('SELECT value FROM admin_settings WHERE name = ?');
        $stmt->execute(['nemer_calc_m']);
        $v = (float)str_replace(',', '.', (string)$stmt->fetchColumn());
        return $v > 0 ? $v : ADJ_NEMER_CALC_DEFAULT;
    } catch (Throwable $e) {
        return ADJ_NEMER_CALC_DEFAULT;
    }
}

/**
 * Длина 1 шт немерной позиции для калькулятора: настройка (11,5 м), но не за пределами
 * диапазона позиции (у «немерная 4–8 м» — 8 м).
 */
function adj_nemer_piece_m(array $len, float $calc): float {
    if (!empty($len['range']) && preg_match('/^([\d,]+)–([\d,]+)$/u', $len['range'], $m)) {
        $a = (float)str_replace(',', '.', $m[1]);
        $b = (float)str_replace(',', '.', $m[2]);
        return max($a, min($b, $calc));
    }
    return $calc;
}

/** Подпись длины: «12 м», «немерная 9–12 м». */
function adj_length_caption(array $len): string {
    return $len['kind'] === 'fixed' ? adj_num($len['m']) . ' м' : $len['text'];
}

/** Родитель каждой категории: slug => parent_slug|null. */
function adj_parents(PDO $pdo): array {
    return array_column($pdo->query('SELECT slug, parent_slug FROM catalog_categories')->fetchAll(), 'parent_slug', 'slug');
}

/** Категория и её предки, начиная с самой категории. */
function adj_chain(string $slug, array $parents): array {
    $chain = [];
    for ($s = $slug; $s !== null && $s !== '' && !in_array($s, $chain, true); $s = $parents[$s] ?? null) {
        $chain[] = $s;
    }
    return $chain;
}

function adj_is_sheet(string $slug, array $parents): bool {
    return in_array(ADJ_SHEET_ROOT, adj_chain($slug, $parents), true);
}

/**
 * Правила длины в порядке проверки для каждой категории: сначала правила самой категории,
 * потом родителя; среди правил одной категории — сначала с условием по размеру.
 * Возвращает [category_slug => [rule, ...]] (в правилах только своей категории).
 */
function adj_load_rules(PDO $pdo): array {
    try {
        $rows = $pdo->query('SELECT * FROM length_rules ORDER BY id')->fetchAll();
    } catch (Throwable $e) {
        return []; // таблицы ещё нет — миграция не выполнена
    }
    $by = [];
    foreach ($rows as $r) $by[$r['category_slug']][] = $r;
    foreach ($by as &$list) {
        usort($list, function ($a, $b) {
            $ca = ($a['size_from'] !== null || $a['size_to'] !== null) ? 0 : 1;
            $cb = ($b['size_from'] !== null || $b['size_to'] !== null) ? 0 : 1;
            return $ca <=> $cb ?: (int)$a['id'] <=> (int)$b['id'];
        });
    }
    return $by;
}

function adj_rule_matches(array $rule, ?float $size): bool {
    $from = $rule['size_from'];
    $to = $rule['size_to'];
    if ($from === null && $to === null) return true;
    if ($size === null) return false;
    if ($from !== null && $size < (float)$from - 1e-9) return false;
    if ($to !== null && $size > (float)$to + 1e-9) return false;
    return true;
}

/** Первое подходящее правило для товара, или null. */
function adj_find_rule(string $label, string $slug, array $rulesBySlug, array $parents): ?array {
    $size = adj_label_size($label);
    foreach (adj_chain($slug, $parents) as $s) {
        foreach ($rulesBySlug[$s] ?? [] as $rule) {
            if (adj_rule_matches($rule, $size)) return $rule;
        }
    }
    return null;
}

/**
 * Итоговая длина товара.
 * ['kind' => 'fixed'|'nemer'|'coil'|'none', 'm' => ?float, 'text' => string,
 *  'source' => 'label'|'manual'|'rule'|'', 'rule' => ?array, 'append' => дописать к названию]
 */
function adj_resolve_length(array $p, array $rulesBySlug, array $parents): array {
    $label = (string)$p['label'];
    $slug = (string)$p['category_slug'];
    $fromLabel = adj_label_length($label, adj_is_sheet($slug, $parents));
    if ($fromLabel) return $fromLabel + ['source' => 'label', 'rule' => null, 'append' => ''];

    $manual = adj_stored_length($p['length_m'] ?? null, $p['length_nemer'] ?? '');
    if ($manual) return $manual + ['source' => 'manual', 'rule' => null, 'append' => ', ' . adj_length_caption($manual)];

    $rule = adj_find_rule($label, $slug, $rulesBySlug, $parents);
    if ($rule && ($len = adj_stored_length($rule['length_m'], $rule['length_nemer']))) {
        return $len + ['source' => 'rule', 'rule' => $rule, 'append' => ', ' . adj_length_caption($len)];
    }
    return ['kind' => 'none', 'm' => null, 'text' => '', 'source' => '', 'rule' => null, 'append' => ''];
}

/** Поправка веса, %, для каждой категории с учётом родителя: slug => float. */
function adj_weight_pcts(PDO $pdo): array {
    try {
        $rows = $pdo->query('SELECT slug, parent_slug, weight_pct FROM catalog_categories')->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    $own = array_column($rows, 'weight_pct', 'slug');
    $parents = array_column($rows, 'parent_slug', 'slug');
    $out = [];
    foreach ($rows as $r) {
        $pct = 0.0;
        foreach (adj_chain($r['slug'], $parents) as $s) {
            if (($own[$s] ?? null) !== null) { $pct = (float)$own[$s]; break; }
        }
        $out[$r['slug']] = $pct;
    }
    return $out;
}

/** Значение для столбца «Длина, м» в выгрузке прайс-листа: число, «немер 9–12» или пусто (по правилу). */
function adj_length_cell(array $p): string {
    $nemer = trim((string)($p['length_nemer'] ?? ''));
    if ($nemer !== '') return 'немер ' . (adj_norm_range($nemer) ?: $nemer);
    $m = $p['length_m'] ?? null;
    return ($m !== null && $m !== '' && (float)$m > 0) ? rtrim(rtrim(number_format((float)$m, 2, '.', ''), '0'), '.') : '';
}

/** Обратно: ячейка «Длина, м» → ['length_m' => ?float, 'length_nemer' => string]. */
function adj_parse_length_cell($v): array {
    $s = trim((string)$v);
    if (preg_match('/немер/iu', $s)) {
        $range = '';
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*[-–—]\s*(\d+(?:[.,]\d+)?)/u', $s, $m)) $range = adj_norm_range($m[1] . '-' . $m[2]);
        return ['length_m' => null, 'length_nemer' => $range !== '' ? $range : '9–12'];
    }
    $f = (float)str_replace(',', '.', $s);
    return ['length_m' => $f > 0 ? $f : null, 'length_nemer' => ''];
}

/** Категории деревом: родитель, под ним его подкатегории. Каждой добавляется 'depth'. */
function adj_tree(array $cats): array {
    $bySlug = [];
    foreach ($cats as $c) $bySlug[$c['slug']] = $c;
    $children = [];
    $roots = [];
    foreach ($cats as $c) {
        $parent = $c['parent_slug'] ?? null;
        if ($parent !== null && isset($bySlug[$parent]) && $parent !== $c['slug']) $children[$parent][] = $c;
        else $roots[] = $c;
    }
    $out = [];
    $seen = [];
    $walk = function (array $list, int $depth) use (&$walk, &$out, &$seen, $children) {
        foreach ($list as $c) {
            if (isset($seen[$c['slug']])) continue;
            $seen[$c['slug']] = true;
            $out[] = $c + ['depth' => $depth];
            $walk($children[$c['slug']] ?? [], $depth + 1);
        }
    };
    $walk($roots, 0);
    return $out;
}
