<?php
/**
 * Parse session uploads (CSV, TSV, Excel, Word) into normalised records.
 */

function impactAllowedUploadExtensions(): array {
    return ['csv', 'tsv', 'txt', 'xlsx', 'xlsm', 'docx'];
}

function impactUploadHelpMessage(): string {
    return 'Use a CSV, Excel (.xlsx), or Word (.docx) file.';
}

function impactNormaliseHeader(string $header): string {
    $h = strtolower(trim($header));
    $h = preg_replace('/[^a-z0-9]+/', '_', $h);
    return trim($h, '_');
}

function impactLooksLikeDate(string $value, bool $allowExcelSerial = false): bool {
    $value = trim($value);
    if ($value === '') {
        return false;
    }
    $isSerial = (bool) preg_match('/^\d{5}(?:\.\d+)?$/', $value);
    if ($isSerial && !$allowExcelSerial) {
        return false;
    }
    return impactParseDate($value) !== null;
}

function impactExpandYear(int $year): int {
    if ($year < 100) {
        return $year >= 70 ? 1900 + $year : 2000 + $year;
    }
    return $year;
}

function impactYmd(int $year, int $month, int $day): ?string {
    $year = impactExpandYear($year);
    if (!checkdate($month, $day, $year) || $year < 1970 || $year > 2100) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function impactExcelSerialToDate($value): ?string {
    if (!is_numeric($value)) {
        return null;
    }
    $serial = (float) $value;
    if ($serial != floor($serial)) {
        $serial = floor($serial);
    }
    // Typical Excel date serials for 1990–2100.
    if ($serial < 20000 || $serial > 73000) {
        return null;
    }
    $unix = (int) round(($serial - 25569) * 86400);
    if ($unix <= 0) {
        return null;
    }
    return gmdate('Y-m-d', $unix);
}

function impactParseDateStrictFormat(string $value, string $fmt): ?string {
    $dt = DateTime::createFromFormat('!' . $fmt, $value);
    if (!$dt instanceof DateTime) {
        return null;
    }
    $errors = DateTime::getLastErrors();
    if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
        return null;
    }
    return impactYmd((int) $dt->format('Y'), (int) $dt->format('n'), (int) $dt->format('j'));
}

function impactNormaliseDateText(string $value): string {
    $value = str_replace(
        ["\u{00A0}", "\u{202F}", "\u{2007}", "\u{2009}", "\u{200A}", "\u{FEFF}"],
        ' ',
        $value
    );
    $value = preg_replace('/\s+/u', ' ', trim($value));
    $value = trim($value, "\"'");
    // "Monday, 12th of March 2026" is how dates are usually typed in Word.
    $value = preg_replace(
        '/^(?:mon(?:day)?|tue(?:s(?:day)?)?|wed(?:nesday)?|thu(?:r(?:s(?:day)?)?)?|fri(?:day)?|sat(?:urday)?|sun(?:day)?)\b[,.]?\s+/i',
        '',
        $value
    );
    $value = preg_replace('/\b(\d{1,2})(?:st|nd|rd|th)\b/i', '$1', $value);
    $value = preg_replace('/\b(\d{1,2})\s+of\s+/i', '$1 ', $value);
    // "12.Mar.2026" and "12 Mar. 2026"
    $value = preg_replace('/(\d)\.(?=[A-Za-z])/', '$1-', $value);
    $value = preg_replace('/([A-Za-z])\.(?=[\s\d])/', '$1', $value);
    return trim($value, " \t.,;");
}

function impactParseDate(string $value): ?string {
    $value = impactNormaliseDateText($value);
    if ($value === '') {
        return null;
    }

    $exact = impactParseDateExact($value);
    if ($exact) {
        return $exact;
    }

    // A date sitting inside a sentence or a note, e.g. "Session on 12 March 2026 (14 children)".
    $patterns = [
        '/\d{4}-\d{2}-\d{2}/',
        '/\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}/',
        '/\d{1,2}\s+[A-Za-z]{3,9}\s+\d{2,4}/',
        '/[A-Za-z]{3,9}\s+\d{1,2},?\s+\d{4}/',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $value, $match)) {
            $found = impactParseDateExact(impactNormaliseDateText($match[0]));
            if ($found) {
                return $found;
            }
        }
    }

    // "12 March" with no year, common when the year is only in the document title.
    if (preg_match('/^(\d{1,2})\s+([A-Za-z]{3,9})$/', $value, $match)) {
        $year = (int) date('Y');
        $found = impactParseDateExact($match[1] . ' ' . $match[2] . ' ' . $year);
        if ($found && $found > date('Y-m-d', strtotime('+45 days'))) {
            $found = impactParseDateExact($match[1] . ' ' . $match[2] . ' ' . ($year - 1));
        }
        return $found;
    }

    return null;
}

function impactParseDateExact(string $value): ?string {
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    // Excel serials often appear as 44927, 44927.0, or 44927.375 (date + time).
    if (preg_match('/^\d{5}(?:\.\d+)?$/', $value)) {
        $serialDate = impactExcelSerialToDate($value);
        if ($serialDate) {
            return $serialDate;
        }
    }

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
        return impactYmd((int) $m[1], (int) $m[2], (int) $m[3]);
    }

    // Drop a trailing time so 09/03/2024 09:30 still parses as a date.
    $dateOnly = $value;
    if (preg_match('/^(.+?)\s+\d{1,2}:\d{2}(?::\d{2})?(?:\s*[AaPp][Mm])?$/', $value, $m)) {
        $dateOnly = trim($m[1]);
    }

    if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{2,4})$/', $dateOnly, $m)) {
        $left = (int) $m[1];
        $middle = (int) $m[2];
        $year = impactExpandYear((int) $m[3]);
        // UK first: day/month/year. Only use US month/day when day would be invalid.
        $uk = impactYmd($year, $middle, $left);
        if ($uk) {
            return $uk;
        }
        return impactYmd($year, $left, $middle);
    }

    $namedFormats = [
        'd M Y', 'j M Y', 'd M y', 'j M y',
        'd-M-Y', 'd-M-y', 'j-M-Y',
        'd F Y', 'j F Y',
        'M d, Y', 'M j, Y', 'F j, Y', 'F d, Y',
        'Y/m/d', 'Y.m.d',
    ];
    foreach ($namedFormats as $fmt) {
        $parsed = impactParseDateStrictFormat($dateOnly, $fmt);
        if ($parsed) {
            return $parsed;
        }
    }

    return null;
}

