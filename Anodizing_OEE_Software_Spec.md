# Anodizing Plant (APP-06) - OEE, Utilization & Downtime Software Specification

Version 1.2 - cycle-based gap stops and cross-midnight detection, approved 26 October 2026. See `OEE_GAP_RULES.md` for current implementation rules. The original prototype is based on `17-10-2026.xls` and `Anodizing_OEE_Downtime_Tracker.xlsx`.
Counting unit is the **flight bar (FB)** - one rack loaded onto the line.

---

## 1. Purpose and scope

Build a system that:

1. Imports the daily anodizing production report (one row per flight bar).
2. Detects suspected line stops from loading gaps and lets operators confirm them with a reason code.
3. Calculates **Availability, Performance, Quality, OEE, Utilization (24-hour basis)** per shift, per day and for any period.
4. Records and reports downtime (Pareto by reason, reason x day matrix).
5. Keeps history that does not change when master data (cycle times) changes later.

The plant runs **24 hours**. There is no PLC / hoist run-stop signal today, so downtime is inferred from loading timestamps and confirmed by people. A signal can replace the inference later (section 17).

---

## 2. Definitions

| Term | Meaning |
|---|---|
| FB / flight bar | One rack with parts, loaded onto the line. Row in the report. |
| Productive FB | FB with parts: part code is not `EMPTY` and pcs > 0. |
| Empty rack | FB with part code `EMPTY` (or pcs = 0). Not output. |
| Window | The time range being analysed (start, end). A full day is 00:00 to 24:00. |
| Planned production time | Window minutes minus planned stops. |
| Run time | Planned production time minus unplanned downtime. |
| Ideal cycle time | Standard minutes per FB for a colour (the line pitch). |
| Ideal output time | Sum of ideal cycle times of all productive FB in the window. |
| Normal pitch | Typical gap between two consecutive loads in normal flow (14.6 min on 17-10-2026). |
| Calendar minutes | 1,440 per day. Basis of Utilization. |

---

## 3. KPI formulas (the core logic)

All time values in **minutes**.

```
window_min          = (window_end - window_start) in minutes
planned_stop_min    = sum(minutes of events where planned = true,  status != FALSE_ALARM)   # clipped to window
unplanned_down_min  = sum(minutes of events where planned = false, status != FALSE_ALARM)   # clipped to window
planned_time        = max(0, window_min - planned_stop_min)
run_time            = max(0, planned_time - unplanned_down_min)

fb_productive       = count(FB with parts, load_time inside window)
fb_good             = fb_productive - rejected_fb
ideal_output_time   = sum( ideal_cycle_min(fb) for each productive FB )     # per colour, see 3.2

availability        = run_time / planned_time                  # 0 if planned_time <= 0
performance         = ideal_output_time / run_time             # 0 if run_time <= 0 ; NOT capped, flag if > 1
quality             = fb_good / fb_productive                  # 0 if fb_productive = 0 ; 1.0 if rejects not entered (flag it)
OEE                 = availability * performance * quality

utilization         = run_time / (1440 * number_of_calendar_days_in_window)       # 24-hour basis, ALWAYS
capacity_util       = ideal_output_time / (1440 * number_of_calendar_days_in_window)
```

Notes:

- `availability x performance = ideal_output_time / planned_time`. Useful as a self-check.
- **Utilization is always on 24 hours.** If the window is shorter than a day (report ends 19:19), the missing time has no run time and counts as *not utilized*. Show the "partial day" warning next to the number.
- Availability, Performance and OEE use planned time **inside the window**, not 1,440.
- With no planned stops, `planned_time = window_min`. For a full day, `availability = utilization`.
- Empty racks are not output. They add no ideal time, and the time they occupy is logged as a loss event (3.4).
- The daily dashboard window is always the complete report date: `00:00:00` through the next day's `00:00:00` (1,440 minutes). The last observed load is not the end of the window.
- Downtime intervals are clipped to the window and merged before totals are calculated. One minute cannot be counted twice, even if automatic events overlap. Overlapping planned/unplanned intervals are flagged for review.

### 3.1 Utilization - why it differs from Availability

| Case | Availability | Utilization |
|---|---|---|
| Full day, no planned stops | run / 1440 | run / 1440 (same) |
| Full day, 60 min planned maintenance | run / 1380 | run / 1440 (lower) |
| Partial day, window 00:00-19:19 | run / 1159 | run / 1440 (lower) |

