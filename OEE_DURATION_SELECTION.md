# OEE duration selection

Operators need a full calendar-day calculation and selectable 8-, 10-, 12-,
and 16-hour windows. Changing only the denominator would overstate output and OEE.

## Requirements

- Dashboard, production report, date-range report and Excel exports support
  `duration_minutes=480|600|720|960|1440` and the equivalent
  `duration_hours=8|10|12|16|24` alias.
- The default remains 24 hours, midnight to midnight. For partial-day durations,
  `start_time` is selectable in HH:MM format, initially 00:00, and the window may
  cross midnight.
- Production belongs to the window when `start <= loading_at < end`. Counts,
  pieces, surface area and ideal cycle minutes use only those flight bars.
- Eligible downtime is clipped to the window, with overlaps counted once and
  unplanned taking precedence over planned.
- Planned time = window minutes - planned stops; run time = planned time -
  unplanned stops. Availability = run/planned; performance = ideal/run;
  quality = good/productive; OEE = availability × performance × quality.
- Window utilization = run/window minutes. The existing `utilization_24h`
  response remains run/1440; a separate `utilization_window` drives the selected
  duration's UI and export. All-lines ratios use summed inputs.
- A partial-day view does not overwrite the stored DAY snapshot. Existing daily
  ingestion and cycle-time refresh continue to operate as before.
- Daily quality entries remain exclusive to the 24-hour view. A partial-day
  window uses only quality entries with a matching `shift_code=H<duration>_HHMM`
  and the selected start date (for example `H8_0800` or `H12_1800`). When none
  exist, assume zero quality losses and show a warning; never copy daily losses
  into a partial window.
- Exports carry the exact selected window and filter their FB, downtime and
  quality source sheets accordingly; existing source-driven formulas remain.
- The API rejects unsupported durations and malformed start times. The UI has
  labelled controls, retains the selected duration between dashboard/report,
  and exposes the actual start/end timestamps and quality warnings.

## Acceptance checks

1. With no duration parameter, full-day results retain the existing semantics.
2. Supported 8-, 10-, 12-, 16- and 24-hour windows use their selected endpoints.
   Adjacent 00:00–12:00 and 12:00–24:00 views partition production without
   duplication; their ideal minutes and clipped downtime add to the daily totals.
3. A start time of 18:00 includes next-day loads before 06:00, but not at 06:00.
4. Downtime crossing a shift boundary contributes only the overlapping minutes.
5. Daily rejection counts never contaminate a partial-day calculation.
6. Switching duration updates summary totals, line metrics, labels and export
   parameters. Each export contains the selected number of calendar minutes per line.
7. Excel schema validation, independent formula evaluation, existing calculator,
   security and ingestion tests pass. Invalid API selections return HTTP 422.

No database migration or redesign of quality entry screens is part of this
change. The start-time selector supplies the missing shift preference.

## Inclusive date-range selection

As requested, a **separate Date-range OEE dashboard** at `#/oee-range` and its
Excel export MUST accept From date and To date (both inclusive). The existing
daily dashboard and production report remain unchanged. Equal dates retain the
single-day calculation. New API actions `range-dashboard` and `range-export`
accept `from_date` and `to_date` together; legacy daily endpoints are unchanged. Reversed,
invalid, incomplete or ranges longer than 93 days return HTTP 422. The limit
bounds synchronous recalculation/export work and supports monthly/quarterly reports.

The selected 480/600/720/960/1440 minutes applies **per day per line**. A
partial-day range starting at 18:00 includes only the selected hours after 18:00,
not the intervening uncovered time.
Time windows are half-open; FBs and downtime cannot be counted twice. Quality
losses remain scoped to the reporting day and selected shift. Missing dates stay
in the selected time denominator; no output is invented for them.

Range ratios MUST use summed inputs: Availability = Σrun/Σplanned, Performance =
Σideal/Σrun, Quality = Σgood/Σproductive, OEE = A×P×Q. Window utilization =
Σrun/(days×lines×selected minutes); 24h utilization uses days×lines×1440.
Line-wise results aggregate the same windows. Daily snapshots remain daily.
Excel MUST retain a separate calculation row for each day/line, with formulas
scoped to that window, and identify both dates in the header and filename.

Acceptance: two-day totals equal the sum of separately selected days, weighted
ratios equal ratios of these sums, partial-day ranges exclude uncovered time,
and Excel independently recalculates the same results. The separate dashboard
retains its independent selection and rejects invalid ranges before loading/exporting. Downtime
tagging, SCADA ingestion and master-data screens are outside this change.
