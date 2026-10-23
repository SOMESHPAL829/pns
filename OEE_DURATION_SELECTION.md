# OEE duration selection

Operators need both a full calendar-day calculation and a selectable 12-hour
shift calculation. Changing only the denominator would overstate output and OEE.

## Requirements

- Dashboard, production report and Excel export support `duration_minutes=1440|720`
  and the equivalent `duration_hours=24|12` alias.
- The default remains 24 hours, midnight to midnight. For 12 hours, `start_time`
  is selectable in HH:MM format, initially 00:00, and the window may cross midnight.
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
- A 12-hour view does not overwrite the stored DAY snapshot. Existing daily
  ingestion and cycle-time refresh continue to operate as before.
- Daily quality entries remain exclusive to the 24-hour view. A 12-hour window
  uses only quality entries with matching `shift_code=H12_HHMM` and the selected
  start date. When none exist, assume zero quality losses and show a warning;
  never divide or copy daily losses into both shifts.
- Exports carry the exact selected window and filter their FB, downtime and
  quality source sheets accordingly; existing source-driven formulas remain.
- The API rejects unsupported durations and malformed start times. The UI has
  labelled controls, retains the selected duration between dashboard/report,
  and exposes the actual start/end timestamps and quality warnings.

## Acceptance checks

1. With no duration parameter, full-day results retain the existing semantics.
2. Adjacent 00:00–12:00 and 12:00–24:00 views partition production without
   duplication; their ideal minutes and clipped downtime add to the daily totals.
3. A start time of 18:00 includes next-day loads before 06:00, but not at 06:00.
4. Downtime crossing a shift boundary contributes only the overlapping minutes.
5. Daily rejection counts never contaminate a 12-hour calculation.
6. Switching duration updates summary totals, line metrics, labels and export
   parameters. A 12-hour export contains 720 calendar minutes per line.
7. Excel schema validation, independent formula evaluation, existing calculator,
   security and ingestion tests pass. Invalid API selections return HTTP 422.

No database migration or redesign of quality entry screens is part of this
change. The start-time selector supplies the missing shift preference.