### 3.2 Ideal cycle time by colour

- Master table `cycle_time_standard (colour, minutes_per_fb, effective_from, effective_to)`.
- For each productive FB: look up the colour that was valid on the FB's load date.
- **Colour used** = report colour if present; otherwise the colour of the previous FB (ordered by load time). If the first FB has no colour: unresolved.
- If the colour has no standard (missing or <= 0): use `default_ideal_pitch` (config, default 12.0) and raise a CHECK flag.
- Empty racks: ideal cycle = 0.
- Store the cycle time **used** on each `fb_run` row at calculation time (snapshot), so later master data changes do not rewrite history.

> Open point: the cycle time given by the user is assumed to be the **line pitch** (minutes between flight bars). If it is the total load-to-unload time (about 110-125 min in the sample), it must be converted to a pitch before use.

### 3.3 Quality

- Input: rejected / reworked FB per day or per shift (`quality_entry`).
- `fb_good = fb_productive - rejected_fb`.
- If no entry exists: quality = 100% and the UI shows "Quality not entered - assumed 100%".
- Optional later: piece-based quality `good_pcs / total_pcs`. Keep FB-based as default because performance is counted per FB.

### 3.3a Aggregation over many days / shifts

Never average percentages. Sum the base quantities and recompute:

```
A = sum(run_time) / sum(planned_time)
P = sum(ideal_output_time) / sum(run_time)
Q = sum(fb_good) / sum(fb_productive)
OEE = A * P * Q
Utilization = sum(run_time) / (1440 * count_of_days)
```

### 3.4 Loss events and what counts as downtime

| Source | How it is created | Minutes |
|---|---|---|
| Gap stop | Gap between consecutive loads strictly above 20 min, ending in productive FB | gap - arriving FB's effective registered cycle (minimum zero) |
| Empty-rack run | One or more EMPTY racks loaded in a row (section 6) | preceding productive load + its registered cycle through last empty load |
| Manual stop | Operator adds start / end / reason | end - start |
| Planned stop | Reason with `planned = true` (PM, PBRK) | end - start, reduces planned time |

Slow running is **not** an event. It shows up as lower Performance.

---

## 4. Input: the daily report file

`17-10-2026.xls` (legacy Excel, read with `xlrd` or convert to xlsx first).

| Item | Detail |
|---|---|
| Header | First 5 rows are title / header rows. Data rows start after them. |
| One row | One flight bar |
| Col 0 | SI (serial number of the flight bar for the day) |
| Col 1 | Grade (may be blank) |
| Col 2 | Colour (e.g. `BLACK-1`, `BLACK-2`, `CLEAR`; may be blank) |
| Col 3 | Load time (time of day, `hh:mm:ss`) |
| Col 4 | Unload time (time of day; blank if not unloaded / not recorded) |
| Col 5 | Sequence no (e.g. `M10Y26-1531`) |
| Col 6 | Part code (e.g. `PB017`, may contain stray line breaks; `EMPTY` for empty rack) |
| Col 7 | Pieces on the flight bar |
| Cols 8-37 | Bath-by-bath process data (temperature / time per stage). Not needed for OEE in v1; store raw for later analysis. |
| Col 38 | Total surface area (unit as per report) |

Import rules:

1. Skip rows where SI is not a number (totals, blanks).
2. Strip `\r`, `\n` and spaces from text fields.
3. Load and unload are **times without a date**. Combine with the report date. If unload < load, add one day (rack crossed midnight).
4. Keep the original file and file hash (`import_batch`). Re-importing the same date replaces the previous rows for that date after a confirmation.
5. Do not guess missing values. Blank stays null.

