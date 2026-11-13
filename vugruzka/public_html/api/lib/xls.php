<?php
/**
 * Чтение старого формата Excel 97–2003 (.xls, BIFF8) без сторонних библиотек.
 * Нужен для прайсов, которые 1С выгружает в .xls. Читает только значения ячеек
 * (текст и числа, у формул — сохранённый результат); оформление не читается.
 *
 * Результат — как у XlsxReader::readAllSheetsRaw():
 * ['Имя листа' => [ [индекс столбца => значение, ...], ... ], ...], пустые строки пропущены.
 */
class XlsReader {
    private const SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    public static function isXls(string $path): bool {
        $f = @fopen($path, 'rb');
        if (!$f) return false;
        $head = fread($f, 8);
        fclose($f);
        return $head === self::SIGNATURE;
    }

    public static function readAllSheetsRaw(string $path): array {
        $data = file_get_contents($path);
        if ($data === false || strncmp($data, self::SIGNATURE, 8) !== 0) {
            throw new RuntimeException('Не удалось открыть файл — это точно .xls?');
        }
        $book = self::readStream($data, ['Workbook', 'Book']);
        if ($book === null) {
            throw new RuntimeException('В файле .xls не найдена книга Excel');
        }
        return self::parseWorkbook($book);
    }

    // ---- Контейнер (OLE2 Compound File) ----

    private static function u16(string $s, int $o): int { return unpack('v', $s, $o)[1]; }
    private static function u32(string $s, int $o): int { return unpack('V', $s, $o)[1]; }

    /** Содержимое потока с одним из имён $names или null. */
    private static function readStream(string $data, array $names): ?string {
        $shift = self::u16($data, 0x1E);
        $miniShift = self::u16($data, 0x20);
        if ($shift < 7 || $shift > 16 || $miniShift < 2 || $miniShift >= $shift) {
            throw new RuntimeException('Повреждённый файл .xls');
        }
        $secSize = 1 << $shift;
        $miniSize = 1 << $miniShift;
        $len = strlen($data);
        $sector = function (int $id) use ($data, $shift, $secSize, $len): string {
            $off = ($id + 1) << $shift;
            if ($off >= $len) throw new RuntimeException('Повреждённый файл .xls');
            return substr($data, $off, $secSize);
        };

        // Таблица размещения (FAT): номера её секторов — в заголовке и в цепочке DIFAT
        $fatSectors = [];
        for ($i = 0; $i < 109; $i++) {
            $id = self::u32($data, 0x4C + $i * 4);
            if ($id < 0xFFFFFFFA) $fatSectors[] = $id;
        }
        $difat = self::u32($data, 0x44);
        $perSector = intdiv($secSize, 4);
        for ($guard = 0; $difat < 0xFFFFFFFA && $guard < 10000; $guard++) {
            $s = $sector($difat);
            for ($i = 0; $i < $perSector - 1; $i++) {
                $id = self::u32($s, $i * 4);
                if ($id < 0xFFFFFFFA) $fatSectors[] = $id;
            }
            $difat = self::u32($s, ($perSector - 1) * 4);
        }
        $fat = [];
        foreach ($fatSectors as $id) {
            foreach (unpack('V*', $sector($id)) as $v) $fat[] = $v;
        }

        $chain = function (int $start, array $table) {
            $ids = [];
            for ($id = $start, $guard = 0; $id < 0xFFFFFFFA && $guard < 1000000; $guard++) {
                if (!isset($table[$id])) throw new RuntimeException('Повреждённый файл .xls');
                $ids[] = $id;
                $id = $table[$id];
            }
            return $ids;
        };
        $readBig = function (int $start) use ($chain, $fat, $sector): string {
            $out = '';
            foreach ($chain($start, $fat) as $id) $out .= $sector($id);
            return $out;
        };

        // Каталог: записи по 128 байт
        $dir = $readBig(self::u32($data, 0x30));
        $entries = [];
        for ($o = 0; $o + 128 <= strlen($dir); $o += 128) {
            $nameLen = self::u16($dir, $o + 0x40);
            $type = ord($dir[$o + 0x42]);
            if ($type === 0 || $nameLen < 2 || $nameLen > 64) continue;
            $name = mb_convert_encoding(substr($dir, $o, $nameLen - 2), 'UTF-8', 'UTF-16LE');
            $entries[] = ['name' => $name, 'type' => $type, 'start' => self::u32($dir, $o + 0x74), 'size' => self::u32($dir, $o + 0x78)];
        }
        $root = null;
        foreach ($entries as $e) if ($e['type'] === 5) { $root = $e; break; }

        foreach ($names as $want) {
            foreach ($entries as $e) {
                if ($e['type'] !== 2 || strcasecmp($e['name'], $want) !== 0) continue;
                $cutoff = self::u32($data, 0x38);
                if ($e['size'] >= $cutoff || $root === null) {
                    return substr($readBig($e['start']), 0, $e['size']);
                }
                // Маленький поток лежит в «мини-потоке» корневой записи
                $miniFat = [];
                $mfStart = self::u32($data, 0x3C);
                if ($mfStart < 0xFFFFFFFA) {
                    foreach (unpack('V*', $readBig($mfStart)) as $v) $miniFat[] = $v;
                }
                $ministream = $readBig($root['start']);
                $out = '';
                foreach ($chain($e['start'], $miniFat) as $id) $out .= substr($ministream, $id * $miniSize, $miniSize);
                return substr($out, 0, $e['size']);
            }
        }
        return null;
    }

