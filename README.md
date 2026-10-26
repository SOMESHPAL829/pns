# KARAM App Portal

PHP and MySQL starter app based on the KARAM theme. It includes server-backed sign-in, one-time password recovery, profile and password updates, admin-managed users, and owner-scoped record CRUD.

## Requirements

- PHP 8.1+ with PDO MySQL
- MySQL 8+ or compatible MariaDB
- A configured PHP mail transport for password-reset email

## Configure

1. Copy `config.example.php` to `config.php`.
2. Set the MySQL connection values in `config.php`. This file is ignored by Git.
3. Set `APP_BASE_URL` to the public app URL and `APP_MAIL_FROM` to a sender address in the PHP environment.
4. Ensure the database account can create and use the `portal_*` tables.
5. From this directory, run `php setup.php admin@example.com admin "Portal Administrator"` and enter a password when prompted. The installer creates the tables and refuses to create a second initial admin.

The account recovery page returns the same confirmation for existing and unknown email addresses. Reset links expire after 30 minutes and can only be used once. PHP's `mail()` must be connected to a working mail transport for delivery.

## Run locally

From this directory:

```bash
php -S 127.0.0.1:4173
```

Open `http://127.0.0.1:4173/`. Do not use `python -m http.server`; PHP routes are required for authentication and database access.

## App screens

- `#/dashboard` — current user's recent records and summary
- `#/requests` — searchable, filterable record list
- `#/create` and `#/detail` — create and update records
- `#/users` and `#/user-create` — admin-only account management
- `#/profile` — update profile and change password

Administrators create accounts; there is no public sign-up route. Regular users can view and change only their own records. Administrators can manage users and view all records.

## Security notes

- Passwords use PHP's password hashing API; reset tokens are stored as hashes.
- Session cookies are HttpOnly and SameSite=Lax; unsafe requests require CSRF tokens.
- Use HTTPS in production. Configure the database and mail transport outside version control.
- Review and customize the sample record types, organization choices, and categories for your app.

## Anodizing OEE cloud upgrade

The OEE architecture and rollout plan are documented in
`OEE_CLOUD_UPGRADE_PLAN.md`. The first additive database migration is
`migrations/001_oee_foundation.sql`; it creates only `oee_*` tables and does
not modify the existing portal tables.

Before enabling Unit 1 ingestion, configure `OEE_INGEST_API_KEY` in the
server environment. Never place the key in `config.php`, source control, or
the Unit 1 report payload. The authenticated endpoint is now available at
`api/v1/ingest/scada-report.php`; it remains disabled until the migration and
server-side API key are configured. The next implementation slice updates
Unit 1's sender.

The authenticated app now includes:

- `#/dashboard` — OEE KPIs, production summary, time losses, sync health, and recent imports.
- `#/oee-report` — date-filtered flight-bar production report.
- `#/scada-logs` — Unit 1 sync status and cloud import event log.

### Formula-driven Excel export

Dashboard and production report offer **1440 min (24 hours)** and **720 min
(12 hours)**. The full-day default starts at midnight. For 720 minutes, choose
the window start; overnight windows include the next day's production until the
displayed end time. Flight bars are selected by loading timestamp, including the
start and excluding the end; downtime is clipped to the same window.

The report/export API accepts `duration_minutes=1440|720&start_time=HH:MM`
(`duration_hours=24|12` is also supported). Window utilization uses the selected
minutes per line; `utilization_24h` in API responses retains the fixed 1440-minute
meaning. Exports include the selection in their filename and workbook header.
12-hour views are calculated separately and do not replace stored DAY results.

12-hour quality uses entries matching `H12_HHMM` and the selected start date;
daily quality losses are never copied into both shifts. When matching quality
is missing, a visible warning explains the zero-loss assumption. Details and
acceptance criteria: `OEE_DURATION_SELECTION.md`. Run
`php tests/oee_window_test.php` for boundary/overnight/quality and weighted-total
checks.

The production report's **Export Excel** downloads FB-level cycle times, event
timestamps, quality records and reporting windows alongside Excel formulas.
The line table starts at row 13; all totals in G:O and R:S are formulas.
The editable window timestamps are in P:Q. Planned production time is
`MAX(0, window minutes - planned stops)`; run time is
`MAX(0, planned production minutes - unplanned downtime)`.

