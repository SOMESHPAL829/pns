<?php
declare(strict_types=1);

function oee_excel_datetime(string $text): float
{
    $value = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $text, new DateTimeZone('UTC'));
    if (!$value || $value->format('Y-m-d H:i:s') !== $text) throw new InvalidArgumentException('Invalid Excel window/event timestamp');
    return $value->getTimestamp() / 86400 + 25569;
}

function oee_export_source_sheet(array $rows, string $lastColumn): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:' . $lastColumn . max(2, count($rows)) . '"/><sheetViews><sheetView workbookViewId="0"/></sheetViews><sheetFormatPr defaultRowHeight="15"/><cols><col min="1" max="19" width="25" customWidth="1"/></cols><sheetData>' . implode('', $rows) . '</sheetData></worksheet>';
}

function oee_export_source_header(array $headers): string
{
    $cells = [];
    foreach ($headers as $index => $text) $cells[] = oee_xlsx_cell(chr(65 + $index) . '1', $text, 1);
    return oee_xlsx_row(1, $cells);
}

/** Build auditable event/quality worksheets and their cached calculation inputs. */
function oee_export_sources(array $report): array
{
    $eventsRows = [oee_export_source_header(['Line', 'Start', 'End', 'Type', 'Status', 'Source', 'Reason / notes', 'Window start', 'Window end', 'Clipped start', 'Clipped end', 'Elapsed min (may overlap)', 'Included'])];
    $segmentRows = [oee_export_source_header(['Line', 'Start boundary', 'End boundary', 'Active unplanned', 'Active planned', 'Unplanned min', 'Planned min', 'Calculation row'])];
    $qualityRows = [oee_export_source_header(['Line', 'Shift', 'Rejected FB', 'Reworked FB', 'Notes', 'Calculation row', 'Reporting date'])];
    $prepared = [];
    foreach ($report['line_metrics'] ?? [] as $index => $line) {
        $code = $line['line_code'];
        $mainRow = 13 + $index;
        $start = oee_excel_datetime($line['window_start'] ?? '');
        $end = oee_excel_datetime($line['window_end'] ?? '');
        if ($end <= $start) throw new InvalidArgumentException('Excel window end must follow start');
        $qStart = count($qualityRows) + 1;
        $rejected = $reworked = 0;
        foreach ($report['quality_entries'] ?? [] as $entry) {
            if ($entry['line_code'] !== $code) continue;
            if (isset($report['from_date']) && (($entry['report_date'] ?? '') !== $line['report_date'] || (($line['duration_hours'] ?? 24) === 12 && ($entry['shift_code'] ?? '') !== $line['shift_code']))) continue;
            $r = count($qualityRows) + 1;
            $reject = (int) $entry['rejected_fb'];
            $rework = (int) $entry['reworked_fb'];
            $qualityRows[] = oee_xlsx_row($r, [oee_xlsx_cell("A$r", $code), oee_xlsx_cell("B$r", $entry['shift_code'] ?? ''), oee_xlsx_cell("C$r", $reject, 5), oee_xlsx_cell("D$r", $rework, 5), oee_xlsx_cell("E$r", $entry['notes'] ?? ''), oee_xlsx_cell("F$r", $mainRow), oee_xlsx_cell("G$r", $entry['report_date'] ?? $report['date'] ?? '')]);
            $rejected += $reject;
            $reworked += $rework;
        }
        if (count($qualityRows) + 1 === $qStart) {
            $qualityRows[] = oee_xlsx_row($qStart, [oee_xlsx_cell("A$qStart", $code), oee_xlsx_cell("C$qStart", 0, 5), oee_xlsx_cell("D$qStart", 0, 5), oee_xlsx_cell("E$qStart", 'No quality entry: zero rejected/reworked FB assumed.'), oee_xlsx_cell("F$qStart", $mainRow), oee_xlsx_cell("G$qStart", $line['report_date'] ?? $report['date'] ?? '')]);
        }
        // Include a window boundary row so there is a complete boundary range
        // even when no downtime exists. This row never counts as an event.
        $lineEvents = [['starts_at' => $line['window_start'], 'ends_at' => $line['window_end'], 'event_type' => 'window', 'status' => 'boundary', 'source' => 'window', 'reason_code' => 'Window boundaries']];
        foreach ($report['downtime_events'] ?? [] as $event) {
            if ($event['line_code'] === $code && (!isset($event['window_date']) || $event['window_date'] === ($line['report_date'] ?? ''))) $lineEvents[] = $event;
        }
        $firstEvent = count($eventsRows) + 1;
        $boundaries = [];
        $activeEvents = [];
        foreach ($lineEvents as $event) {
            $r = count($eventsRows) + 1;
            $rawStart = oee_excel_datetime($event['starts_at']);
            $rawEnd = oee_excel_datetime($event['ends_at']);
            $clippedStart = max($start, min($end, $rawStart));
            $clippedEnd = max($start, min($end, $rawEnd));
            $included = in_array($event['status'], ['proposed', 'confirmed', 'approved'], true) && in_array($event['event_type'], ['planned', 'unplanned'], true) && $rawEnd > $rawStart;
            $boundaries[] = $clippedStart;
            $boundaries[] = $clippedEnd;
            $activeEvents[] = ['start' => $clippedStart, 'end' => $clippedEnd, 'type' => $event['event_type'], 'included' => $included];
            $window = $event['event_type'] === 'window';
            $eventsRows[] = oee_xlsx_row($r, [
                oee_xlsx_cell("A$r", $code),
                oee_xlsx_cell("B$r", $rawStart, 6, $window ? "'OEE Report'!P$mainRow" : null),
                oee_xlsx_cell("C$r", $rawEnd, 6, $window ? "'OEE Report'!Q$mainRow" : null),
                oee_xlsx_cell("D$r", $event['event_type']), oee_xlsx_cell("E$r", $event['status']), oee_xlsx_cell("F$r", $event['source']),
                oee_xlsx_cell("G$r", trim(($event['reason_code'] ?? '') . ' ' . ($event['notes'] ?? ''))),
                oee_xlsx_cell("H$r", $start, 6, "'OEE Report'!P$mainRow"), oee_xlsx_cell("I$r", $end, 6, "'OEE Report'!Q$mainRow"),
                oee_xlsx_cell("J$r", $clippedStart, 6, "MAX(H$r,MIN(I$r,B$r))"), oee_xlsx_cell("K$r", $clippedEnd, 6, "MAX(H$r,MIN(I$r,C$r))"),
                oee_xlsx_cell("L$r", max(0, ($clippedEnd - $clippedStart) * 1440), 3, "MAX(0,(K$r-J$r)*1440)"),
                oee_xlsx_cell("M$r", $included ? 1 : 0, 0, "IF(AND(OR(E$r=\"proposed\",E$r=\"confirmed\",E$r=\"approved\"),OR(D$r=\"planned\",D$r=\"unplanned\"),C$r>B$r),1,0)"),
            ]);
        }
        $lastEvent = count($eventsRows);
        sort($boundaries, SORT_NUMERIC);
        $unplanned = $planned = 0.0;
        for ($i = 0, $count = count($boundaries) - 1; $i < $count; $i++) {
            $r = count($segmentRows) + 1;
            $b = $boundaries[$i];
            $e = $boundaries[$i + 1];
            $unplannedActive = $plannedActive = 0;
            foreach ($activeEvents as $event) {
                if (!$event['included'] || $event['start'] >= $e || $event['end'] <= $b) continue;
                if ($event['type'] === 'unplanned') $unplannedActive++;
                else $plannedActive++;
            }
            $u = $unplannedActive > 0 ? max(0, ($e - $b) * 1440) : 0;
            $p = $unplannedActive === 0 && $plannedActive > 0 ? max(0, ($e - $b) * 1440) : 0;
            $unplanned += $u;
            $planned += $p;
            $range = "'Downtime'!\$J\$$firstEvent:\$K\$$lastEvent";
            $criteria = ",'Downtime'!\$J\$$firstEvent:\$J\$$lastEvent,\"<\"&C$r,'Downtime'!\$K\$$firstEvent:\$K\$$lastEvent,\">\"&B$r,'Downtime'!\$M\$$firstEvent:\$M\$$lastEvent,1)";
            $segmentRows[] = oee_xlsx_row($r, [
                oee_xlsx_cell("A$r", $code), oee_xlsx_cell("B$r", $b, 6, 'SMALL(' . $range . ',' . ($i + 1) . ')'), oee_xlsx_cell("C$r", $e, 6, 'SMALL(' . $range . ',' . ($i + 2) . ')'),
                oee_xlsx_cell("D$r", $unplannedActive, 0, "COUNTIFS('Downtime'!\$D\$$firstEvent:\$D\$$lastEvent,\"unplanned\"" . $criteria),
                oee_xlsx_cell("E$r", $plannedActive, 0, "COUNTIFS('Downtime'!\$D\$$firstEvent:\$D\$$lastEvent,\"planned\"" . $criteria),
                oee_xlsx_cell("F$r", $u, 3, "IF(D$r>0,MAX(0,(C$r-B$r)*1440),0)"),
                oee_xlsx_cell("G$r", $p, 3, "IF(AND(D$r=0,E$r>0),MAX(0,(C$r-B$r)*1440),0)"),
                oee_xlsx_cell("H$r", $mainRow),
            ]);
        }
        $productive = 0;
        $ideal = 0.0;
        foreach ($report['rows'] ?? [] as $item) {
            if (($item['line_code'] ?? '') !== $code || (int) ($item['pcs'] ?? 0) <= 0) continue;
            if (isset($report['from_date']) && (($item['loading_at'] ?? '') < $line['window_start'] || ($item['loading_at'] ?? '') >= $line['window_end'])) continue;
            if (!isset($item['cycle_minutes']) || !is_numeric($item['cycle_minutes']) || (float) $item['cycle_minutes'] <= 0) throw new InvalidArgumentException('Applied cycle time missing for productive FB');
            $productive++;
            $ideal += (float) $item['cycle_minutes'];
        }
        $prepared[] = array_replace($line, ['window_minutes' => round(($end - $start) * 1440, 3), 'calendar_minutes' => round(($end - $start) * 1440, 3), 'planned_stop_minutes' => round($planned, 3), 'unplanned_minutes' => round($unplanned, 3), 'ideal_output_minutes' => round($ideal, 3), 'productive_fb' => $productive, 'good_fb' => max(0, $productive - $rejected - $reworked), 'rejected_fb' => $rejected, 'reworked_fb' => $reworked, 'excel_start' => $start, 'excel_end' => $end]);
    }
    return ['lines' => $prepared, 'events' => oee_export_source_sheet($eventsRows, 'M'), 'segments' => oee_export_source_sheet($segmentRows, 'H'), 'quality' => oee_export_source_sheet($qualityRows, 'G'), 'last_segment' => max(2, count($segmentRows)), 'last_quality' => max(2, count($qualityRows))];
}
