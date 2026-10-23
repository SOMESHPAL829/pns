<?php
declare(strict_types=1);

require_once __DIR__ . '/oee_export_sources.php';

function oee_xml_text(mixed $value): string
{
    $text = htmlspecialchars((string) ($value ?? ''), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    // Database strings can contain characters XML 1.0 forbids (for example NUL).
    return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
}

function oee_xlsx_cell(string $reference, mixed $value = '', int $style = 0, ?string $formula = null): string
{
    if ($formula !== null) {
        // OOXML stores formulas without the leading '=' used by spreadsheet UIs.
        $formula = ltrim($formula, '=');
        return '<c r="' . $reference . '" s="' . $style . '"' . (is_string($value) ? ' t="str"' : '') . '><f>' . oee_xml_text($formula) . '</f><v>' . oee_xml_text($value) . '</v></c>';
    }
    if (is_int($value) || is_float($value)) {
        return '<c r="' . $reference . '" s="' . $style . '"><v>' . oee_xml_text($value) . '</v></c>';
    }
    return '<c r="' . $reference . '" s="' . $style . '" t="inlineStr"><is><t>' . oee_xml_text($value) . '</t></is></c>';
}

function oee_xlsx_row(int $rowNumber, array $cells): string
{
    return '<row r="' . $rowNumber . '">' . implode('', $cells) . '</row>';
}

/** Raw database inputs plus cached results of the same formulas used in Excel. */
function oee_export_line_calculation(array $line): array
{
    $values = [];
    foreach (['window_minutes', 'planned_stop_minutes', 'unplanned_minutes', 'ideal_output_minutes', 'productive_fb', 'good_fb', 'calendar_minutes'] as $key) {
        if (!isset($line[$key]) || !is_numeric($line[$key]) || !is_finite((float) $line[$key]) || (float) $line[$key] < 0) {
            throw new InvalidArgumentException('Excel calculation input missing or invalid: ' . $key);
        }
        $values[$key] = (float) $line[$key];
    }
    $values['planned_minutes'] = max(0.0, $values['window_minutes'] - $values['planned_stop_minutes']);
    $values['run_minutes'] = max(0.0, $values['planned_minutes'] - $values['unplanned_minutes']);
    return $values;
}

function oee_export_xlsx(array $report): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Excel export is not available on this server.');
    }
    $sources = oee_export_sources($report);
    $lines = $sources['lines'];
    $calculations = array_map('oee_export_line_calculation', $lines);
    $totals = [];
    foreach ($calculations as $calculation) {
        foreach ($calculation as $key => $value) $totals[$key] = ($totals[$key] ?? 0.0) + $value;
    }
    $ratio = static fn (array $values, string $numerator, string $denominator): float => ($values[$denominator] ?? 0) > 0 ? $values[$numerator] / $values[$denominator] : 0.0;
    $availability = $ratio($totals, 'run_minutes', 'planned_minutes');
    $performance = $ratio($totals, 'ideal_output_minutes', 'run_minutes');
    $quality = $ratio($totals, 'good_fb', 'productive_fb');
    $temporary = tempnam(sys_get_temp_dir(), 'pns-oee-');
    if ($temporary === false) throw new RuntimeException('Could not create the Excel export.');
    $zip = new ZipArchive();
    if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($temporary);
        throw new RuntimeException('Could not create the Excel workbook.');
    }

    $rows = $report['rows'] ?? [];
    $sheetRows = [];
    $summaryRows = [
        1 => [oee_xlsx_cell('A1', 'PNS OEE report', 1), oee_xlsx_cell('H1', 'Calculation inputs', 1), oee_xlsx_cell('I1', 'Value', 1)],
        2 => [oee_xlsx_cell('A2', 'Report date'), oee_xlsx_cell('B2', $report['date'] ?? '')],
        3 => [oee_xlsx_cell('A3', 'Line / machine'), oee_xlsx_cell('B3', ($report['machine'] ?? '') === 'ALL' ? 'All lines' : ($report['machine'] ?? ''))],
        4 => [oee_xlsx_cell('A4', 'Blue values = database inputs. Black values = Excel calculations.')],
        5 => [oee_xlsx_cell('A5', 'Aggregate calculation', 1), oee_xlsx_cell('B5', 'Value', 1), oee_xlsx_cell('C5', 'Formula', 1)],
        6 => [oee_xlsx_cell('A6', 'Availability'), oee_xlsx_cell('B6', $availability, 2, 'IF(I2>0,I4/I2,0)'), oee_xlsx_cell('C6', 'Run time / planned production time')],
        7 => [oee_xlsx_cell('A7', 'Performance'), oee_xlsx_cell('B7', $performance, 2, 'IF(I4>0,I5/I4,0)'), oee_xlsx_cell('C7', 'Ideal output time / run time')],
        8 => [oee_xlsx_cell('A8', 'Quality'), oee_xlsx_cell('B8', $quality, 2, 'IF(I6>0,I7/I6,0)'), oee_xlsx_cell('C8', 'Good FB / productive FB')],
        9 => [oee_xlsx_cell('A9', 'OEE'), oee_xlsx_cell('B9', $availability * $performance * $quality, 2, 'B6*B7*B8'), oee_xlsx_cell('C9', 'Availability × Performance × Quality')],
        10 => [oee_xlsx_cell('A10', '24h utilization'), oee_xlsx_cell('B10', $ratio($totals, 'run_minutes', 'calendar_minutes'), 2, 'IF(I8>0,I4/I8,0)'), oee_xlsx_cell('C10', 'Run time / summed calendar minutes')],
        11 => [oee_xlsx_cell('A11', 'Calculation sources: FB rows below, Downtime, Downtime calculation, Quality. Edit blue source cells to recalculate.')],
    ];
    $inputRows = [
        2 => ['Planned production (min)', 'I', 'planned_minutes'],
        3 => ['Unplanned downtime (min)', 'J', 'unplanned_minutes'],
        4 => ['Run time (min)', 'K', 'run_minutes'],
        5 => ['Ideal output time (min)', 'L', 'ideal_output_minutes'],
        6 => ['Productive FB', 'M', 'productive_fb'],
        7 => ['Good FB', 'N', 'good_fb'],
        8 => ['Calendar time (min)', 'O', 'calendar_minutes'],
        9 => ['Planned stop (min)', 'H', 'planned_stop_minutes'],
        10 => ['Window time (min)', 'G', 'window_minutes'],
    ];
    $lastLineRow = 12 + count($lines);
    foreach ($inputRows as $number => [$label, $column, $key]) {
        $summaryRows[$number][] = oee_xlsx_cell('H' . $number, $label);
        $summaryRows[$number][] = oee_xlsx_cell('I' . $number, $totals[$key] ?? 0.0, 3, $lines ? 'SUM(' . $column . '13:' . $column . $lastLineRow . ')' : '0');
    }
    ksort($summaryRows);
    foreach ($summaryRows as $number => $cells) $sheetRows[] = oee_xlsx_row($number, $cells);
    $lineHeaders = ['Line-wise OEE', 'Availability', 'Performance', 'Quality', 'OEE', '24h utilization', 'Window (min)', 'Planned stop (min)', 'Planned production (min)', 'Unplanned (min)', 'Run time (min)', 'Ideal output (min)', 'Productive FB', 'Good FB', 'Calendar (min)', 'Window start', 'Window end', 'Rejected FB', 'Reworked FB'];
    $headerCells = [];
    foreach ($lineHeaders as $index => $label) $headerCells[] = oee_xlsx_cell(chr(65 + $index) . '12', $label, 1);
    $sheetRows[] = oee_xlsx_row(12, $headerCells);
    $lineRow = 13;
    $firstDetail = 16 + count($lines);
    $lastDetail = max($firstDetail, $firstDetail + count($rows) - 1);
    $detailLines = '$A$' . $firstDetail . ':$A$' . $lastDetail;
    $lastSegment = $sources['last_segment'];
    $lastQuality = $sources['last_quality'];
    foreach ($lines as $index => $line) {
        $values = $calculations[$index];
        $a = $ratio($values, 'run_minutes', 'planned_minutes');
        $p = $ratio($values, 'ideal_output_minutes', 'run_minutes');
        $q = $ratio($values, 'good_fb', 'productive_fb');
        $sheetRows[] = oee_xlsx_row($lineRow, [
            oee_xlsx_cell('A' . $lineRow, $line['line_code']),
            oee_xlsx_cell('B' . $lineRow, $a, 2, "IF(I$lineRow>0,K$lineRow/I$lineRow,0)"),
            oee_xlsx_cell('C' . $lineRow, $p, 2, "IF(K$lineRow>0,L$lineRow/K$lineRow,0)"),
            oee_xlsx_cell('D' . $lineRow, $q, 2, "IF(M$lineRow>0,N$lineRow/M$lineRow,0)"),
            oee_xlsx_cell('E' . $lineRow, $a * $p * $q, 2, "B$lineRow*C$lineRow*D$lineRow"),
            oee_xlsx_cell('F' . $lineRow, $ratio($values, 'run_minutes', 'calendar_minutes'), 2, "IF(O$lineRow>0,K$lineRow/O$lineRow,0)"),
            oee_xlsx_cell('G' . $lineRow, $values['window_minutes'], 3, "O$lineRow"),
            oee_xlsx_cell('H' . $lineRow, $values['planned_stop_minutes'], 3, "ROUND(SUMIFS('Downtime calculation'!\$G\$2:\$G\$$lastSegment,'Downtime calculation'!\$A\$2:\$A\$$lastSegment,A$lineRow),3)"),
            oee_xlsx_cell('I' . $lineRow, $values['planned_minutes'], 3, "MAX(0,G$lineRow-H$lineRow)"),
            oee_xlsx_cell('J' . $lineRow, $values['unplanned_minutes'], 3, "ROUND(SUMIFS('Downtime calculation'!\$F\$2:\$F\$$lastSegment,'Downtime calculation'!\$A\$2:\$A\$$lastSegment,A$lineRow),3)"),
            oee_xlsx_cell('K' . $lineRow, $values['run_minutes'], 3, "MAX(0,I$lineRow-J$lineRow)"),
            oee_xlsx_cell('L' . $lineRow, $values['ideal_output_minutes'], 3, "ROUND(SUMIFS(\$N\$$firstDetail:\$N\$$lastDetail,$detailLines,A$lineRow),3)"),
            oee_xlsx_cell('M' . $lineRow, $values['productive_fb'], 0, "SUMIFS(\$M\$$firstDetail:\$M\$$lastDetail,$detailLines,A$lineRow)"),
            oee_xlsx_cell('N' . $lineRow, $values['good_fb'], 0, "MAX(0,M$lineRow-R$lineRow-S$lineRow)"),
            oee_xlsx_cell('O' . $lineRow, $values['calendar_minutes'], 3, "ROUND(MAX(0,Q$lineRow-P$lineRow)*1440,3)"),
            oee_xlsx_cell('P' . $lineRow, $line['excel_start'], 6),
            oee_xlsx_cell('Q' . $lineRow, $line['excel_end'], 6),
            oee_xlsx_cell('R' . $lineRow, $line['rejected_fb'], 0, "SUMIFS('Quality'!\$C\$2:\$C\$$lastQuality,'Quality'!\$A\$2:\$A\$$lastQuality,A$lineRow)"),
            oee_xlsx_cell('S' . $lineRow, $line['reworked_fb'], 0, "SUMIFS('Quality'!\$D\$2:\$D\$$lastQuality,'Quality'!\$A\$2:\$A\$$lastQuality,A$lineRow)"),
        ]);
        $lineRow++;
    }
    $detailHeader = $lineRow + 2;
    $headers = ['Line', 'SI', 'Sequence', 'Part', 'Grade', 'Colour', 'Loading', 'Unloading', 'PCS', 'Total SA', 'State', 'Applied cycle min / FB', 'Productive flag', 'Ideal output min', 'Cycle-time notes'];
    $headerCells = [];
    foreach ($headers as $index => $header) $headerCells[] = oee_xlsx_cell(chr(65 + $index) . $detailHeader, $header, 1);
    $sheetRows[] = oee_xlsx_row($detailHeader, $headerCells);
    $detailRow = $detailHeader + 1;
    foreach ($rows as $item) {
        $productive = (int) ($item['pcs'] ?? 0) > 0 ? 1 : 0;
        $values = [$item['line_code'] ?? '', $item['si_no'] ?? '', $item['seq'] ?? '', $item['part_code'] ?? '', $item['grade'] ?? '', ($item['used_colour'] ?? '') ?: ($item['report_colour'] ?? ''), $item['loading_at'] ?? '', $item['unloading_at'] ?? '', (int) ($item['pcs'] ?? 0), ($item['total_surface_area'] ?? null) === null ? '' : (float) $item['total_surface_area']];
        $cells = [];
        foreach ($values as $index => $value) $cells[] = oee_xlsx_cell(chr(65 + $index) . $detailRow, $value, is_numeric($value) && $index > 0 && $index < 10 ? 3 : 0);
        $cycle = $productive ? (float) ($item['cycle_minutes'] ?? 0) : 0.0;
        $flags = is_array($item['flags'] ?? null) ? $item['flags'] : json_decode((string) ($item['flags'] ?? '{}'), true);
        $cells[] = oee_xlsx_cell('K' . $detailRow, $productive ? 'Productive' : 'Empty', 0, "IF(M$detailRow=1,\"Productive\",\"Empty\")");
        $cells[] = oee_xlsx_cell('L' . $detailRow, $cycle, 4);
        $cells[] = oee_xlsx_cell('M' . $detailRow, $productive, 0, "IF(I$detailRow>0,1,0)");
        $cells[] = oee_xlsx_cell('N' . $detailRow, $productive * $cycle, 3, "IF(M$detailRow=1,L$detailRow,0)");
        $cells[] = oee_xlsx_cell('O' . $detailRow, !empty($flags['colour_no_standard']) ? 'Missing cycle standard: server fallback applied' : 'Applied cycle-time snapshot');
        $sheetRows[] = oee_xlsx_row($detailRow, $cells);
        $detailRow++;
    }
    $lastRow = max(1, $detailRow - 1);
    $columns = '<cols><col min="1" max="1" width="27" customWidth="1"/><col min="2" max="2" width="18" customWidth="1"/><col min="3" max="3" width="43" customWidth="1"/><col min="4" max="7" width="23" customWidth="1"/><col min="8" max="9" width="30" customWidth="1"/><col min="10" max="19" width="24" customWidth="1"/></cols>';
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:O' . $lastRow . '"/><sheetViews><sheetView workbookViewId="0"/></sheetViews><sheetFormatPr defaultRowHeight="15"/>' . $columns . '<sheetData>' . implode('', $sheetRows) . '</sheetData><pageMargins left="0.25" right="0.25" top="0.5" bottom="0.5" header="0.2" footer="0.2"/></worksheet>';
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="2"><numFmt numFmtId="165" formatCode="0.0%"/><numFmt numFmtId="166" formatCode="yyyy-mm-dd hh:mm:ss"/></numFmts><fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><sz val="11"/><color rgb="FF0070C0"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFD9EAF7"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="7"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/><xf numFmtId="0" fontId="1" fillId="1" borderId="0"/><xf numFmtId="165" fontId="0" fillId="0" borderId="0"/><xf numFmtId="2" fontId="0" fillId="0" borderId="0"/><xf numFmtId="2" fontId="2" fillId="0" borderId="0"/><xf numFmtId="0" fontId="2" fillId="0" borderId="0"/><xf numFmtId="166" fontId="2" fillId="0" borderId="0"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    $worksheets = ['OEE Report' => $sheet, 'Downtime' => $sources['events'], 'Downtime calculation' => $sources['segments'], 'Quality' => $sources['quality']];
    $definitions = $relationships = $overrides = '';
    $number = 1;
    foreach ($worksheets as $name => $xml) {
        $definitions .= '<sheet name="' . oee_xml_text($name) . '" sheetId="' . $number . '" r:id="rId' . $number . '"/>';
        $relationships .= '<Relationship Id="rId' . $number . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $number . '.xml"/>';
        $overrides .= '<Override PartName="/xl/worksheets/sheet' . $number . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $zip->addFromString('xl/worksheets/sheet' . $number . '.xml', $xml);
        $number++;
    }
    $relationships .= '<Relationship Id="rId' . $number . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . $overrides . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $definitions . '</sheets><calcPr calcMode="auto" fullCalcOnLoad="1" forceFullCalc="1"/></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relationships . '</Relationships>');
    $zip->addFromString('xl/styles.xml', $styles);
    $zip->close();
    $contents = file_get_contents($temporary);
    @unlink($temporary);
    if ($contents === false) throw new RuntimeException('Could not read the Excel workbook.');
    return $contents;
}