function impactFormatDisplayDate(string $ymd): string {
    $dt = DateTime::createFromFormat('!Y-m-d', $ymd);
    if (!$dt instanceof DateTime) {
        return $ymd;
    }
    return $dt->format('j M Y');
}

function impactIsTruthy(string $value): bool {
    $v = strtolower(trim($value));
    return in_array($v, ['1', 'true', 'yes', 'y'], true);
}

function impactInferFieldType(array $values): string {
    $nonEmpty = array_values(array_filter($values, static fn($v) => trim((string)$v) !== ''));
    if (!$nonEmpty) {
        return 'text';
    }

    $dateHits = 0;
    $boolHits = 0;
    $numericHits = 0;

    foreach ($nonEmpty as $raw) {
        $v = trim((string)$raw);
        if (impactLooksLikeDate($v)) {
            $dateHits++;
            continue;
        }
        if (in_array(strtolower($v), ['yes', 'no', 'true', 'false', '1', '0', 'y', 'n'], true)) {
            $boolHits++;
            continue;
        }
        if (is_numeric($v)) {
            $numericHits++;
        }
    }

    $total = count($nonEmpty);
    if ($dateHits / $total >= 0.7) {
        return 'date';
    }
    if ($boolHits / $total >= 0.7) {
        return 'boolean';
    }
    if ($numericHits / $total >= 0.7) {
        return 'numeric';
    }

    $unique = count(array_unique(array_map(static fn($v) => strtolower(trim((string)$v)), $nonEmpty)));
    if ($unique <= 12 && $unique / $total <= 0.5) {
        return 'categorical';
    }

    $avgLen = array_sum(array_map(static fn($v) => strlen((string)$v), $nonEmpty)) / $total;
    return $avgLen > 40 ? 'text' : 'categorical';
}

function impactScoreDateColumn(string $name, array $values): int {
    $score = 0;
    $nameLooksLikeDate = (bool) preg_match('/date|session|when|day/i', $name);
    if ($nameLooksLikeDate) {
        $score += 5;
    }
    $hits = 0;
    foreach ($values as $v) {
        if (impactLooksLikeDate((string) $v, $nameLooksLikeDate)) {
            $hits++;
        }
    }
    $score += (int) round(($hits / max(count($values), 1)) * 10);
    return $score;
}

function impactScoreHeadcountColumn(string $name, array $values): int {
    if (preg_match('/\b(age|year|grade|year_group|class_year|dob|birth)\b/i', $name)) {
        return -20;
    }
    if (preg_match('/\b(id|ref|reference|score|rating|percent|percentage)\b/i', $name)) {
        return -10;
    }

    $score = 0;
    $nameSaysHeadcount = (bool) preg_match('/headcount|attendance|attendees|people|participants|pupils|students|children|child|kids|juniors|families|group_size|class_size|count|present|turnout|capacity/i', $name);
    if ($nameSaysHeadcount) {
        $score += 8;
    }

    $nums = [];
    foreach ($values as $v) {
        $v = trim((string)$v);
        if ($v !== '' && is_numeric($v)) {
            $nums[] = (float)$v;
        }
    }
    if (!$nums) {
        return $score;
    }

    $score += (int)round((count($nums) / max(count($values), 1)) * 10);

    $max = max($nums);
    $avg = array_sum($nums) / count($nums);
    // Typical session headcounts are usually > 15; age/year values cluster under ~18.
    // Skip the penalty when the header explicitly names attendance or headcount.
    if (!$nameSaysHeadcount && $max <= 18 && $avg <= 12) {
        $score -= 15;
    } elseif ($avg >= 5) {
        $score += 3;
    }

    return $score;
}

function impactDetectDelimiter(string $headerLine): string {
    $comma = substr_count($headerLine, ',');
    $semi = substr_count($headerLine, ';');
    $tab = substr_count($headerLine, "\t");
    $best = ',';
    $bestCount = $comma;
    if ($semi > $bestCount) {
        $best = ';';
        $bestCount = $semi;
    }
    if ($tab > $bestCount) {
        $best = "\t";
    }
    return $best;
}

function impactRowsFromDelimited(string $path, string $delimiter = ','): array {
    $handle = fopen($path, 'r');
    if (!$handle) {
        throw new RuntimeException('Could not read the uploaded file.');
    }

    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($handle);
    }

    $headers = fgetcsv($handle, 0, $delimiter, '"', '\\');
    if (!$headers || !is_array($headers)) {
        fclose($handle);
        throw new RuntimeException('The file has no header row.');
    }
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);

    $keys = array_map('impactNormaliseHeader', $headers);
    $rows = [];
    while (($line = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
        if (!is_array($line)) {
            continue;
        }
        $allEmpty = true;
        foreach ($line as $cell) {
            if (trim((string) $cell) !== '') {
                $allEmpty = false;
                break;
            }
        }
        if ($allEmpty) {
            continue;
        }

        $row = [];
        foreach ($keys as $i => $key) {
            if ($key === '') {
                continue;
            }
            $row[$key] = isset($line[$i]) ? trim((string) $line[$i]) : '';
        }
        if ($row) {
            $rows[] = $row;
        }
    }
    fclose($handle);

    return $rows;
}

