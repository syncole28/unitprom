<?php
/**
 * Минимальные читалка и писалка .xlsx без внешних библиотек (без Composer на хостинге).
 * Рассчитаны именно на простые плоские таблицы (строка заголовков + строки данных),
 * без стилей, формул и объединённых ячеек — это всё, что нужно для прайс-листа.
 */

class XlsxReader {
    /**
     * Возвращает ['Имя листа' => [ ['Заголовок1' => значение, ...], ... ], ...]
     * Первая строка каждого листа считается заголовком и в данные не попадает.
     */
    public static function readSheets(string $path, array $sheetNames): array {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Не удалось открыть файл — это точно .xlsx?');
        }

        $sharedStrings = self::readSharedStrings($zip);
        $sheetTargets  = self::readSheetTargets($zip);

        $result = [];
        foreach ($sheetNames as $name) {
            if (!isset($sheetTargets[$name])) {
                throw new RuntimeException("В файле нет листа «{$name}»");
            }
            // Target в workbook.xml.rels бывает и относительным ("worksheets/sheet1.xml",
            // как пишет настоящий Excel), и абсолютным ("/xl/worksheets/sheet1.xml",
            // как пишут некоторые другие программы) — приводим к одному виду.
            $target = ltrim($sheetTargets[$name], '/');
            if (str_starts_with($target, 'xl/')) {
                $target = substr($target, 3);
            }
            $xml = $zip->getFromName('xl/' . $target);
            if ($xml === false) {
                throw new RuntimeException("Не удалось прочитать лист «{$name}»");
            }
            $result[$name] = self::parseSheet($xml, $sharedStrings);
        }

        $zip->close();
        return $result;
    }

    /**
     * Все листы файла «как есть», в порядке листов книги:
     * ['Имя листа' => [ [индекс столбца => значение, ...], ... ], ...].
     * Заголовки не выделяются — для прайсов поставщиков, где шапка таблицы
     * начинается не с первой строки и её нужно найти самостоятельно.
     */
    public static function readAllSheetsRaw(string $path): array {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Не удалось открыть файл — это точно .xlsx?');
        }

        $sharedStrings = self::readSharedStrings($zip);
        $result = [];
        foreach (self::readSheetTargets($zip) as $name => $target) {
            $target = ltrim($target, '/');
            if (str_starts_with($target, 'xl/')) {
                $target = substr($target, 3);
            }
            $xml = $zip->getFromName('xl/' . $target);
            if ($xml === false) continue;
            $result[$name] = self::parseRows($xml, $sharedStrings);
        }

        $zip->close();
        return $result;
    }

    private static function readSharedStrings(ZipArchive $zip): array {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) return [];

        $dom = new SimpleXMLElement($xml);
        $strings = [];
        foreach ($dom->si as $si) {
            // <si> может быть простым <t> или набором "runs" <r><t>...</t></r>
            if (isset($si->t)) {
                $strings[] = (string)$si->t;
            } else {
                $text = '';
                foreach ($si->r as $run) {
                    $text .= (string)$run->t;
                }
                $strings[] = $text;
            }
        }
        return $strings;
    }

    private static function readSheetTargets(ZipArchive $zip): array {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml     = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) {
            throw new RuntimeException('Повреждённый файл .xlsx');
        }

        $wb = new SimpleXMLElement($workbookXml);
        $wb->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $rIdToName = [];
        foreach ($wb->sheets->sheet as $sheet) {
            $attrs = $sheet->attributes('r', true);
            $rId = (string)$attrs['id'];
            $rIdToName[$rId] = (string)$sheet['name'];
        }

        $rels = new SimpleXMLElement($relsXml);
        $targetByRId = [];
        foreach ($rels->Relationship as $rel) {
            $targetByRId[(string)$rel['Id']] = (string)$rel['Target'];
        }

        // В порядке листов книги, а не в порядке записей в .rels
        $targets = [];
        foreach ($rIdToName as $rId => $name) {
            if (isset($targetByRId[$rId])) {
                $targets[$name] = $targetByRId[$rId];
            }
        }
        return $targets;
    }

    private static function parseSheet(string $xml, array $sharedStrings): array {
        $rows = self::parseRows($xml, $sharedStrings);

        if (!$rows) return [];

        $headerRow = array_shift($rows);
        $maxCol = $headerRow ? max(array_keys($headerRow)) : -1;
        $headers = [];
        for ($i = 0; $i <= $maxCol; $i++) {
            $headers[$i] = isset($headerRow[$i]) ? trim((string)$headerRow[$i]) : '';
        }

        $out = [];
        foreach ($rows as $cells) {
            // Пропускаем полностью пустые строки
            $hasValue = false;
            foreach ($cells as $v) { if ($v !== null && $v !== '') { $hasValue = true; break; } }
            if (!$hasValue) continue;

            $assoc = [];
            foreach ($headers as $i => $h) {
                if ($h === '') continue;
                $assoc[$h] = $cells[$i] ?? null;
            }
            $out[] = $assoc;
        }
        return $out;
    }

    /** Строки листа как списки [индекс столбца => значение]. */
    private static function parseRows(string $xml, array $sharedStrings): array {
        $dom = new SimpleXMLElement($xml);
        $rows = [];
        foreach ($dom->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $ref = (string)$c['r'];
                $col = self::colIndexFromRef($ref);
                $type = (string)$c['t'];

                if ($type === 's') {
                    $idx = (int)$c->v;
                    $value = $sharedStrings[$idx] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = (string)($c->is->t ?? '');
                } elseif ($type === 'str') {
                    $value = (string)$c->v;
                } elseif (isset($c->v)) {
                    $value = (string)$c->v;
                    if (is_numeric($value)) {
                        $value = $value + 0; // int или float
                    }
                } else {
                    $value = null;
                }
                $cells[$col] = $value;
            }
            $rows[] = $cells;
        }
        return $rows;
    }

    private static function colIndexFromRef(string $ref): int {
        preg_match('/^([A-Z]+)/', $ref, $m);
        $letters = $m[1] ?? 'A';
        $index = 0;
        foreach (str_split($letters) as $ch) {
            $index = $index * 26 + (ord($ch) - 64);
        }
        return $index - 1;
    }
}

