<?php
/**
 * Единое описание колонок прайс-листа — чтобы импорт и экспорт
 * всегда использовали один и тот же порядок и подписи столбцов.
 */

function category_columns(): array {
    return ['slug', 'parent_slug', 'Название', 'Описание', 'Марка стали', 'ГОСТ',
        'Цена от ₽/т', 'Остаток т', 'Ссылка фото',
        'filter1_label', 'filter1_type', 'filter1_values',
        'filter2_label', 'filter2_type', 'filter2_values'];
}

function category_field_map(): array {
    return [
        'slug' => 'slug', 'parent_slug' => 'parent_slug', 'Название' => 'title',
        'Описание' => 'short_desc', 'Марка стали' => 'steel', 'ГОСТ' => 'gost_main',
        'Цена от ₽/т' => 'price_from', 'Остаток т' => 'in_stock', 'Ссылка фото' => 'image',
        'filter1_label' => 'filter1_label', 'filter1_type' => 'filter1_type', 'filter1_values' => 'filter1_values',
        'filter2_label' => 'filter2_label', 'filter2_type' => 'filter2_type', 'filter2_values' => 'filter2_values',
    ];
}

function product_columns(): array {
    return ['id', 'Категория (slug)', 'Название', 'Вес кг/м', 'Длина, м',
        'Закупка ₽/т', 'Остаток т', 'param1_value', 'param2_value'];
}

function product_field_map(): array {
    return [
        'id' => 'id', 'Категория (slug)' => 'category_slug', 'Название' => 'label',
        'Вес кг/м' => 'weight_per_meter', 'Длина, м' => 'length_m',
        'Закупка ₽/т' => 'cost_price_per_ton',
        'Остаток т' => 'in_stock',
        'param1_value' => 'param1_value', 'param2_value' => 'param2_value',
    ];
}

/** Превращает строку с русскими заголовками (из XlsxReader) в массив с ключами БД. */
function map_row(array $row, array $fieldMap): array {
    $out = [];
    foreach ($fieldMap as $header => $field) {
        $out[$field] = isset($row[$header]) ? $row[$header] : null;
    }
    return $out;
}