function impactXlsxColIndex(string $cellRef): int {
    $letters = preg_replace('/[^A-Z]/', '', strtoupper($cellRef));
    $n = 0;
    $len = strlen($letters);
    for ($i = 0; $i < $len; $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return max(0, $n - 1);
}

function impactXlsxDateStyleIndexes(ZipArchive $zip): array {
    $xml = $zip->getFromName('xl/styles.xml');
    if (!$xml) {
        return [];
    }
    $sx = @simplexml_load_string($xml);
    if (!$sx) {
        return [];
    }

    $dateFmtIds = [];
    foreach ([14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 45, 46, 47, 50, 51, 52, 53, 54, 55, 56, 57, 58] as $id) {
        $dateFmtIds[$id] = true;
    }
    if (isset($sx->numFmts)) {
        foreach ($sx->numFmts->numFmt as $nf) {
            $code = strtolower((string) $nf['formatCode']);
            if (preg_match('/[dmy]/', $code) && strpos($code, 'red') === false) {
                $dateFmtIds[(int) $nf['numFmtId']] = true;
            }
        }
    }

    $indexes = [];
    $i = 0;
    if (isset($sx->cellXfs)) {
        foreach ($sx->cellXfs->xf as $xf) {
            $fmtId = (int) $xf['numFmtId'];
            if (isset($dateFmtIds[$fmtId])) {
                $indexes[$i] = true;
            }
            $i++;
        }
    }
    return $indexes;
}

function impactXlsxFirstSheetXml(ZipArchive $zip): ?string {
    $wbXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wbXml && $relsXml) {
        $wb = @simplexml_load_string($wbXml);
        $rels = @simplexml_load_string($relsXml);
        if ($wb && $rels) {
            $wb->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $sheets = $wb->xpath('//m:sheets/m:sheet') ?: [];
            if (!$sheets && isset($wb->sheets->sheet)) {
                $sheets = [];
                foreach ($wb->sheets->sheet as $sheet) {
                    $sheets[] = $sheet;
                }
            }
            $rid = '';
            if ($sheets) {
                $first = $sheets[0];
                $rAttrs = $first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                if ($rAttrs && isset($rAttrs['id'])) {
                    $rid = (string) $rAttrs['id'];
                }
                if ($rid === '') {
                    $rid = (string) $first->attributes('r', true)['id'];
                }
                if ($rid === '') {
                    $rid = (string) $first['id'];
                }
            }
            $targets = [];
            $relNodes = $rels->Relationship;
            if (!$relNodes || count($relNodes) === 0) {
                $rels->registerXPathNamespace('pr', 'http://schemas.openxmlformats.org/package/2006/relationships');
                $relNodes = $rels->xpath('//pr:Relationship') ?: [];
            }
            foreach ($relNodes as $rel) {
                $targets[(string) $rel['Id']] = (string) $rel['Target'];
            }
            if ($rid !== '' && isset($targets[$rid])) {
                $target = str_replace('\\', '/', $targets[$rid]);
                if (strpos($target, '/') === 0) {
                    $path = ltrim($target, '/');
                } elseif (strpos($target, 'xl/') === 0) {
                    $path = $target;
                } else {
                    $path = 'xl/' . ltrim($target, '/');
                }
                $xml = $zip->getFromName($path);
                if ($xml) {
                    return $xml;
                }
            }
        }
    }

    foreach (['xl/worksheets/sheet1.xml', 'xl/worksheets/sheet.xml'] as $fallback) {
        $xml = $zip->getFromName($fallback);
        if ($xml) {
            return $xml;
        }
    }
    return null;
}

function impactRowsFromXlsx(string $path): array {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Excel files need ZipArchive on the server. Save the sheet as CSV and try again.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Could not read the Excel file.');
    }

    $dateStyles = impactXlsxDateStyleIndexes($zip);

    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $ss = @simplexml_load_string($ssXml);
        if ($ss) {
            foreach ($ss->si as $si) {
                $text = '';
                if (isset($si->t)) {
                    $text = (string) $si->t;
                } else {
                    foreach ($si->r as $run) {
                        $text .= (string) $run->t;
                    }
                }
                $shared[] = trim($text);
            }
        }
    }

    $sheetXml = impactXlsxFirstSheetXml($zip);
    $zip->close();
    if (!$sheetXml) {
        throw new RuntimeException('The Excel file has no first sheet.');
    }

    $sheet = @simplexml_load_string($sheetXml);
    if (!$sheet) {
        throw new RuntimeException('Could not parse the Excel sheet.');
    }

    $grid = [];
    $maxCol = 0;
    foreach ($sheet->sheetData->row as $row) {
        $rowNum = (int) $row['r'] - 1;
        if ($rowNum < 0) {
            continue;
        }
        foreach ($row->c as $cell) {
            $ref = (string) $cell['r'];
            $col = impactXlsxColIndex($ref);
            $maxCol = max($maxCol, $col);
            $type = (string) $cell['t'];
            $style = (int) $cell['s'];
            $raw = isset($cell->v) ? (string) $cell->v : '';
            if ($type === 's') {
                $raw = $shared[(int) $raw] ?? '';
            } elseif ($type === 'inlineStr' && isset($cell->is->t)) {
                $raw = (string) $cell->is->t;
            } elseif ($type === 'd') {
                $raw = substr($raw, 0, 10);
            } elseif ($type !== 's' && $type !== 'b' && isset($dateStyles[$style])) {
                $asDate = impactExcelSerialToDate($raw);
                if ($asDate) {
                    $raw = $asDate;
                }
            }
            $grid[$rowNum][$col] = trim($raw);
        }
    }

    if (!$grid) {
        throw new RuntimeException('The Excel sheet is empty.');
    }
    ksort($grid);
    $headerRow = $grid[array_key_first($grid)] ?? [];
    $headers = [];
    for ($i = 0; $i <= $maxCol; $i++) {
        $headers[$i] = impactNormaliseHeader((string) ($headerRow[$i] ?? ''));
    }

    $rows = [];
    $first = true;
    foreach ($grid as $rowCells) {
        if ($first) {
            $first = false;
            continue;
        }
        $row = [];
        $empty = true;
        for ($i = 0; $i <= $maxCol; $i++) {
            $key = $headers[$i];
            if ($key === '') {
                continue;
            }
            $val = (string) ($rowCells[$i] ?? '');
            $row[$key] = $val;
            if ($val !== '') {
                $empty = false;
            }
        }
        if (!$empty) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function impactWordNs(): string {
    return 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
}

function impactDocxDomXPath(string $xml): ?DOMXPath {
    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml)) {
        return null;
    }
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('w', impactWordNs());
    return $xp;
}