    // ---- Книга (записи BIFF8) ----

    private static function parseWorkbook(string $b): array {
        $len = strlen($b);
        $sheets = [];
        $sst = [];
        $pos = 0;
        $first = true;
        while ($pos + 4 <= $len) {
            $type = self::u16($b, $pos);
            $size = self::u16($b, $pos + 2);
            $rec = substr($b, $pos + 4, $size);
            $pos += 4 + $size;
            if ($first) {
                $first = false;
                if ($type !== 0x0809 || self::u16($rec, 0) !== 0x0600) {
                    throw new RuntimeException('Файл в слишком старом формате Excel (до 97) — сохраните его как .xlsx');
                }
                continue;
            }
            if ($type === 0x002F) {
                throw new RuntimeException('Файл защищён паролем — снимите защиту или сохраните как .xlsx');
            } elseif ($type === 0x0085) { // BOUNDSHEET
                if (ord($rec[5]) !== 0) continue; // не обычный лист (диаграмма, макрос)
                $cch = ord($rec[6]);
                $sheets[] = ['offset' => self::u32($rec, 0), 'name' => self::unicodeChars($rec, 7, $cch)[0]];
            } elseif ($type === 0x00FC) { // SST + CONTINUE
                $chunks = [substr($rec, 8)];
                while ($pos + 4 <= $len && self::u16($b, $pos) === 0x003C) {
                    $size = self::u16($b, $pos + 2);
                    $chunks[] = substr($b, $pos + 4, $size);
                    $pos += 4 + $size;
                }
                $sst = self::parseSst($chunks, self::u32($rec, 4));
            } elseif ($type === 0x000A) { // EOF глобального раздела
                break;
            }
        }

        $result = [];
        foreach ($sheets as $sh) {
            $result[$sh['name']] = self::parseSheet($b, $sh['offset'], $sst);
        }
        return $result;
    }

    /** Символы строки BIFF8: флаг 1 байт (бит 0 — 2 байта на символ), затем $cch символов. Возвращает [строка, позиция после]. */
    private static function unicodeChars(string $s, int $o, int $cch): array {
        $flags = ord($s[$o] ?? "\0");
        $o++;
        if ($flags & 0x08) $o += 2; // rich text: число форматных отрезков
        if ($flags & 0x04) $o += 4; // азиатские расширения
        if ($flags & 0x01) {
            $str = mb_convert_encoding(substr($s, $o, $cch * 2), 'UTF-8', 'UTF-16LE');
            $o += $cch * 2;
        } else {
            $str = mb_convert_encoding(substr($s, $o, $cch), 'UTF-8', 'ISO-8859-1');
            $o += $cch;
        }
        return [$str, $o];
    }

