<?php
require __DIR__ . '/db.php';
require_once __DIR__ . '/lib/adjustments.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') json_response(null, 200);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_response(['error' => 'Method not allowed'], 405);

try {
    $pdo = db_connect();

    // Явный список колонок: закупочная цена и наценка — внутренние данные,
    // наружу отдаём только уже посчитанную продажную цену под тем же именем
    // price_per_ton, что и раньше (фронтенд ничего не меняет).
    $categories = $pdo->query(
        'SELECT slug, parent_slug, title, short_desc, steel, gost_main, price_from, in_stock, image, sort_order,
                filter_label, filter_ranges, filter1_label, filter1_type, filter1_values,
                filter2_label, filter2_type, filter2_values, updated_at
         FROM catalog_categories ORDER BY sort_order'
    )->fetchAll();

    $products = $pdo->query(
        'SELECT p.*, (p.cost_price_per_ton + c.markup_per_ton) AS price_per_ton
         FROM catalog_products p
         JOIN catalog_categories c ON c.slug = p.category_slug
         ORDER BY p.sort_order'
    )->fetchAll();

    // «Ручные корректировки»: вес с поправкой категории (в базе — теоретический),
    // длина по старшинству (название → вручную → правило) дописывается в название.
    // length_kind: fixed — есть цена за штуку; nemer / coil — вместо неё length_text
    // (немерные калькулятор считает штуками по length_calc_m); none — длина не задана.
    $parents = adj_parents($pdo);
    $rules = adj_load_rules($pdo);
    $pcts = adj_weight_pcts($pdo);
    $nemerCalc = adj_nemer_calc_setting($pdo);
    $labelById = [];
    foreach ($products as &$p) {
        $len = adj_resolve_length($p, $rules, $parents);
        $pct = $pcts[$p['category_slug']] ?? 0.0;
        $p = [
            'id'               => $p['id'],
            'category_slug'    => $p['category_slug'],
            'label'            => $p['label'] . $len['append'],
            'weight_per_meter' => round((float)$p['weight_per_meter'] * (1 + $pct / 100), 3),
            'length_m'         => $len['kind'] === 'fixed' ? $len['m'] : null,
            'length_kind'      => $len['kind'],
            'length_text'      => $len['text'],
            // Немерные в калькуляторе продаются штуками по расчётной длине (11,5 м)
            'length_calc_m'    => $len['kind'] === 'nemer' ? adj_nemer_piece_m($len, $nemerCalc) : null,
            'price_per_ton'    => (int)$p['price_per_ton'],
            'sort_order'       => $p['sort_order'],
            'param_value'      => $p['param_value'],
            'in_stock'         => $p['in_stock'],
            'param1_value'     => $p['param1_value'],
            'param2_value'     => $p['param2_value'],
            'updated_at'       => $p['updated_at'],
        ];
        $labelById[$p['id']] = $p['label'];
    }
    unset($p);

    // «Цена от» считается сама: минимальная цена продажи среди товаров категории
    // и её подкатегорий (только товары с заполненной закупкой). Значение из админки
    // остаётся запасным — для категорий, где таких товаров нет.
    $minBySlug = [];
    foreach ($pdo->query(
        'SELECT p.category_slug, MIN(p.cost_price_per_ton + c.markup_per_ton) AS min_price
         FROM catalog_products p
         JOIN catalog_categories c ON c.slug = p.category_slug
         WHERE p.cost_price_per_ton > 0
         GROUP BY p.category_slug'
    ) as $r) {
        $minBySlug[$r['category_slug']] = (int)$r['min_price'];
    }
    $parentBySlug = array_column($categories, 'parent_slug', 'slug');
    foreach ($minBySlug as $slug => $min) {
        $seen = [];
        for ($p = $parentBySlug[$slug] ?? null; $p !== null && !isset($seen[$p]); $p = $parentBySlug[$p] ?? null) {
            $seen[$p] = true;
            if (!isset($minBySlug[$p]) || $min < $minBySlug[$p]) $minBySlug[$p] = $min;
        }
    }
    foreach ($categories as &$c) {
        if (($minBySlug[$c['slug']] ?? 0) > 0) $c['price_from'] = $minBySlug[$c['slug']];
    }
    unset($c);

    $featured = $pdo->query(
        'SELECT p.id, p.label, (p.cost_price_per_ton + c.markup_per_ton) AS price_per_ton
         FROM featured_products f
         JOIN catalog_products p ON p.id = f.product_id
         JOIN catalog_categories c ON c.slug = p.category_slug
         ORDER BY f.sort_order'
    )->fetchAll();
    foreach ($featured as &$f) {
        $f['label'] = $labelById[$f['id']] ?? $f['label'];
    }
    unset($f);

    json_response(['categories' => $categories, 'products' => $products, 'featured' => $featured]);
} catch (Throwable $e) {
    json_response(['error' => 'Server error'], 500);
}