function impactDocxNodeText(DOMXPath $xp, DOMNode $node, bool $keepBreaks = false): string {
    $parts = [];
    $walk = function (DOMNode $current) use (&$walk, &$parts, $node): void {
        foreach ($current->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            $local = $child->localName;
            // A table nested inside a cell is read on its own, so skip its text here.
            if ($local === 'tbl' && $child !== $node) {
                continue;
            }
            if ($local === 'tab') {
                $parts[] = "\t";
                continue;
            }
            if ($local === 'br' || $local === 'cr') {
                $parts[] = "\n";
                continue;
            }
            if ($local === 't') {
                $parts[] = $child->textContent;
                continue;
            }
            $walk($child);
        }
    };
    $walk($node);

    $text = str_replace(
        ["\u{00A0}", "\u{202F}", "\u{2007}", "\u{2009}", "\u{200A}", "\u{FEFF}"],
        ' ',
        implode('', $parts)
    );
    if (!$keepBreaks) {
        $text = str_replace(["\r", "\n"], ' ', $text);
    }
    $text = preg_replace('/[^\S\n]+/u', ' ', $text);
    $text = preg_replace('/\n{2,}/', "\n", $text);
    return trim($text);
}

function impactDocxDirect(DOMNode $node, string $local): array {
    $found = [];
    foreach ($node->childNodes as $child) {
        if ($child->nodeType !== XML_ELEMENT_NODE) {
            continue;
        }
        if ($child->localName === $local) {
            $found[] = $child;
            continue;
        }
        // Repeating rows and some Google Docs exports wrap the row in a content control.
        if ($child->localName !== 'sdt') {
            continue;
        }
        foreach ($child->childNodes as $content) {
            if ($content->nodeType !== XML_ELEMENT_NODE || $content->localName !== 'sdtContent') {
                continue;
            }
            foreach ($content->childNodes as $inner) {
                if ($inner->nodeType === XML_ELEMENT_NODE && $inner->localName === $local) {
                    $found[] = $inner;
                }
            }
        }
    }
    return $found;
}

function impactDocxAttr(DOMNode $node, string $name): string {
    if (!$node->attributes) {
        return '';
    }
    foreach ($node->attributes as $attr) {
        if ($attr->localName === $name) {
            return (string) $attr->value;
        }
    }
    return '';
}

function impactDocxGridSpan(DOMXPath $xp, DOMNode $cell): int {
    foreach (impactDocxDirect($cell, 'tcPr') as $props) {
        foreach (impactDocxDirect($props, 'gridSpan') as $span) {
            $count = (int) impactDocxAttr($span, 'val');
            return $count > 0 ? $count : 1;
        }
    }
    return 1;
}

function impactDocxTablesFromXml(string $xml): array {
    $xp = impactDocxDomXPath($xml);
    if (!$xp) {
        return [];
    }
    $tables = [];
    // local-name() so this works for normal Word, Strict Open XML, and files
    // that use a different namespace prefix.
    foreach ($xp->query('//*[local-name()="tbl"]') as $tbl) {
        $grid = [];
        $rowNum = 0;
        foreach (impactDocxDirect($tbl, 'tr') as $tr) {
            $col = 0;
            $cells = [];
            foreach (impactDocxDirect($tr, 'tc') as $tc) {
                $cells[$col] = impactDocxNodeText($xp, $tc);
                $col += impactDocxGridSpan($xp, $tc);
            }
            if ($cells) {
                $grid[$rowNum] = $cells;
                $rowNum++;
            }
        }
        if (count($grid) >= 2) {
            $tables[] = $grid;
        }
    }
    return $tables;
}

function impactDocxParagraphLines(string $xml, bool $includeTables = false): array {
    $xp = impactDocxDomXPath($xml);
    if (!$xp) {
        return [];
    }
    $lines = [];
    foreach ($xp->query('//*[local-name()="p"]') as $p) {
        if (!$includeTables) {
            $inTable = false;
            for ($parent = $p->parentNode; $parent; $parent = $parent->parentNode) {
                if ($parent->nodeType === XML_ELEMENT_NODE && $parent->localName === 'tbl') {
                    $inTable = true;
                    break;
                }
            }
            if ($inTable) {
                continue;
            }
        }
        $text = impactDocxNodeText($xp, $p, true);
        foreach (preg_split('/\n/', $text) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }
    }
    return $lines;
}

function impactRowLooksLikeHeader(array $cells, int $gridWidth = 0): bool {
    $nonEmpty = array_values(array_filter($cells, static fn($cell) => trim((string) $cell) !== ''));
    if (!$nonEmpty) {
        return false;
    }
    // A single merged title cell ("Spring term register") is not a header.
    $width = max($gridWidth, count($cells));
    if (count($nonEmpty) === 1 && $width > 1) {
        return false;
    }
    foreach ($nonEmpty as $cell) {
        // "Date" is a header. "12 March 2026" is a session, even in the first row.
        if (impactLooksLikeDate((string) $cell)) {
            return false;
        }
    }
    return true;
}