All five aggregate cells B6:B10 and all line percentages contain live Excel
formulas. Availability is run / planned production time; performance is ideal
output time / run time; quality is good / productive FB; OEE multiplies these
three ratios; utilization divides run time by calendar minutes. A zero
denominator returns zero. All-lines results use summed inputs, including 1440
calendar minutes per included line, rather than averaging percentages.

The FB detail table includes the applied cycle-time snapshot (L), productive
flag `IF(pcs > 0, 1, 0)` (M), and ideal minutes per FB (N). Productive FB and
ideal output minutes are summed from these detail formulas. Good FB is
`MAX(0, productive FB - rejected FB - reworked FB)`, using the **Quality** sheet.
No quality entry is visibly labelled as assuming zero quality losses.

The **Downtime** sheet contains event timestamps, types, lifecycle statuses,
and reporting-window clipping formulas. **Downtime calculation** sorts event
boundaries and counts active events per segment. Overlaps count only once;
unplanned takes priority over planned; rejected events do not count. The line
totals sum these segments and round minutes to three decimal places, matching
the stored daily snapshots. Calendar minutes are calculated from window
timestamps, rather than exported as a fixed 1440 value.

Editing exported source inputs changes the workbook only; it does not update
database records. Excel recalculates automatically on opening or editing.

Run `php tests/oee_export_test.php` to verify formulas, weighted totals, source
text handling and no-data cases. If `officecli` is installed, this test also
validates the workbook against the strict OpenXML schema.

## Date-range OEE dashboard

Open **Date-range OEE** in the sidebar (`app.php#/oee-range`). The original
daily dashboard and production report remain separate. Choose From date and
To date (inclusive, up to 93 days), one machine or all machines, and 1440 or
720 minutes **per day**. For 720 minutes choose the daily start time, then
click **Calculate OEE**. The page shows aggregate KPIs, line-wise OEE, a daily
breakdown, and time/production totals. Excel export uses the same selections.

Percentages use sums, not averages: availability = total run / total planned,
performance = total ideal / total run, quality = total good / total productive,
OEE = availability × performance × quality. Utilization = total run /
(days × selected lines × selected minutes). A 12-hour overnight range includes
each day's selected shift, not the daytime gaps. Empty days remain in the time
denominator. See [duration/range rules](OEE_DURATION_SELECTION.md).

API: authenticated `resource=oee&action=range-dashboard` or `range-export`, with
`from_date`, `to_date`, `machine`, `duration_minutes`, and `start_time`.
Excel has one calculation row per day/line; source formulas are isolated by
window so repeated line names do not multiply production or quality losses.

Verification: `php tests/oee_window_test.php`,
`php tests/oee_range_export_test.php`, and `php tests/oee_export_test.php`.
For isolated browser checks, install Playwright and run
`node tests/oee_range_browser_test.cjs` (optional `PLAYWRIGHT_MODULE` and
`CHROMIUM_EXECUTABLE` overrides). No database migration is required.

## Cycle-based gap detection

Since 26 October 2026, a loading gap strictly greater than 20 minutes proposes
`gap - arriving FB's registered cycle time` as downtime, not `gap - 14.6`.
Smaller excess gaps remain performance losses. Adjacent-day loads reveal
midnight stops, which are stored once and clipped to each reporting window.
Operator-confirmed/approved/rejected events retain their decisions through
stable evidence keys. Missing cycle standards keep the flagged 12-minute fallback.

Apply additive migration **before deploying this calculator**:
`php migrate-oee-gaps.php` (idempotent, CLI only). It adds nullable evidence keys
and a unique index, without changing human-entered records. Backup downtime and
snapshot tables before deployment. Rollback: revert the code commits and restore
the backed-up derived proposals/snapshots if required; the additive column can
remain. Keep user records and source imports intact.

Rules/examples: [OEE_GAP_RULES.md](OEE_GAP_RULES.md). Run
`php tests/oee_gap_test.php` plus the existing calculator, window, ingest and
Excel tests. Dashboard/report/export recalculate selected dates on demand;
no broad historical recalculation or Unit 1 sender change is required.

## Theme assets

`css/theme.css` contains design tokens. `css/portal.css` contains the shared layout and components. `js/icons.js` contains Heroicons Outline icons (MIT), and `js/portal.js` handles navigation and toasts. Add icons with `<span class="app-sidebar-icon" data-icon="users"></span>`.
