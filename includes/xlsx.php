<?php
/**
 * Excel (.xlsx) export without any libraries: builds the workbook with ZipArchive and sends it as a download.
 *
 *   xlsx_download('Transactions 2026-11-08', [[
 *       'name'    => 'Transactions',                       // sheet tab (max 31 chars)
 *       'title'   => 'Transaction History',                // optional bold first line
 *       'meta'    => ['Date: 08 Nov 2026'],                // optional lines under the title
 *       'columns' => ['Item', ['Qty', 'int'], ['Value (K)', 'money']],   // type: text (default), int, money
 *       'rows'    => [['Toner', 2, 3200.0], ...],
 *       'totals'  => ['Total', 2, 3200.0],                 // optional bold last row
 *   ]]);
 *
 * Cell text is always written as text, never as a formula, so "=..." in data can't run in Excel.
 */

/** Is this request asking for the Excel version of the page? */
function wants_xlsx(): bool {
    return ($_GET['export'] ?? '') === 'xlsx';
}

/** "Export to Excel" button: the current page and filters, plus export=xlsx. */
function xlsx_button(array $extra = []): string {
    $q = array_merge($_GET, $extra, ['export' => 'xlsx']);
    $href = strtok($_SERVER['REQUEST_URI'] ?? '', '?') . '?' . http_build_query($q);
    return '<a href="' . htmlspecialchars($href) . '" class="btn btn-sm btn-excel" download>'
         . '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="19"/><line x1="16" y1="13" x2="8" y2="19"/></svg>'
         . ' Export to Excel</a>';
}

function xlsx_esc(string $s): string {
    // Drop characters XML 1.0 doesn't allow
    $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s) ?? '';
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** Column letters: 0 => A, 26 => AA. */
function xlsx_col(int $i): string {
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
    return $s;
}

/** One sheet's XML. Styles: 0 normal, 1 bold, 2 whole number, 3 money, 4 bold whole number, 5 bold money, 6 header, 7 title. */
function xlsx_sheet_xml(array $sheet): string {
    $cols  = array_map(fn($c) => is_array($c) ? $c : [$c, 'text'], $sheet['columns'] ?? []);
    $types = array_column($cols, 1);
    $rows  = []; $widths = [];
    $r = 0;
    $cell = function (int $c, $v, int $style = 0) use (&$r, &$widths): string {
        $ref = xlsx_col($c) . $r;
        $len = is_string($v) ? mb_strlen($v) : strlen(number_format((float)$v, 2));
        $widths[$c] = max($widths[$c] ?? 0, $len);
        if ($v === null || $v === '') return '';
        if (is_int($v) || is_float($v)) return '<c r="' . $ref . '" s="' . $style . '"><v>' . (is_float($v) ? round($v, 2) : $v) . '</v></c>';
        return '<c r="' . $ref . '" t="inlineStr" s="' . $style . '"><is><t xml:space="preserve">' . xlsx_esc((string)$v) . '</t></is></c>';
    };
    $num_style = fn(int $c, bool $bold) => match ($types[$c] ?? 'text') { 'int' => $bold ? 4 : 2, 'money' => $bold ? 5 : 3, default => $bold ? 1 : 0 };
    $value = function ($v, string $type) {
        if ($v === null || $v === '') return '';
        if ($type === 'int' && is_numeric($v)) return (int)$v;
        if ($type === 'money' && is_numeric($v)) return (float)$v;
        return (string)$v;
    };

    if (!empty($sheet['title'])) { $r++; $rows[] = '<row r="' . $r . '">' . $cell(0, (string)$sheet['title'], 7) . '</row>'; $widths[0] = 0; }
    foreach ($sheet['meta'] ?? [] as $m) { $r++; $rows[] = '<row r="' . $r . '">' . $cell(0, (string)$m) . '</row>'; $widths[0] = 0; }
    if ($r) { $r++; $rows[] = '<row r="' . $r . '"/>'; }
    $r++; $header_row = $r;
    $rows[] = '<row r="' . $r . '">' . implode('', array_map(fn($i) => $cell($i, $cols[$i][0], 6), array_keys($cols))) . '</row>';
    foreach ($sheet['rows'] ?? [] as $row) {
        $r++;
        $row = array_values($row);
        $out = '';
        foreach ($row as $i => $v) $out .= $cell($i, $value($v, $types[$i] ?? 'text'), $num_style($i, false));
        $rows[] = '<row r="' . $r . '">' . $out . '</row>';
    }
    if (!empty($sheet['totals'])) {
        $r++;
        $out = '';
        foreach (array_values($sheet['totals']) as $i => $v) $out .= $cell($i, $value($v, $types[$i] ?? 'text'), $num_style($i, true));
        $rows[] = '<row r="' . $r . '">' . $out . '</row>';
    }

    $colxml = '';
    foreach ($cols as $i => $_) {
        $w = min(60, max(10, ($widths[$i] ?? 8) + 2));
        $colxml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
    }
    $last = xlsx_col(max(0, count($cols) - 1));
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $header_row . '" topLeftCell="A' . ($header_row + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . ($colxml ? '<cols>' . $colxml . '</cols>' : '')
        . '<sheetData>' . implode('', $rows) . '</sheetData>'
        . ($cols && ($sheet['rows'] ?? []) ? '<autoFilter ref="A' . $header_row . ':' . $last . ($header_row + count($sheet['rows'])) . '"/>' : '')
        . '</worksheet>';
}

/** Build the workbook and send it to the browser, then stop. */
function xlsx_download(string $filename, array $sheets): never {
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        exit('Excel export needs the PHP zip extension. Ask IT to enable it.');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);

    $names = []; $sheet_entries = ''; $rels = ''; $types = '';
    foreach (array_values($sheets) as $i => $sheet) {
        $n = $i + 1;
        $name = mb_substr(trim(preg_replace('/[\[\]:*?\/\\\\]/', ' ', (string)($sheet['name'] ?? "Sheet$n"))), 0, 31) ?: "Sheet$n";
        while (in_array(mb_strtolower($name), $names, true)) $name = mb_substr($name, 0, 28) . " $n";
        $names[] = mb_strtolower($name);
        $zip->addFromString("xl/worksheets/sheet$n.xml", xlsx_sheet_xml($sheet));
        $sheet_entries .= '<sheet name="' . xlsx_esc($name) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $rels  .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        $types .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $n = count($sheets) + 1;
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . $types . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . $sheet_entries . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels
        . '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="14"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFE8EBF6"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border/><border><bottom style="thin"><color rgb="FF25398D"/></bottom></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="8">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="3" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
        . '<xf numFmtId="4" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '</cellXfs></styleSheet>');
    $zip->close();

    $safe = trim(preg_replace('/[^\w\- ]+/', '', $filename)) ?: 'Export';
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $safe . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($tmp);
    unlink($tmp);
    exit();
}