function impactKeyedRowsFromGridAt(array $grid, int $headerIndex): array {
    if (!isset($grid[$headerIndex])) {
        return [];
    }
    $maxCol = 0;
    foreach ($grid as $rowCells) {
        if ($rowCells) {
            $maxCol = max($maxCol, (int) max(array_keys($rowCells)));
        }
    }

    $headers = [];
    $used = [];
    for ($i = 0; $i <= $maxCol; $i++) {
        $name = impactNormaliseHeader((string) ($grid[$headerIndex][$i] ?? ''));
        if ($name === '') {
            $headers[$i] = '';
            continue;
        }
        if (isset($used[$name])) {
            $used[$name]++;
            $name .= '_' . $used[$name];
        } else {
            $used[$name] = 1;
        }
        $headers[$i] = $name;
    }

    $rows = [];
    foreach ($grid as $index => $rowCells) {
        if ($index <= $headerIndex) {
            continue;
        }
        $row = [];
        $empty = true;
        for ($i = 0; $i <= $maxCol; $i++) {
            $key = $headers[$i];
            if ($key === '') {
                continue;
            }
            $val = trim((string) ($rowCells[$i] ?? ''));
            $row[$key] = $val;
            if ($val !== '') {
                $empty = false;
            }
        }
        if (!$empty) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function impactKeyedRowsNoHeader(array $grid): array {
    $maxCol = 0;
    foreach ($grid as $rowCells) {
        if ($rowCells) {
            $maxCol = max($maxCol, (int) max(array_keys($rowCells)));
        }
    }

    $dateCol = null;
    $bestHits = 0;
    for ($i = 0; $i <= $maxCol; $i++) {
        $hits = 0;
        $seen = 0;
        foreach ($grid as $rowCells) {
            $val = trim((string) ($rowCells[$i] ?? ''));
            if ($val === '') {
                continue;
            }
            $seen++;
            if (impactLooksLikeDate($val)) {
                $hits++;
            }
        }
        if ($seen > 0 && $hits > $bestHits && ($hits / $seen) >= 0.6) {
            $bestHits = $hits;
            $dateCol = $i;
        }
    }
    if ($dateCol === null) {
        return [];
    }

    $names = [];
    for ($i = 0; $i <= $maxCol; $i++) {
        $names[$i] = $i === $dateCol ? 'session_date' : 'column_' . ($i + 1);
    }

    $rows = [];
    foreach ($grid as $rowCells) {
        $row = [];
        $empty = true;
        for ($i = 0; $i <= $maxCol; $i++) {
            $val = trim((string) ($rowCells[$i] ?? ''));
            $row[$names[$i]] = $val;
            if ($val !== '') {
                $empty = false;
            }
        }
        if (!$empty) {
            $rows[] = $row;
        }
    }
    return $rows;
}

/**
 * Word tables often start with a title row, or have no header at all.
 * Try the first few rows as the header and keep whichever reads as dates.
 */
function impactBestRowsFromGrid(array $grid): array {
    ksort($grid);
    $best = [];
    $bestScore = -1;
    $gridWidth = 0;
    foreach ($grid as $rowCells) {
        if ($rowCells) {
            $gridWidth = max($gridWidth, (int) max(array_keys($rowCells)) + 1);
        }
    }

    $indexes = array_keys($grid);
    foreach (array_slice($indexes, 0, 4) as $index) {
        if (!impactRowLooksLikeHeader($grid[$index] ?? [], $gridWidth)) {
            continue;
        }
        $keyed = impactKeyedRowsFromGridAt($grid, (int) $index);
        $score = impactGridDateScore($keyed);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $keyed;
        }
    }

    if ($bestScore < 4) {
        $plain = impactKeyedRowsNoHeader($grid);
        if (impactGridDateScore($plain) > $bestScore) {
            $best = $plain;
        }
    }
    return $best;
}

function impactRowsFromDelimitedLines(array $lines): array {
    $start = null;
    $delimiter = ',';
    foreach ($lines as $index => $line) {
        $candidate = impactDetectDelimiter($line);
        if (substr_count($line, $candidate) > 0) {
            $start = $index;
            $delimiter = $candidate;
            break;
        }
    }
    if ($start === null) {
        return [];
    }

    $tmp = tempnam(sys_get_temp_dir(), 'impactdocx');
    if (!$tmp) {
        return [];
    }
    file_put_contents($tmp, implode("\n", array_slice($lines, $start)));
    try {
        $rows = impactRowsFromDelimited($tmp, $delimiter);
    } catch (Throwable $e) {
        @unlink($tmp);
        return [];
    }
    @unlink($tmp);
    return $rows;
}

function impactRowsFromProseLines(array $lines): array {
    $rows = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strlen($line) > 240) {
            continue;
        }
        if (!impactParseDate($line)) {
            continue;
        }
        $dateText = null;
        $patterns = [
            '/\d{1,2}(?:st|nd|rd|th)?\s+(?:of\s+)?[A-Za-z]{3,9}(?:\s+\d{2,4})?/i',
            '/[A-Za-z]{3,9}\s+\d{1,2}(?:st|nd|rd|th)?,?\s+\d{4}/',
            '/\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}/',
            '/\d{4}-\d{2}-\d{2}/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line, $match) && impactParseDate($match[0])) {
                $dateText = $match[0];
                break;
            }
        }
        if ($dateText === null) {
            continue;
        }

        $rest = trim(str_replace($dateText, ' ', $line));
        $rest = trim($rest, " \t-:|,;.");
        $row = ['session_date' => $dateText];
        if (preg_match('/\b(\d{1,4})\b/', $rest, $number)) {
            $count = (int) $number[1];
            if ($count >= 1 && $count <= 500) {
                $row['attendance'] = (string) $count;
                $rest = trim(preg_replace('/\b' . preg_quote($number[1], '/') . '\b/', ' ', $rest, 1));
                $rest = trim($rest, " \t-:|,;.");
            }
        }
        if ($rest !== '') {
            $row['details'] = $rest;
        }
        $rows[] = $row;
    }
    return count($rows) >= 2 ? $rows : [];
}

