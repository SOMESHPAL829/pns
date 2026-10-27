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
6. Run `php migrate-permissions.php`, then `php migrate-export-permissions.php` to install page-level roles and permissions.
7. Run `php migrate-chemicals.php` to install chemical items and effective-dated INR rates.
8. Run `php migrate-chemical-consumption.php` to install the chemical consumption ledger.
9. Run `php migrate-breakdowns.php` to add the planned and unplanned breakdown reasons.

The account recovery page returns the same confirmation for existing and unknown email addresses. Reset links expire after 30 minutes and can only be used once. PHP's `mail()` must be connected to a working mail transport for delivery.

## Run locally

From this directory:

```bash
php -S 127.0.0.1:4173
```

Open `http://127.0.0.1:4173/`. Do not use `python -m http.server`; PHP routes are required for authentication and database access.

## Icon Standard

Interface icons come from the official [Heroicons](https://heroicons.com) outline
set and are registered centrally in `js/icons.js`. Reference icons through a
`data-icon` name; do not embed SVG markup in page templates. Sidebar actions
must use distinct icons that communicate their individual purpose.

## App screens

- `#/dashboard` — current user's recent records and summary
- `#/requests` — searchable, filterable record list
- `#/create` and `#/detail` — create and update records
- `#/users` and `#/user-create` — permission-controlled account management
- `#/roles` — protected administrator role/permission editor
- `#/chemical-registration` — permission-controlled chemical items and effective-dated INR rates
- `#/chemical-consumption` — daily chemical consumption entries by operator, machine, and process
- `#/chemical-consumption-report` — printable date-range and machine-filtered chemical consumption report
- `#/chemical-cost-report` — weekly, monthly, or consolidated chemical cost per dm² report
- `#/profile` — update profile and change password

## Chemical Registration

Open **Administration → Chemical Registration** to register an item code,
description, Active/Inactive status, and UOM. Each item can have effective-dated
rates in INR. Effective dates are inclusive, and overlapping rate periods for
the same item are rejected so the current rate is always unambiguous.

Run `php migrate-chemicals.php` before opening this page. Access is controlled
independently through Chemical Registration View/Create/Update permissions;
new custom roles and the built-in Standard User role receive no access by default.

## Chemical Consumption

Open **Workspace → Chemical Consumption** to record Date, Shift, Operator Name,
Machine Code, Process Description, Chemical Name, Consume Quantity, Used For,
Recommended By, and Remarks. Chemical names are selected from Chemical
Registration, quantities display the registered UOM, and inactive chemicals
cannot be used for new entries. Used For is limited to Fresh Makeup or Top-Up.

Run `php migrate-chemical-consumption.php` after the chemical registration
migration. Access is controlled independently through Chemical Consumption
View/Create/Update permissions and is denied to new custom roles and the
built-in Standard User role until explicitly granted.

## Chemical Consumption Report

Open **Workspace → Chemical Consumption Report** to filter consumption entries
by From Date, To Date, and Machine. The report follows the operational register
format, includes the chemical name and item code together, shows quantity with
its UOM, and provides a landscape print layout. Report access is controlled by
the independent Chemical Consumption Report View permission.

## Chemical Cost Report

Open **Workspace → Chemical Cost Report** to select From Date, To Date, Machine,
and a Weekly Basis, Monthly Basis, or Consolidated report. Total Chemical Cost
uses each consumption entry's effective INR item rate. Chemical Cost per dm² is
calculated as Total Chemical Cost divided by production Total Surface Area from
the OEE feed. When an effective rate is missing, the affected cost is withheld
and clearly flagged instead of being understated. The report supports landscape
printing and a maximum date range of 366 days.

## Breakdown Registration

Open **Administration → Breakdown Registration** to create, edit, and delete
planned or unplanned loss reasons. The page uses the OEE reason master so saved
reasons are available in Downtime Review; its reason selector filters by the
selected loss type. A reason cannot be deleted while it is referenced by a
downtime record. Run `php migrate-breakdowns.php` after installing the OEE
foundation and reason-master migrations to add the loss type field and seed the
default reason list. Access is controlled by the Breakdown Registration
View/Create/Update/Delete permissions; Downtime Review users need Downtime/View
to load the active reason choices.

Authorized account managers create accounts; there is no public sign-up route. Record ownership restrictions still apply in addition to role grants. Protected administrators retain full access and can view all records.

## Roles and page permissions

Open **Administration → Roles & permissions**, create a named role, and select
View/Create/Update/Delete/Export for each supported page action. Every existing page is
listed. Read-only pages show dashes for unsupported actions; no new deletion of
SCADA data or user accounts is introduced. Users activate/deactivate with Update.
View is required for mutations. Excel downloads require **View + Export** for
the corresponding report: Production report and Date-range OEE have separate grants.
Users without Export can still view allowed reports, but see no download button;
direct export API requests return 403. New roles default to Export disabled.
OEE threshold edits require master-data Update, not Create.

Assign the role under **Users → Save role**, or choose it when creating an account.
Reassignment invalidates the user's sessions. Permission edits apply on the next
API request; users should refresh to update menus/buttons. Direct API requests
are checked even if an old page is still open. New roles start with no access.

Existing administrators are protected full-access accounts. Their role/status
cannot be changed through these screens, and custom roles cannot administer the
permission system. The immutable **Standard user** role preserves prior regular
user access. Create a custom role instead of editing it. Users cannot change their
own role, assign stronger roles than their own, or delete an assigned/built-in role.

Run additive, idempotent `php migrate-permissions.php` **before deploying** this
version. It creates migration 007 tables without changing existing users, login
credentials, OEE settings or SCADA ingestion. Back up `portal_users`, access tables,
and current deployed code privately. Rollback can leave the additive tables intact,
but the old version reverts to the broad admin/user policy. Security-sensitive
role creation/changes/deletion/assignment/status changes have database audit records.

For the independent Export permission, also run `php migrate-export-permissions.php`
before deployment. Migration 008 preserves existing report download rights once,
records completion, and never restores later revoked grants when rerun. It does
not change other page permissions. Back up access tables and the migration ledger
privately. Rollback to older code restores the old View-implies-export policy.

Tests: `php tests/permissions_test.php`, `php tests/export_permissions_test.php`; isolated browser test
`node tests/permissions_browser_test.cjs` (Playwright/Chromium overrides supported).
Full database/HTTP integration: `OEE_TEST_BASE_URL=http://127.0.0.1:4173 php
tests/permissions_api_test.php`. This opt-in test creates uniquely named temporary
accounts/roles/records and removes those fixtures in `finally`; do not run it on
production without a backup. See [ROLE_PERMISSIONS.md](ROLE_PERMISSIONS.md).

## OEE dashboard presentation

The daily dashboard adapts the supplied `PNS_Anodizing_Dashboard.html` reference,
without its hourly production chart or sample data. It shows live KPI cards,
production totals, productive rack rate, a fleet-aware time allocation bar,
downtime reason breakdown, recent batches and recent downtime events. Detailed
rows require the corresponding Production report/View or Downtime/View permission;
Dashboard/View alone still allows the aggregate KPIs. Synchronization/import
details remain in an expandable section. Report links preserve the selected
machine/date/duration. The separate date-range dashboard is unchanged.

Presentation code is isolated in `includes/dashboard.php`, `css/oee-dashboard.css`
and `js/dashboard.js`. The dashboard API adds permission-gated read-only detail
fields; reason durations union overlapping events per line and clip to the selected
window. No OEE formula or database migration changes. See [OEE_DASHBOARD_UI.md](OEE_DASHBOARD_UI.md).
Verify with `php tests/oee_dashboard_view_test.php`, the existing HTTP permission
test, and `node tests/oee_dashboard_browser_test.cjs` (Playwright overrides supported).

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

Dashboard, production report, and date-range report offer **480 min (8 hours)**,
**600 min (10 hours)**, **720 min (12 hours)**, **960 min (16 hours)**, and
**1440 min (24 hours)**. The full-day default starts at midnight. For partial-day
windows, choose the start time; overnight windows include the next day's
production until the displayed end time. Flight bars are selected by loading
timestamp, including the start and excluding the end; downtime is clipped to the
same window.

The report/export API accepts `duration_minutes=480|600|720|960|1440&start_time=HH:MM`
(`duration_hours=8|10|12|16|24` is also supported). Window utilization uses the
selected minutes per line; `utilization_24h` in API responses retains the fixed
1440-minute meaning. Exports include the selection in their filename and
workbook header. Partial-day views are calculated separately and do not replace
stored DAY results.

Partial-day quality uses entries matching `H<duration>_HHMM` and the selected
start date; daily quality losses are never copied into a partial window. When
matching quality is missing, a visible warning explains the zero-loss
assumption. Details and acceptance criteria: `OEE_DURATION_SELECTION.md`. Run
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
To date (inclusive, up to 93 days), one machine or all machines, and 480, 600,
720, 960 or 1440 minutes **per day**. For partial-day windows choose the daily
start time, then
click **Calculate OEE**. The page shows aggregate KPIs, line-wise OEE, a daily
breakdown, and time/production totals. Excel export uses the same selections.

Percentages use sums, not averages: availability = total run / total planned,
performance = total ideal / total run, quality = total good / total productive,
OEE = availability × performance × quality. Utilization = total run /
(days × selected lines × selected minutes). An overnight partial-day range includes
each day's selected window, not the uncovered time. Empty days remain in the time
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

Since 26 October 2026, a loading gap strictly greater than the plant threshold
(default 20 minutes) proposes
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

### Plant gap thresholds

OEE master data → **Plant gap threshold** lists APP-06, APP-07 and APP-10.
An administrator can select a plant and save 0.001–1440 minutes (three decimal
places maximum). Equal-to-limit gaps do not qualify. This is the detection
limit, not the cycle time deducted from the gap; empty-rack rules are unchanged.
Refresh the dashboard/report after saving. Historical reports use the current
setting on recalculation, while tagged/approved/rejected downtime is preserved.

Run `php migrate-oee-settings.php` **before deploying** this feature. Migration
006 only creates a settings table; no source imports or operator events change.
The migration is idempotent and defaults missing plant rows to 20 minutes.
Rollback: deploy the preceding code revision; leave the additive table intact.
Keep a private backup of downtime/snapshots before deployment. For tests run
`php tests/oee_threshold_test.php` and `node tests/oee_threshold_browser_test.cjs`
(same Playwright/Chromium overrides as the date-range browser test).

## Theme assets

`css/theme.css` contains design tokens. `css/portal.css` contains the shared layout and components. `js/icons.js` contains Heroicons Outline icons (MIT), and `js/portal.js` handles navigation and toasts. Add icons with `<span class="app-sidebar-icon" data-icon="users"></span>`.