class XlsxWriter {
    /**
     * $sheets: ['Имя листа' => [ [заголовок1, заголовок2, ...], [значение1, значение2, ...], ... ]]
     * Первая строка каждого листа — заголовки. Строковые и числовые значения определяются автоматически.
     */
    public static function write(array $sheets, string $outPath): void {
        if (file_exists($outPath)) unlink($outPath);

        $zip = new ZipArchive();
        $zip->open($outPath, ZipArchive::CREATE);

        $zip->addFromString('[Content_Types].xml', self::contentTypes(count($sheets)));
        $zip->addFromString('_rels/.rels', self::rootRels());
        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->addFromString('xl/workbook.xml', self::workbookXml(array_keys($sheets)));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels(count($sheets)));

        $i = 1;
        foreach ($sheets as $rows) {
            $zip->addFromString("xl/worksheets/sheet{$i}.xml", self::sheetXml($rows));
            $i++;
        }

        $zip->close();
    }

    private static function colLetter(int $index): string {
        $letter = '';
        $index++;
        while ($index > 0) {
            $rem = ($index - 1) % 26;
            $letter = chr(65 + $rem) . $letter;
            $index = intdiv($index - 1, 26);
        }
        return $letter;
    }

    private static function sheetXml(array $rows): string {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        foreach ($rows as $rIdx => $row) {
            $rNum = $rIdx + 1;
            $xml .= "<row r=\"{$rNum}\">";
            foreach (array_values($row) as $cIdx => $value) {
                $ref = self::colLetter($cIdx) . $rNum;
                if ($value === null || $value === '') {
                    continue;
                }
                if (is_numeric($value) && !is_string($value)) {
                    $xml .= "<c r=\"{$ref}\"><v>" . htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8') . "</v></c>";
                } else {
                    $esc = htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
                    $xml .= "<c r=\"{$ref}\" t=\"inlineStr\"><is><t xml:space=\"preserve\">{$esc}</t></is></c>";
                }
            }
            $xml .= '</row>';
        }

        $xml .= '</sheetData></worksheet>';
        return $xml;
    }

    private static function contentTypes(int $sheetCount): string {
        $overrides = '';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $overrides .= "<Override PartName=\"/xl/worksheets/sheet{$i}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $overrides
            . '</Types>';
    }

    private static function rootRels(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbookXml(array $sheetNames): string {
        $sheetsXml = '';
        foreach (array_values($sheetNames) as $i => $name) {
            $id = $i + 1;
            $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $sheetsXml .= "<sheet name=\"{$safeName}\" sheetId=\"{$id}\" r:id=\"rId{$id}\"/>";
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . "<sheets>{$sheetsXml}</sheets></workbook>";
    }

    private static function workbookRels(int $sheetCount): string {
        $rels = '';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $rels .= "<Relationship Id=\"rId{$i}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$i}.xml\"/>";
        }
        $stylesId = $sheetCount + 1;
        $rels .= "<Relationship Id=\"rId{$stylesId}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\" Target=\"styles.xml\"/>";
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels
            . '</Relationships>';
    }

    private static function styles(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="1"><xf/></cellXfs>'
            . '</styleSheet>';
    }
}