function impactKeyedRowsFromGrid(array $grid): array {
    if (!$grid) {
        return [];
    }
    ksort($grid);
    $maxCol = 0;
    foreach ($grid as $rowCells) {
        if ($rowCells) {
            $maxCol = max($maxCol, (int) max(array_keys($rowCells)));
        }
    }
    $headerRow = $grid[array_key_first($grid)] ?? [];
    $headers = [];
    for ($i = 0; $i <= $maxCol; $i++) {
        $headers[$i] = impactNormaliseHeader((string) ($headerRow[$i] ?? ''));
    }

    $rows = [];
    $first = true;
    foreach ($grid as $rowCells) {
        if ($first) {
            $first = false;
            continue;
        }
        $row = [];
        $empty = true;
        for ($i = 0; $i <= $maxCol; $i++) {
            $key = $headers[$i];
            if ($key === '') {
                continue;
            }
            $val = (string) ($rowCells[$i] ?? '');
            $row[$key] = $val;
            if ($val !== '') {
                $empty = false;
            }
        }
        if (!$empty) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function impactGridDateScore(array $keyedRows): int {
    if (!$keyedRows) {
        return -1;
    }
    $columns = array_keys($keyedRows[0]);
    $best = -1;
    foreach ($columns as $col) {
        $values = array_map(static function ($r) use ($col) {
            return $r[$col] ?? '';
        }, $keyedRows);
        $best = max($best, impactScoreDateColumn($col, $values));
    }
    return $best;
}

function impactXmlToPlainText(string $xml): string {
    if (strlen($xml) > 4000000) {
        $xml = substr($xml, 0, 4000000);
    }
    $extra = [];
    if (preg_match_all('/fullDate="([^"]+)"/', $xml, $found)) {
        $extra = $found[1];
    }
    $xml = preg_replace('/<[^>]*:tab\b[^>]*\/>/', "\t", $xml);
    $xml = preg_replace('/<[^>]*:br\b[^>]*\/>/', "\n", $xml);
    $xml = preg_replace('/<\/[^>]*(?::p|:tr|p|tr|li)>/i', "\n", $xml);
    $xml = preg_replace('/<[^>]*(?::tc|:td|td|th)\b[^>]*>/i', "\t", $xml);
    $xml = preg_replace_callback(
        '/<[^>]*(?::t|:v)\b[^>]*>(.*?)<\/[^>]*(?::t|:v)>/s',
        static function ($match) {
            return html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        },
        $xml
    );
    $xml = preg_replace('/<[^>]+>/', ' ', $xml);
    $xml = html_entity_decode($xml, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($extra) {
        $xml .= "\n" . implode("\n", $extra);
    }
    return $xml;
}

function impactDateMatchPattern(): string {
    return '/(?:\d{4}-\d{2}-\d{2}|\d{1,2}(?:st|nd|rd|th)?\s*[\.\/\-]\s*\d{1,2}\s*[\.\/\-]\s*\d{2,4}|\d{1,2}(?:st|nd|rd|th)?\s+(?:of\s+)?[A-Za-z]{3,9}(?:\s+\d{2,4})?|[A-Za-z]{3,9}\s+\d{1,2}(?:st|nd|rd|th)?,?\s+\d{4})/i';
}

/**
 * Last resort: pull every date out of the document text, wherever it sits
 * (a long paragraph, a text box, a header, or an HTML chunk).
 */
function impactRowsFromDateScan(string $text): array {
    $text = str_replace(["\u{00A0}", "\u{202F}", "\u{2007}", "\u{2009}"], ' ', $text);
    if (!preg_match_all(impactDateMatchPattern(), $text, $matches, PREG_OFFSET_CAPTURE)) {
        return [];
    }

    $rows = [];
    $count = count($matches[0]);
    $lastParsed = null;
    $lastPos = -1000;
    for ($i = 0; $i < $count; $i++) {
        $raw = $matches[0][$i][0];
        $pos = $matches[0][$i][1];
        $parsed = impactParseDate($raw);
        if (!$parsed) {
            continue;
        }
        // Word stores the same date twice (visible text plus a date field).
        if ($parsed === $lastParsed && ($pos - $lastPos) < 80) {
            $lastPos = $pos;
            continue;
        }
        $lastParsed = $parsed;
        $lastPos = $pos;

        $nextPos = ($i + 1 < $count) ? $matches[0][$i + 1][1] : strlen($text);
        $window = substr($text, $pos + strlen($raw), max(0, min($nextPos, $pos + strlen($raw) + 80) - ($pos + strlen($raw))));
        $lineEnd = strpos($window, "\n");
        if ($lineEnd !== false) {
            $window = substr($window, 0, $lineEnd);
        }

        $row = ['session_date' => trim($raw)];
        if (preg_match('/\b(\d{1,4})\b/', $window, $number)) {
            $headcount = (int) $number[1];
            if ($headcount >= 1 && $headcount <= 500) {
                $row['attendance'] = (string) $headcount;
            }
        }
        $rows[] = $row;
    }
    return $rows;
}

function impactMonthNumber(string $name): ?int {
    $months = [
        'jan' => 1, 'january' => 1,
        'feb' => 2, 'february' => 2,
        'mar' => 3, 'march' => 3,
        'apr' => 4, 'april' => 4,
        'may' => 5,
        'jun' => 6, 'june' => 6,
        'jul' => 7, 'july' => 7,
        'aug' => 8, 'august' => 8,
        'sep' => 9, 'sept' => 9, 'september' => 9,
        'oct' => 10, 'october' => 10,
        'nov' => 11, 'november' => 11,
        'dec' => 12, 'december' => 12,
    ];
    $key = strtolower(trim($name));
    return $months[$key] ?? null;
}

/**
 * A newsletter or annual summary often has no session list, only a period and
 * totals ("August 2025 – June 2026", "35 sessions", "311 child attendances").
 * Spread those totals across the period so the report can use them, and say
 * plainly that the individual dates were not in the file.
 */
function impactRowsFromSummaryDocument(string $text): array {
    $text = str_replace(["\u{00A0}", "\u{2013}", "\u{2014}"], [' ', '-', '-'], $text);
    $text = preg_replace('/\s+/u', ' ', $text);

    $month = 'january|february|march|april|may|june|july|august|september|october|november|december|jan|feb|mar|apr|jun|jul|aug|sep|sept|oct|nov|dec';
    if (!preg_match('/\b(' . $month . ')\s+(\d{4})\s*(?:-|to)\s*(' . $month . ')\s+(\d{4})\b/i', $text, $range)) {
        return [];
    }
    $startMonth = impactMonthNumber($range[1]);
    $endMonth = impactMonthNumber($range[3]);
    $startYear = (int) $range[2];
    $endYear = (int) $range[4];
    if (!$startMonth || !$endMonth) {
        return [];
    }

    $sessionCount = 0;
    if (preg_match('/\b(\d+)\s+sessions?\s+delivered\b/i', $text, $found)
        || preg_match('/\bsessions?\s+delivered\s+(\d+)\b/i', $text, $found)
        || preg_match('/\bdelivered\s+(\d+)\s+sessions?\b/i', $text, $found)) {
        $sessionCount = (int) $found[1];
    }
    if ($sessionCount < 2 || $sessionCount > 400) {
        return [];
    }

    $childTotal = null;
    if (preg_match('/\b(\d+)\s+child\s+attendances?\b/i', $text, $found)
        || preg_match('/\bchild\s+attendances?\s+(\d+)\b/i', $text, $found)) {
        $childTotal = (int) $found[1];
    }
    $average = null;
    if (preg_match('/~?\s*(\d+(?:\.\d+)?)\s+children\s+per\s+session/i', $text, $found)) {
        $average = (float) $found[1];
    }
    if ($childTotal === null && $average !== null) {
        $childTotal = (int) round($average * $sessionCount);
    }
    if ($childTotal === null || $childTotal < 1) {
        return [];
    }

    $adultTotal = null;
    if (preg_match('/\b(\d+)\s+adult\s+attendances?\b/i', $text, $found)) {
        $adultTotal = (int) $found[1];
    }

    $start = sprintf('%04d-%02d-01', $startYear, $startMonth);
    $endStamp = strtotime(sprintf('%04d-%02d-01', $endYear, $endMonth));
    if ($endStamp === false) {
        return [];
    }
    $end = date('Y-m-t', $endStamp);
    $startStamp = strtotime($start);
    $endStamp = strtotime($end);
    if ($startStamp === false || $endStamp === false || $endStamp < $startStamp) {
        return [];
    }

    $base = intdiv($childTotal, $sessionCount);
    $remainder = $childTotal - ($base * $sessionCount);
    $periodLabel = $range[1] . ' ' . $startYear . ' - ' . $range[3] . ' ' . $endYear;
    $note = 'This file is a summary, not a register of individual sessions. '
        . $sessionCount . ' sessions and ' . $childTotal . ' child attendances were spread evenly across ' . $periodLabel . '.';
    if ($adultTotal !== null) {
        $note .= ' The document also reports ' . $adultTotal . ' adult attendances.';
    }

    $rows = [];
    for ($i = 0; $i < $sessionCount; $i++) {
        $offset = $sessionCount === 1 ? 0 : (int) round(($endStamp - $startStamp) * $i / ($sessionCount - 1));
        $rows[] = [
            'session_date' => date('Y-m-d', $startStamp + $offset),
            'attendance' => (string) ($base + ($i < $remainder ? 1 : 0)),
            'data_note' => $note,
        ];
    }
    return $rows;
}

function impactConsiderRows(array $rows, array &$bestRows, int &$bestScore): void {
    $score = impactGridDateScore($rows);
    if ($score < 4) {
        return;
    }
    if ($score > $bestScore || ($score === $bestScore && count($rows) > count($bestRows))) {
        $bestScore = $score;
        $bestRows = $rows;
    }
}

function impactRowsFromDocx(string $path): array {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Word files need ZipArchive on the server. Save the document as CSV or Excel and try again.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Could not read the Word file.');
    }

    $bestRows = [];
    $bestScore = -1;
    $plainText = '';

    // A Word file sometimes contains the real table as an embedded spreadsheet,
    // with only a preview in the document itself.
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xlsm'], true)) {
            continue;
        }
        $embedded = $zip->getFromIndex($i);
        if (!$embedded || strlen($embedded) < 64) {
            continue;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'impactxlsx');
        if (!$tmp) {
            continue;
        }
        file_put_contents($tmp, $embedded);
        try {
            impactConsiderRows(impactRowsFromXlsx($tmp), $bestRows, $bestScore);
        } catch (Throwable $e) {
            // This embedded file is not a readable sheet. Keep looking.
        }
        @unlink($tmp);
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $isDocumentPart = (bool) preg_match('#^(word/|word/header|word/footer|word/footnotes|word/endnotes|word/comments)#', $name)
            || strpos($name, 'word/') === 0;
        if (!$isDocumentPart || !in_array($ext, ['xml', 'html', 'htm'], true)) {
            continue;
        }
        // Styles and themes contain no session data and can add noise.
        if (preg_match('#/(styles|fontTable|settings|webSettings|theme/|numbering)\.#', $name)) {
            continue;
        }
        $xml = $zip->getFromIndex($i);
        if (!$xml) {
            continue;
        }

        foreach (impactDocxTablesFromXml($xml) as $grid) {
            impactConsiderRows(impactBestRowsFromGrid($grid), $bestRows, $bestScore);
        }
        foreach ([false, true] as $includeTables) {
            $lines = impactDocxParagraphLines($xml, $includeTables);
            impactConsiderRows(impactRowsFromDelimitedLines($lines), $bestRows, $bestScore);
            impactConsiderRows(impactRowsFromProseLines($lines), $bestRows, $bestScore);
        }
        $partText = impactXmlToPlainText($xml);
        $plainText .= "\n" . $partText;
        impactConsiderRows(impactRowsFromDateScan($partText), $bestRows, $bestScore);
    }

    if ($bestScore < 4) {
        impactConsiderRows(impactRowsFromSummaryDocument($plainText), $bestRows, $bestScore);
    }
    $zip->close();

    if ($bestScore >= 4 && $bestRows) {
        return $bestRows;
    }

    throw new RuntimeException(
        'Could not find session dates in the Word file. Use a table with a header row (for example Date and Attendance), or one line per session such as "12 March 2026 - 14 children". You can also save it as CSV or Excel.'
    );
}