Sample facts for 17-10-2026: 82 rows, 78 productive, 4 empty (#79-82), first load 00:07:12, last load 19:18:40, 12,688 pcs, total surface area 8,497.64.

---

## 5. Data model (PostgreSQL)

```sql
-- master data
CREATE TABLE line (
  line_id        SERIAL PRIMARY KEY,
  code           TEXT UNIQUE NOT NULL,          -- 'APP-06'
  name           TEXT NOT NULL
);

CREATE TABLE config (                            -- per line, versioned
  line_id            INT REFERENCES line,
  key                TEXT NOT NULL,              -- see 5.1
  value_num          NUMERIC,
  effective_from     DATE NOT NULL,
  PRIMARY KEY (line_id, key, effective_from)
);

CREATE TABLE cycle_time_standard (
  line_id        INT REFERENCES line,
  colour         TEXT NOT NULL,
  minutes_per_fb NUMERIC(6,2) NOT NULL CHECK (minutes_per_fb > 0),
  effective_from DATE NOT NULL,
  effective_to   DATE,
  PRIMARY KEY (line_id, colour, effective_from)
);

CREATE TABLE shift_def (
  line_id    INT REFERENCES line,
  shift_code TEXT,                               -- 'A','B','C'
  start_time TIME NOT NULL,
  end_time   TIME NOT NULL,                      -- C may cross midnight
  PRIMARY KEY (line_id, shift_code)
);

CREATE TABLE loss_reason (
  code        TEXT PRIMARY KEY,                  -- 'BD-HOIST'
  name        TEXT NOT NULL,
  category    TEXT NOT NULL,                     -- six-big-loss style category
  is_planned  BOOLEAN NOT NULL DEFAULT FALSE,
  active      BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE station (
  station_id SERIAL PRIMARY KEY,
  name       TEXT UNIQUE NOT NULL
);

-- transactions
CREATE TABLE import_batch (
  batch_id     SERIAL PRIMARY KEY,
  line_id      INT REFERENCES line,
  report_date  DATE NOT NULL,
  file_name    TEXT, file_sha256 TEXT,
  imported_by  TEXT, imported_at TIMESTAMPTZ DEFAULT now(),
  is_active    BOOLEAN DEFAULT TRUE
);

CREATE TABLE fb_run (
  fb_id          BIGSERIAL PRIMARY KEY,
  batch_id       INT REFERENCES import_batch,
  line_id        INT REFERENCES line,
  si             INT NOT NULL,
  seq_no         TEXT,
  load_ts        TIMESTAMP NOT NULL,
  unload_ts      TIMESTAMP,
  part_code      TEXT,
  grade          TEXT,
  colour_report  TEXT,                           -- as in the report (may be null)
  colour_used    TEXT,                           -- after fill-forward (6.3)
  pcs            INT,
  surface_area   NUMERIC(12,2),
  raw_stage_data JSONB,                          -- cols 8-37 kept as-is
  -- derived at import / recalculation
  is_productive  BOOLEAN NOT NULL,
  gap_prev_min   NUMERIC(8,2),
  transit_min    NUMERIC(8,2),
  ideal_cycle_min NUMERIC(6,2),                  -- snapshot of the standard used
  flag           TEXT,                           -- OK | SUSPECTED_STOP | CATCH_UP | EMPTY_RACK
  data_check     TEXT[],                         -- NO_UNLOAD, SHORT_TRANSIT, SEQ_JUMP, NO_COLOUR, ...
  UNIQUE (batch_id, si)
);
CREATE INDEX ON fb_run (line_id, load_ts);

CREATE TABLE downtime_event (
  event_id     BIGSERIAL PRIMARY KEY,
  line_id      INT REFERENCES line,
  start_ts     TIMESTAMP NOT NULL,
  end_ts       TIMESTAMP NOT NULL CHECK (end_ts > start_ts),
  station_id   INT REFERENCES station,
  reason_code  TEXT REFERENCES loss_reason,      -- NULL treated as 'UNK'
  source       TEXT NOT NULL,                    -- AUTO_GAP | AUTO_EMPTY | MANUAL
  status       TEXT NOT NULL,                    -- PROPOSED | ASSIGNED | APPROVED | FALSE_ALARM
  evidence     TEXT,                             -- e.g. '#29 -> #30 (gap 23.5 min)'
  remarks      TEXT,
  created_by   TEXT, created_at TIMESTAMPTZ DEFAULT now(),
  approved_by  TEXT, approved_at TIMESTAMPTZ
);
-- no two active events on a line may overlap
ALTER TABLE downtime_event ADD CONSTRAINT no_overlap
  EXCLUDE USING gist (line_id WITH =, tsrange(start_ts, end_ts) WITH &&)
  WHERE (status <> 'FALSE_ALARM');

CREATE TABLE quality_entry (
  line_id INT, entry_date DATE, shift_code TEXT,
  rejected_fb INT NOT NULL DEFAULT 0, remarks TEXT,
  entered_by TEXT, entered_at TIMESTAMPTZ DEFAULT now(),
  PRIMARY KEY (line_id, entry_date, shift_code)
);

CREATE TABLE oee_snapshot (                      -- frozen daily / shift results
  snapshot_id BIGSERIAL PRIMARY KEY,
  line_id INT, period_start TIMESTAMP, period_end TIMESTAMP, shift_code TEXT,
  window_min NUMERIC, planned_stop_min NUMERIC, unplanned_down_min NUMERIC,
  run_time_min NUMERIC, fb_productive INT, fb_good INT, ideal_output_min NUMERIC,
  availability NUMERIC, performance NUMERIC, quality NUMERIC, oee NUMERIC,
  utilization NUMERIC, capacity_util NUMERIC,
  flags JSONB, is_partial BOOLEAN, created_at TIMESTAMPTZ DEFAULT now(), created_by TEXT
);

CREATE TABLE audit_log (
  id BIGSERIAL PRIMARY KEY, ts TIMESTAMPTZ DEFAULT now(), user_id TEXT,
  entity TEXT, entity_id TEXT, action TEXT, old_value JSONB, new_value JSONB
);
```

### 5.1 Config keys (defaults from the 17-10-2026 analysis)

| Key | Default | Meaning |
|---|---|---|
| `default_ideal_pitch_min` | 12.0 | Used when a colour has no cycle time. **Assumption - confirm.** |
| `normal_pitch_min` | 14.6 (historical) | Legacy prototype statistic only. No longer used to deduct stop time; use the registered colour cycle. |
| `stop_threshold_min` | 20.0 | Gap above this is a suspected stop. |
| `catchup_gap_min` | 5.0 | Loads closer than this are flagged CATCH_UP. |
| `short_transit_min` | 60.0 | Load-to-unload below this is flagged SHORT_TRANSIT. |
| `calendar_min_per_day` | 1440 | Utilization basis. |
| `empty_part_code` | `EMPTY` | Part code that marks an empty rack. |

### 5.2 Loss reason master (initial)

| Code | Reason | Category | Planned |
|---|---|---|---|
| UNK | Unassigned - reason not yet recorded | Unaccounted | No |
| BD-HOIST | Hoist / transporter breakdown | Equipment breakdown | No |
| BD-RECT | Rectifier / anodizing power fault | Equipment breakdown | No |
| BD-UTIL | Chiller / boiler / pump / utility fault | Equipment breakdown | No |
| BD-ELEC | Power failure | Equipment breakdown | No |
| JIG | Jig / rack shortage or repair | Setup & adjustment | No |
| CHEM | Chemical analysis / dosing / bath adjustment | Setup & adjustment | No |
| CHG | Colour / grade changeover | Setup & adjustment | No |
| NM | No material / no parts to load | Idling & minor stops | No |
| NMAN | No manpower / operator absent | Idling & minor stops | No |
| WAIT | Waiting - loading, unloading or inspection | Idling & minor stops | No |
| QHOLD | Quality hold / rework / re-dip | Quality loss | No |
| PM | Planned maintenance / tank cleaning | Planned stop | **Yes** |
| PBRK | Planned break / shift handover | Planned stop | **Yes** |
| OTH | Other (remarks mandatory) | Other | No |

Stations: Whole line, Loading / unloading, Hoist / transporter, Degreasing, Acid etch, Alkaline etch, Nitric dip, Bright dip, Hot water rinse, Anodizing tank, Rectifier, Colour tank, Sealing tank, Utilities (chiller / boiler).

---

## 6. Processing pipeline

Run in this order after every import or edit of `fb_run`.

### 6.1 Per-FB derived fields

Order all FB of the line by `load_ts`.

```
is_productive = NOT (upper(part_code) == 'EMPTY' OR coalesce(pcs,0) == 0)

gap_prev_min  = minutes(load_ts[i] - load_ts[i-1])        # null for the first FB of the batch
transit_min   = minutes(unload_ts - load_ts)               # null if unload_ts is null

flag:
  if not is_productive                              -> EMPTY_RACK
  else if gap_prev_min is null                      -> OK
  else if gap_prev_min >  stop_threshold_min        -> SUSPECTED_STOP
  else if gap_prev_min <  catchup_gap_min           -> CATCH_UP
  else                                              -> OK

data_check (list, can have several):
  unload_ts is null                                 -> NO_UNLOAD
  transit_min < short_transit_min                   -> SHORT_TRANSIT
  seq numeric suffix - previous suffix <> 1         -> SEQ_JUMP     (regex ^(.*)-(\d+)$ on seq_no)
  colour_report is null                             -> NO_COLOUR
```

Flag precedence: EMPTY_RACK, then SUSPECTED_STOP, then CATCH_UP, then OK. Check order matters: a gap flag is set only for productive FB.

### 6.2 Observed pitch (diagnostic only)

```
normal_pitch = median( gap_prev_min for all FB where 8 <= gap_prev_min <= 20 )
```
On 17-10-2026 this gives 14.6 (70 gaps). This describes observed flow; it must
not replace the registered ideal cycle in the current stop calculation.

### 6.3 Colour fill-forward and cycle time

```
colour_used[i] = colour_report[i] if present else colour_used[i-1]   # null if none yet
ideal_cycle_min[i] =
   0                                    if not is_productive
   standard(colour_used[i], date)       if a standard > 0 exists
   default_ideal_pitch_min              otherwise  (+ flag COLOUR_NO_STANDARD)
```

### 6.4 Auto-detect downtime events

Create events with `source = AUTO_*`, `status = PROPOSED`, `reason_code = 'UNK'`. Never overwrite an event a person has edited (match on evidence key; skip if already exists).

**Gap stops**

```
for each productive FB i with flag == SUSPECTED_STOP:
    start = load_ts[i-1] + ideal_cycle_min[i]     # arriving FB colour/date standard
    end   = load_ts[i]
    minutes = max(0, end - start)                # gap minus registered cycle
    evidence = f"#{si[i-1]} -> #{si[i]} (gap {gap:.1f} min)"
```

**Empty-rack runs**

```
for each maximal run of consecutive EMPTY_RACK FB (j..k) preceded by a productive FB p:
    start = load_ts[p] + ideal_cycle_min[p]
    end   = load_ts[k]
    evidence = f"After #{si[p]}: bars #{si[j]}-{si[k]} loaded EMPTY"
    remark   = "Suggested reason: NM - confirm"
```

Read adjacent calendar dates so midnight gaps and empty runs are not lost.
Store a crossing event once and clip it when calculating each reporting window.
Do not infer stops across a missing calendar-day report. Rebuild only proposed
automatic `gap`/`empty_rack` events. Stable evidence keys retain confirmed,
approved and rejected operator decisions across recalculation and re-import;
manual records are untouched. Overlapping intervals still count only once.

**Hold hint** (informational, attach to the event that precedes it): when a CATCH_UP group (3+ bars loaded within `catchup_gap_min` of each other) follows a gap stop, or consecutive FB have no unload time, add the remark "Possible longer hold - extend End if the line was stopped longer".

### 6.5 Event lifecycle

```
PROPOSED --(operator picks reason, adjusts times)--> ASSIGNED --(supervisor)--> APPROVED
PROPOSED / ASSIGNED --(operator)--> FALSE_ALARM        # removed from all totals
APPROVED events are locked; edits need supervisor role and are written to audit_log
```

Rules:

- Minutes are **always calculated** from start and end, never typed.
- `reason_code` null or `UNK` counts as unplanned downtime and is shown as "Unaccounted".
- Planned reasons reduce planned time; they do not reduce run time twice.
- Events must not overlap (database constraint above). Validate in the UI before saving.
- An event that crosses midnight or a shift boundary is stored once and **clipped** when reporting (section 7).
- Manual events can be added anywhere inside the day (stops shorter than the loading pitch cannot be detected automatically).
- Block "close the day" while any event is still PROPOSED, or allow it with a visible "Unaccounted" total.

---

## 7. Calculation engine

```python
def calc(line, w_start, w_end, shift=None):
    window_min = minutes(w_end - w_start)

    events = [e for e in downtime_events(line) if e.status != 'FALSE_ALARM']
    def clipped(e):                                   # overlap with the window
        s, f = max(e.start, w_start), min(e.end, w_end)
        return max(0, minutes(f - s))
    planned_stop = sum(clipped(e) for e in events if reason(e).is_planned)
    unplanned    = sum(clipped(e) for e in events if not reason(e).is_planned)   # includes UNK
    unaccounted  = sum(clipped(e) for e in events if (e.reason_code or 'UNK') == 'UNK')

    planned_time = max(0, window_min - planned_stop)
    run_time     = max(0, planned_time - unplanned)

    fbs          = [f for f in fb_runs(line) if w_start <= f.load_ts < w_end and f.is_productive]
    fb_prod      = len(fbs)
    ideal_out    = sum(f.ideal_cycle_min for f in fbs)
    rejected     = quality_rejects(line, w_start, w_end, shift)     # None if not entered
    fb_good      = fb_prod - (rejected or 0)

    A = run_time / planned_time if planned_time > 0 else 0
    P = ideal_out / run_time    if run_time    > 0 else 0
    Q = fb_good / fb_prod       if fb_prod     > 0 else 0
    if rejected is None: Q = 1.0

    days = calendar_days_in(w_start, w_end)           # 1 for a normal day
    util = run_time / (1440 * days)
    cap  = ideal_out / (1440 * days)
    return dict(A=A, P=P, Q=Q, OEE=A*P*Q, util=util, cap=cap, ...)
```

Rules:

- **Window for a shift:** shift start/end from `shift_def`; a night shift that crosses midnight is two clips of the same event logic.
- **Open day:** if "now" is before the end of the day, the window ends at `now` (label "as of hh:mm"); Utilization is still on 24 hours.
- **Rounding:** store seconds; round to 0.1 min only for display. (The Excel prototype rounds each event to 0.1 min, so totals can differ by up to about 0.1 min per event.)
- **Performance above 100%** is not capped. It means the cycle time is too long, loads are missing, or events are overstated. Show a CHECK.
- **Division by zero** returns 0 and a CHECK, never an error.
- Saved results go to `oee_snapshot` (daily close). Reports for closed days read the snapshot; live screens recalculate.

---

## 8. Validation and logic checks (dashboard panel)

| Check | Status rule |
|---|---|
| Window coverage | CHECK if window end < 24:00 (partial day) |
| Performance <= 100% | CHECK if P > 1 |
| All downtime has a reason | CHECK if unaccounted minutes > 0 |
| Overlapping events | CHECK if any overlap exists |
| Events outside the window | CHECK if any start < window start or end > window end |
| Bars with no unload time | INFO if count > 0 (the last few may still be in the line) |
| Bars with short transit | INFO if count > 0 |
| Sequence-number jumps | CHECK if count > 0 (a flight bar is missing from the log) |
| Colours without a cycle time | CHECK if a colour used in the log has no standard |
| Colours missing from master | CHECK if a productive FB has a colour not in master |
| Bars with no colour in the report | INFO (previous colour used) |
| Pareto reconciles | CHECK if sum of reason minutes <> total event minutes (tolerance 0.05) |
| Report not imported | CHECK if no `fb_run` rows exist for a date in the window |

---

## 9. Reports and screens

1. **Import** - upload report, preview rows, show flags and data checks, confirm.
2. **Live shift screen** - FB count vs target, current run / stop status (time since last load vs threshold), red alert when a gap exceeds the threshold with no reason.
3. **Downtime Review** - list of PROPOSED events, one-tap reason picker, adjust start / end, split an event, add manual event, mark false alarm, supervisor approve.
4. **Dashboard** - KPI cards (Availability, Performance, Quality, OEE, Utilization 24 h, Capacity utilization 24 h), time accounting, output (FB, pcs, surface area, pitch, FB per hour), checks panel.
5. **Loss waterfall** - 1,440 -> window -> planned time -> run time -> ideal output -> good output.
6. **Pareto** - downtime minutes by reason, sorted, with cumulative %.
7. **Downtime matrix** - reason x day for a week / month.
8. **History / trend** - OEE and its three factors per day, weighted roll-ups, filter by colour / shift.
9. **Master data** - cycle time by colour (with effective dates), reasons, stations, shifts, config.
10. **Email-ready daily summary** - HTML table with green / red month-over-month colouring.

---

## 10. API (REST, JSON)

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/v1/imports` | Upload report (multipart); returns batch id and preview |
| POST | `/api/v1/imports/{id}/confirm` | Commit the import and run the pipeline |
| GET | `/api/v1/fb-runs?date=` | FB rows with flags and data checks |
| GET | `/api/v1/downtime-events?from=&to=&status=` | List events |
| POST | `/api/v1/downtime-events` | Add manual event |
| PATCH | `/api/v1/downtime-events/{id}` | Change times, station, reason, status |
| POST | `/api/v1/downtime-events/{id}/split` | Split into two events |
| POST | `/api/v1/downtime-events/{id}/approve` | Supervisor approval |
| PUT | `/api/v1/quality-entries` | Enter rejected / reworked FB for a day or shift |
| GET | `/api/v1/oee?from=&to=&shift=` | Live KPI calculation |
| POST | `/api/v1/oee/close-day?date=` | Freeze the day into `oee_snapshot` |
| GET | `/api/v1/pareto?from=&to=` | Downtime by reason |
| GET | `/api/v1/downtime-matrix?from=&to=` | Reason x day |
| GET/PUT | `/api/v1/master/cycle-times` | Cycle time by colour |
| GET/PUT | `/api/v1/master/config` | Configuration values |

Roles: **Operator** (assign reasons, enter quality), **Supervisor** (approve, edit approved with audit), **Admin** (master data, config, re-import), **Viewer** (read only).

---

## 11. Archived prototype example - report 17-10-2026

The following figures document the legacy 14.6-minute pitch/observed-window
prototype, not the current 1,440/720-minute cycle-based implementation. Do not
use them as current acceptance values. Current examples and formulas are in
`OEE_GAP_RULES.md` and `OEE_DURATION_SELECTION.md`.

Inputs: report-date window 00:00-next day 00:00 (1,440 min); default pitch 12 min for every colour (colour cycle times not yet entered); no planned stops; no rejections entered.

Auto-detected events (all `PROPOSED`, reason `UNK`):

| ID | Evidence | Start | End | Minutes |
|---|---|---|---|---|
| DT-001 | #29 -> #30 (gap 23.5) | 07:09:10 | 07:18:06 | 8.9 |
| DT-002 | #50 -> #51 (gap 21.9) | 11:13:41 | 11:21:00 | 7.3 |
| DT-003 | #55 -> #56 (gap 24.4) | 12:31:06 | 12:40:57 | 9.9 |
| DT-004 | #67 -> #68 (gap 20.2) | 15:37:22 | 15:43:01 | 5.7 |
| DT-005 | #74 -> #75 (gap 26.5) | 17:22:54 | 17:34:46 | 11.9 |
| DT-006 | After #78: bars #79-82 EMPTY | 18:24:18 | 19:18:40 | 54.4 |

Calculation:

| Quantity | Value |
|---|---|
| Window minutes | 1,159.0 |
| Planned stops | 0.0 |
| Unplanned downtime | 98.1 (all unaccounted) |
| Run time | 1,060.9 |
| FB with parts / empty / total | 78 / 4 / 82 |
| Productive FB by colour (after fill-forward) | BLACK-1: 61, BLACK-2: 10, CLEAR: 7 |
| Ideal output time (78 x 12) | 936.0 |
| **Availability** | 1,060.9 / 1,159 = **91.5%** |
| **Performance** | 936 / 1,060.9 = **88.2%** |
| **Quality** | 78 / 78 = **100%** (not entered) |
| **OEE** | 0.915 x 0.882 x 1.00 = **80.8%** |
| **Utilization (24 h)** | 1,060.9 / 1,440 = **73.7%** |
| **Capacity utilization (24 h)** | 936 / 1,440 = **65.0%** |

Other facts used by the checks: 16 bars with no unload time (#23-29, #41, #75-82), 3 short-transit bars (#33, #34, #48), 1 sequence jump (`M10Y26-1578` missing before #48), 5 bars with a blank colour (#31-34, #48), first load 00:07:12, last load 19:18:40, average pitch 14.2 min, 4.04 FB per hour.

Cases the data suggests need a person to look at (software should surface them as hints, not as facts):

- 06:54-07:24: bars #23-29 have no unload time and incomplete stage data; #31-34 loaded 07:22-07:24 in quick succession. The line was probably stopped longer than DT-001 shows.
- Bar #41 (PN-1001, 384 pcs) has no unload time and #48 repeats 384 pcs with only sealing recorded. Possible rework / re-dip.

---

## 12. Test cases

| # | Scenario | Expected |
|---|---|---|
| T1 | Import the 17-10-2026 report | 82 rows, 78 productive, 4 empty, 16 no-unload, 3 short-transit, 1 seq jump |
| T2 | Detect with threshold >20 and effective colour cycles | Event start = previous load + arriving CT; empty runs use preceding productive CT; midnight intervals split once; see `tests/oee_gap_test.php` |
| T3 | Day totals with default 12-min pitch | A 91.5%, P 88.2%, Q 100%, OEE 80.8%, Util 73.7% (tolerance 0.1 percentage point) |
| T4 | Enter colour cycle times 14 / 15 / 12 for BLACK-1 / BLACK-2 / CLEAR | Ideal output = 61x14 + 10x15 + 7x12 = 1,088 min; Performance = 102.6% and the "Performance above 100%" check fires |
| T5 | Set one event to FALSE_ALARM | Event minutes removed from downtime; run time rises by the same amount |
| T6 | Assign a planned reason (PM) to a 30-min event | Planned stops +30, planned time -30, unplanned down -30; Availability = run / (window - 30) |
| T7 | Assign reasons to all events | "All downtime has a reason" -> OK; Pareto sums to total event minutes |
| T8 | Event crossing midnight (23:50-00:20) | Calculated 10 min on day 1 and 20 min on day 2 |
| T9 | Overlapping manual event | Rejected by validation / database constraint |
| T10 | Colour blank on the first FB of the day | Flag NO_COLOUR, default pitch used, CHECK shown |
| T11 | Colour missing from master (e.g. `GOLD`) | CHECK "Colours missing from master"; default pitch used |
| T12 | No FB rows for a date | KPIs return 0 with "Report not imported" CHECK; no exception |
| T13 | Window set to 24:00 with the full report | Utilization equals Availability when there are no planned stops |
| T14 | Weekly roll-up over 7 days | Computed from summed minutes / counts, not averaged percentages |
| T15 | Edit master cycle time after the day is closed | Closed snapshot unchanged; reopening recalculates only if the user chooses to |
| T16 | Re-import the same date | Previous rows replaced after confirmation; user-edited events preserved when evidence keys match |

---

## 13. Edge cases and rules to decide in design

1. **Same part, different colour on one rack** - not expected; colour is per FB.
2. **FB loaded after the window end but before the report end** - excluded from the window.
3. **Report spans two calendar days** - import per date; the midnight rule (unload < load adds a day) handles unload times.
4. **First FB of the day** has no previous gap. A long idle period before it is not detected from gaps. Use window start as the reference if the plant should have been running at 00:00 (configurable: `first_load_reference = window_start | none`). The Excel prototype does not count it.
5. **Pitch differs by colour** - normal pitch is global in v1. If the cycle time table shows different pitches per colour, switch to `normal_pitch_by_colour`.
6. **Surface area** - unit not stated in the report. Show as "as per report". A secondary KPI `surface_area_per_hour` is recommended because area per bar varies about 30 to 270.
7. **Stops shorter than the pitch** between two loads cannot be seen - manual events cover them.
8. **Timezones** - store plant-local time; no conversion.
9. **Late edits** - any change to events or FB after day close marks the snapshot STALE and requires re-close.

---

## 14. Non-functional requirements

- Import of one day (about 100 rows) completes in under 5 seconds; dashboard in under 2 seconds.
- All calculations are deterministic and re-runnable from raw data + master data + events.
- Every edit to events, quality entries, master data and config is audit-logged with user and time.
- Role-based access; approvals cannot be done by the same user who entered the event (configurable).
- Daily automatic backup; raw report files kept for 3 years (or per plant policy).
- Export: Excel, PDF and HTML email for the daily summary.

---

## 15. Mapping from the Excel prototype

| Excel sheet | Software module |
|---|---|
| Settings | `config`, `cycle_time_standard`, `quality_entry` |
| FB_Log | `fb_run` + import pipeline (section 6.1-6.3) |
| Downtime_Review | `downtime_event` + review screen (6.4-6.5) |
| OEE_Dashboard | Calculation engine (7) + checks (8) + dashboard |
| History | `oee_snapshot` + trend screen |
| Lists | `loss_reason`, `station` |

---

## 16. Assumptions to confirm before development

1. Cycle time by colour = **line pitch in minutes per flight bar** (not total bath time).
2. Default pitch 12 min until the colour table is filled.
3. Normal pitch 14.6 min and stop threshold 20 min are taken from one day of data; review with more days.
4. Shift timings (A / B / C) and whether night shift data arrives in the same file.
5. Whether rejects are counted per flight bar or per piece.
6. Whether empty racks should always be treated as a loss (current rule) or only when no material is available.
7. Meaning of the surface-area unit and of the bath-by-bath columns 8-37.

---

## 17. Roadmap

1. **Phase 1 - Excel + script:** current workbook, proves the logic.
2. **Phase 2 - Web application:** database, import, review screen, dashboard, history, roles.
3. **Phase 3 - Machine signals:** hoist / rectifier / PLC run-stop feed replaces gap detection with exact stop times; reason selection happens at the stop. KPI engine and data model stay the same.
4. **Phase 4 - Analytics:** OEE by colour and part, surface-area-based performance, alerts, trend and anomaly detection.