    /** Общая таблица строк; строки могут переходить через границы записей CONTINUE. */
    private static function parseSst(array $chunks, int $count): array {
        $ci = 0;
        $p = 0;
        $read = function (int $n) use (&$chunks, &$ci, &$p): string {
            $out = '';
            while ($n > 0 && $ci < count($chunks)) {
                $avail = strlen($chunks[$ci]) - $p;
                if ($avail <= 0) { $ci++; $p = 0; continue; }
                $take = min($n, $avail);
                $out .= substr($chunks[$ci], $p, $take);
                $p += $take;
                $n -= $take;
            }
            return $out;
        };
        $strings = [];
        for ($i = 0; $i < $count && $ci < count($chunks); $i++) {
            $hdr = $read(3);
            if (strlen($hdr) < 3) break;
            $cch = unpack('v', $hdr)[1];
            $flags = ord($hdr[2]);
            $runs = ($flags & 0x08) ? unpack('v', $read(2))[1] : 0;
            $ext = ($flags & 0x04) ? unpack('V', $read(4))[1] : 0;
            $wide = (bool)($flags & 0x01);
            $str = '';
            $left = $cch;
            while ($left > 0 && $ci < count($chunks)) {
                $avail = strlen($chunks[$ci]) - $p;
                if ($avail <= 0) {
                    // Продолжение символов в следующей записи начинается с нового флага ширины
                    $ci++;
                    $p = 0;
                    if ($ci >= count($chunks)) break;
                    $wide = (bool)(ord($chunks[$ci][0]) & 0x01);
                    $p = 1;
                    continue;
                }
                $bpc = $wide ? 2 : 1;
                $take = min($left, intdiv($avail, $bpc));
                if ($take <= 0) { $p = strlen($chunks[$ci]); continue; }
                $part = substr($chunks[$ci], $p, $take * $bpc);
                $str .= $wide ? mb_convert_encoding($part, 'UTF-8', 'UTF-16LE') : mb_convert_encoding($part, 'UTF-8', 'ISO-8859-1');
                $p += $take * $bpc;
                $left -= $take;
            }
            if ($runs) $read($runs * 4);
            if ($ext) $read($ext);
            $strings[] = $str;
        }
        return $strings;
    }

    private static function rk(int $rk) {
        if ($rk & 0x02) {
            $v = $rk >> 2;
            if ($v & 0x20000000) $v -= 0x40000000; // знак 30-битного целого
        } else {
            $v = unpack('e', pack('V', 0) . pack('V', $rk & 0xFFFFFFFC))[1];
        }
        if ($rk & 0x01) $v /= 100;
        return $v;
    }

    private static function num($v) {
        return is_float($v) && floor($v) == $v && abs($v) < PHP_INT_MAX ? (int)$v : $v;
    }

    private static function parseSheet(string $b, int $pos, array $sst): array {
        $len = strlen($b);
        $cells = [];
        $pendingFormula = null; // ячейка, чей текстовый результат придёт в записи STRING
        $first = true;
        while ($pos + 4 <= $len) {
            $type = self::u16($b, $pos);
            $size = self::u16($b, $pos + 2);
            $rec = substr($b, $pos + 4, $size);
            $pos += 4 + $size;
            if ($first) { $first = false; if ($type !== 0x0809) break; continue; }
            if ($type === 0x000A) break; // EOF листа
            switch ($type) {
                case 0x00FD: // LABELSST
                    $cells[self::u16($rec, 0)][self::u16($rec, 2)] = $sst[self::u32($rec, 6)] ?? '';
                    break;
                case 0x0203: // NUMBER
                    $cells[self::u16($rec, 0)][self::u16($rec, 2)] = self::num(unpack('e', $rec, 6)[1]);
                    break;
                case 0x027E: // RK
                    $cells[self::u16($rec, 0)][self::u16($rec, 2)] = self::num(self::rk(self::u32($rec, 6)));
                    break;
                case 0x00BD: // MULRK
                    $r = self::u16($rec, 0);
                    $c = self::u16($rec, 2);
                    for ($o = 4; $o + 6 <= $size - 2; $o += 6, $c++) {
                        $cells[$r][$c] = self::num(self::rk(self::u32($rec, $o + 2)));
                    }
                    break;
                case 0x0204: // LABEL
                    $cells[self::u16($rec, 0)][self::u16($rec, 2)] = self::unicodeChars($rec, 8, self::u16($rec, 6))[0];
                    break;
                case 0x0006: // FORMULA — берём сохранённый результат
                    $r = self::u16($rec, 0);
                    $c = self::u16($rec, 2);
                    if (substr($rec, 12, 2) === "\xFF\xFF") {
                        $kind = ord($rec[6]);
                        if ($kind === 0) $pendingFormula = [$r, $c];
                        elseif ($kind === 1) $cells[$r][$c] = ord($rec[8]) ? 1 : 0;
                    } else {
                        $cells[$r][$c] = self::num(unpack('e', $rec, 6)[1]);
                    }
                    break;
                case 0x0207: // STRING — текстовый результат предыдущей формулы
                    if ($pendingFormula) {
                        $cells[$pendingFormula[0]][$pendingFormula[1]] = self::unicodeChars($rec, 2, self::u16($rec, 0))[0];
                        $pendingFormula = null;
                    }
                    break;
            }
        }
        ksort($cells);
        $rows = [];
        foreach ($cells as $row) {
            ksort($row);
            $rows[] = $row;
        }
        return $rows;
    }
}