function impactRowsFromFile(string $path, string $ext): array {
    $ext = strtolower($ext);
    if ($ext === 'xls') {
        throw new RuntimeException('Older Excel (.xls) files are not supported. Save the sheet as .xlsx or CSV and try again.');
    }
    if ($ext === 'doc') {
        throw new RuntimeException('Older Word (.doc) files are not supported. Save the document as .docx or CSV and try again.');
    }
    if ($ext === 'xlsx' || $ext === 'xlsm') {
        return impactRowsFromXlsx($path);
    }
    if ($ext === 'docx') {
        return impactRowsFromDocx($path);
    }
    if (!in_array($ext, ['csv', 'tsv', 'txt'], true)) {
        throw new RuntimeException(impactUploadHelpMessage());
    }

    $sample = file_get_contents($path, false, null, 0, 4096);
    if ($sample === false) {
        throw new RuntimeException('Could not read the uploaded file.');
    }
    $sample = preg_replace('/^\xEF\xBB\xBF/', '', $sample);
    $firstLine = strtok($sample, "\r\n") ?: $sample;
    $delimiter = $ext === 'tsv' ? "\t" : impactDetectDelimiter($firstLine);
    return impactRowsFromDelimited($path, $delimiter);
}

function impactParseUpload(string $path, string $ext): array {
    $ext = strtolower($ext);
    $rows = impactRowsFromFile($path, $ext);

    if (!$rows) {
        throw new RuntimeException('No session rows found in the file.');
    }

    $columns = array_keys($rows[0]);
    $columnValues = [];
    foreach ($columns as $col) {
        $columnValues[$col] = array_map(static fn($r) => $r[$col] ?? '', $rows);
    }

    $dateCol = null;
    $dateScore = -1;
    $headcountCol = null;
    $headcountScore = -1;

    foreach ($columns as $col) {
        $dScore = impactScoreDateColumn($col, $columnValues[$col]);
        if ($dScore > $dateScore) {
            $dateScore = $dScore;
            $dateCol = $col;
        }
        $hScore = impactScoreHeadcountColumn($col, $columnValues[$col]);
        if ($hScore > $headcountScore) {
            $headcountScore = $hScore;
            $headcountCol = $col;
        }
    }

    if ($headcountScore < 4) {
        $headcountCol = null;
    }

    if (!$dateCol || $dateScore < 4) {
        throw new RuntimeException(
            'Could not find a date column. Include a column like session_date or date with valid dates.'
        );
    }

    $fieldMap = [];
    foreach ($columns as $col) {
        if ($col === $dateCol) {
            $fieldMap[$col] = 'date';
            continue;
        }
        if ($col === $headcountCol) {
            $fieldMap[$col] = 'numeric';
            continue;
        }
        $fieldMap[$col] = impactInferFieldType($columnValues[$col]);
    }

    $sessions = [];
    foreach ($rows as $row) {
        $sessionDate = impactParseDate((string)($row[$dateCol] ?? ''));
        if (!$sessionDate) {
            continue;
        }

        $headcount = null;
        if ($headcountCol && isset($row[$headcountCol]) && is_numeric(trim((string)$row[$headcountCol]))) {
            $headcount = (int)round((float)$row[$headcountCol]);
        }

        $attributes = [];
        foreach ($row as $key => $value) {
            if ($key === $dateCol || $key === $headcountCol) {
                continue;
            }
            if (trim((string)$value) === '') {
                continue;
            }
            $type = $fieldMap[$key] ?? 'text';
            if ($type === 'boolean') {
                $attributes[$key] = impactIsTruthy((string)$value);
            } elseif ($type === 'numeric' && is_numeric($value)) {
                $attributes[$key] = (float)$value;
            } else {
                $attributes[$key] = (string)$value;
            }
        }

        $sessions[] = [
            'session_date' => $sessionDate,
            'headcount' => $headcount,
            'attributes' => $attributes,
        ];
    }

    if (!$sessions) {
        throw new RuntimeException('No valid session rows with parseable dates were found.');
    }

    usort($sessions, static fn($a, $b) => strcmp($a['session_date'], $b['session_date']));

    $numericFields = [];
    foreach ($fieldMap as $name => $type) {
        if ($type === 'numeric' && $name !== $headcountCol) {
            $numericFields[] = $name;
        }
    }

    return [
        'sessions' => $sessions,
        'field_map' => $fieldMap,
        'detected' => [
            'date_column' => $dateCol,
            'headcount_column' => $headcountCol,
            'numeric_fields' => $numericFields,
        ],
    ];
}
