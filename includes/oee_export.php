<?php
declare(strict_types=1);

function oee_xml_text(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function oee_xlsx_cell(string $reference, mixed $value = '', int $style = 0, ?string $formula = null): string
{
    if ($formula !== null) {
        // OOXML stores formulas without the leading '=' used by spreadsheet UIs.
        $formula = ltrim($formula, '=');
        return '<c r="' . $reference . '" s="' . $style . '"><f>' . oee_xml_text($formula) . '</f><v>' . oee_xml_text($value) . '</v></c>';
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

function oee_export_xlsx(array $report): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Excel export is not available on this server.');
    }
    $temporary = tempnam(sys_get_temp_dir(), 'pns-oee-');
    if ($temporary === false) throw new RuntimeException('Could not create the Excel export.');
    $zip = new ZipArchive();
    if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($temporary);
        throw new RuntimeException('Could not create the Excel workbook.');
    }

    $snapshot = $report['oee'] ?? [];
    $lines = $report['line_metrics'] ?? [];
    $rows = $report['rows'] ?? [];
    $sheetRows = [];
    $sheetRows[] = oee_xlsx_row(1, [oee_xlsx_cell('A1', 'PNS OEE report', 1)]);
    $sheetRows[] = oee_xlsx_row(2, [oee_xlsx_cell('A2', 'Report date'), oee_xlsx_cell('B2', $report['date'] ?? '')]);
    $sheetRows[] = oee_xlsx_row(3, [oee_xlsx_cell('A3', 'Line / machine'), oee_xlsx_cell('B3', ($report['machine'] ?? '') === 'ALL' ? 'All lines' : ($report['machine'] ?? ''))]);
    $sheetRows[] = oee_xlsx_row(5, [oee_xlsx_cell('A5', 'Aggregate calculation', 1), oee_xlsx_cell('B5', 'Value', 1), oee_xlsx_cell('C5', 'Formula', 1)]);
    $sheetRows[] = oee_xlsx_row(6, [oee_xlsx_cell('A6', 'Availability'), oee_xlsx_cell('B6', (float) ($snapshot['availability'] ?? 0), 2), oee_xlsx_cell('C6', 'Run time / planned production time')]);
    $sheetRows[] = oee_xlsx_row(7, [oee_xlsx_cell('A7', 'Performance'), oee_xlsx_cell('B7', (float) ($snapshot['performance'] ?? 0), 2), oee_xlsx_cell('C7', 'Ideal output time / run time')]);
    $sheetRows[] = oee_xlsx_row(8, [oee_xlsx_cell('A8', 'Quality'), oee_xlsx_cell('B8', (float) ($snapshot['quality'] ?? 0), 2), oee_xlsx_cell('C8', 'Good FB / productive FB')]);
    $sheetRows[] = oee_xlsx_row(9, [oee_xlsx_cell('A9', 'OEE'), oee_xlsx_cell('B9', (float) ($snapshot['oee'] ?? 0), 2, '=B6*B7*B8'), oee_xlsx_cell('C9', 'Availability × Performance × Quality')]);
    $sheetRows[] = oee_xlsx_row(10, [oee_xlsx_cell('A10', '24h utilization'), oee_xlsx_cell('B10', (float) ($snapshot['utilization_24h'] ?? 0), 2), oee_xlsx_cell('C10', 'Run time / 1440 minutes')]);
    $sheetRows[] = oee_xlsx_row(12, [oee_xlsx_cell('A12', 'Line-wise OEE', 1), oee_xlsx_cell('B12', 'Availability', 1), oee_xlsx_cell('C12', 'Performance', 1), oee_xlsx_cell('D12', 'Quality', 1), oee_xlsx_cell('E12', 'OEE', 1), oee_xlsx_cell('F12', '24h utilization', 1)]);
    $lineRow = 13;
    foreach ($lines as $line) {
        $sheetRows[] = oee_xlsx_row($lineRow, [
            oee_xlsx_cell('A' . $lineRow, $line['line_code']),
            oee_xlsx_cell('B' . $lineRow, (float) $line['availability'], 2),
            oee_xlsx_cell('C' . $lineRow, (float) $line['performance'], 2),
            oee_xlsx_cell('D' . $lineRow, (float) $line['quality'], 2),
            oee_xlsx_cell('E' . $lineRow, (float) $line['oee'], 2, '=B' . $lineRow . '*C' . $lineRow . '*D' . $lineRow),
            oee_xlsx_cell('F' . $lineRow, (float) $line['utilization_24h'], 2),
        ]);
        $lineRow++;
    }
    $detailHeader = $lineRow + 2;
    $headers = ['Line', 'SI', 'Sequence', 'Part', 'Grade', 'Colour', 'Loading', 'Unloading', 'PCS', 'Total SA', 'State'];
    $headerCells = [];
    foreach ($headers as $index => $header) $headerCells[] = oee_xlsx_cell(chr(65 + $index) . $detailHeader, $header, 1);
    $sheetRows[] = oee_xlsx_row($detailHeader, $headerCells);
    $detailRow = $detailHeader + 1;
    foreach ($rows as $item) {
        $values = [$item['line_code'] ?? '', $item['si_no'] ?? '', $item['seq'] ?? '', $item['part_code'] ?? '', $item['grade'] ?? '', $item['used_colour'] ?: ($item['report_colour'] ?? ''), $item['loading_at'] ?? '', $item['unloading_at'] ?? '', (int) ($item['pcs'] ?? 0), $item['total_surface_area'] === null ? '' : (float) $item['total_surface_area'], (int) ($item['is_productive'] ?? 0) === 1 ? 'Productive' : 'Empty'];
        $cells = [];
        foreach ($values as $index => $value) $cells[] = oee_xlsx_cell(chr(65 + $index) . $detailRow, $value, is_numeric($value) && $index > 0 && $index < 10 ? 3 : 0);
        $sheetRows[] = oee_xlsx_row($detailRow, $cells);
        $detailRow++;
    }
    $lastRow = max(1, $detailRow - 1);
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:K' . $lastRow . '"/><sheetViews><sheetView workbookViewId="0"/></sheetViews><sheetFormatPr defaultRowHeight="15"/><sheetData>' . implode('', $sheetRows) . '</sheetData><pageMargins left="0.25" right="0.25" top="0.5" bottom="0.5" header="0.2" footer="0.2"/></worksheet>';
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="165" formatCode="0.0%"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="solid"><fgColor rgb="D9EAF7"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/><xf numFmtId="0" fontId="1" fillId="1" borderId="0"/><xf numFmtId="165" fontId="0" fillId="0" borderId="0"/><xf numFmtId="2" fontId="0" fillId="0" borderId="0"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/xl/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><fileVersion appName="PNS OEE"/><calcPr calcMode="auto" fullCalcOnLoad="1" forceFullCalc="1"/><sheets><sheet name="OEE Report" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="theme/theme1.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->addFromString('xl/styles.xml', $styles);
    $zip->addFromString('xl/theme/theme1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Office"><a:themeElements><a:clrScheme name="Office"><a:dk1><a:sysClr val="windowText" lastClr="000000"/></a:dk1><a:lt1><a:sysClr val="window" lastClr="FFFFFF"/></a:lt1><a:dk2><a:srgbClr val="1F4E78"/></a:dk2><a:lt2><a:srgbClr val="D9EAF7"/></a:lt2><a:accent1><a:srgbClr val="4472C4"/></a:accent1><a:accent2><a:srgbClr val="ED7D31"/></a:accent2><a:accent3><a:srgbClr val="A5A5A5"/></a:accent3><a:accent4><a:srgbClr val="FFC000"/></a:accent4><a:accent5><a:srgbClr val="5B9BD5"/></a:accent5><a:accent6><a:srgbClr val="70AD47"/></a:accent6><a:hlink><a:srgbClr val="0563C1"/></a:hlink><a:folHlink><a:srgbClr val="954F72"/></a:folHlink></a:clrScheme><a:fontScheme name="Office"><a:majorFont><a:latin typeface="Calibri Light"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont><a:minorFont><a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont></a:fontScheme><a:fmtScheme name="Office"><a:fillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:fillStyleLst><a:lnStyleLst><a:ln w="9525" cap="flat" cmpd="sng" algn="ctr"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:prstDash val="solid"/></a:ln></a:lnStyleLst><a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst><a:bgFillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:bgFillStyleLst></a:fmtScheme></a:themeElements><a:objectDefaults/><a:extraClrSchemeLst/></a:theme>');
    $zip->close();
    $contents = file_get_contents($temporary);
    @unlink($temporary);
    if ($contents === false) throw new RuntimeException('Could not read the Excel workbook.');
    return $contents;
}
