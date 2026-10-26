# Reference-based OEE dashboard refresh

## Problem and scope

Use the user's `deploy/PNS_Anodizing_Dashboard.html` as the visual reference for
the existing daily OEE dashboard, without its Hourly production (pieces) panel.
Preserve the source reference unchanged (it is user-owned and currently untracked).

## Requirements

- Adapt the reference's navy/white cards, section headings, production summary,
  time-loss bar/legend, downtime reason bars, recent batches and recent downtime.
  Keep the existing permission-aware navigation, logged-in identity and routes.
- Use real API data only. No sample names, sample machines, hardcoded KPIs,
  invented trends, prototype alerts, hourly chart or unsupported 8h duration.
- Preserve current machine/date/720-or-1440-minute/start-time controls, OEE
  formulas, exports, role permissions, and the separate date-range dashboard.
- Dashboard/View allows existing aggregate KPIs. Detailed batch rows additionally
  require Production report/View; reason breakdown/event rows additionally require
  Downtime/View. Hide restricted panels and do not send their source data.
- Time-loss segments use the selected fleet's total window budget, not a single
  machine's minutes. Downtime reasons use clipped interval unions per line with
  unplanned precedence, never a naive sum of overlapping event durations. For
  overlapping reasons of the same type, confirmed/approved human decisions win
  over proposals; stable event ordering resolves remaining ambiguity.
- Show real statuses (proposed/confirmed/approved/rejected), reason labels and
  empty/loading/error states. Do not claim a proposed gap is a verified PLC stop.
- Scope styling to the dashboard. Keep synchronization details/recent imports
  accessible in a lower expandable section. Preserve existing DOM metric IDs.
- No migrations, dependencies, changes to source imports, formulas, permissions,
  SCADA sender, other page designs, or mutation APIs.

## Acceptance and implementation plan

1. Pure tests prove reason breakdown clipping, overlap precedence, line isolation,
   ignored rejected stops and empty results. Detail queries are limited to six
   recent rows; reason totals cover the full selected window.
2. HTTP tests prove Dashboard-only roles receive no batch/downtime detail, the
   corresponding page grants expose only permitted details, and KPI values match
   existing report values for the same selection.
3. Browser tests prove all cards/rows/bars display API values, filters and links
   preserve selection, no hourly panel exists, restricted panels stay hidden,
   errors clear stale details, and 320/768/1024/1440px layouts remain usable.
4. Existing OEE, window, permission and Excel tests remain green. Inspect reference
   and final screenshots before deployment; no unrelated files enter the commit.

Implement the read-only detail projection first, then the scoped UI and rendering,
then browser/regression verification and normal cloud deployment. No blocking
questions. Rollback redeploys the prior revision; database schema is unchanged.

## Dashboard downtime tagging (2026-10-26)

Operators currently have to leave the dashboard to classify a stop. The dashboard
MUST offer Tag reason / Edit reason on recent downtime events to users with
Downtime/View and Update. View-only roles MUST remain read-only; no role grants
are added automatically. The dialog MUST show the actual line and full original
interval, including seconds and overnight dates, and edit only type, registered
active reason, and notes (500 characters). Cancel MUST NOT write anything.

Saving MUST confirm a proposed event, preserve confirmed/approved/rejected status
otherwise, and leave timestamps, source, evidence and report date unchanged.
The dashboard MUST refresh its selected window, KPIs and reason breakdown after
success; failed saves MUST retain the form and display the server error. Dialog
focus, Escape/Cancel and 320px layout MUST work. No formula/schema/sender changes.

Internal contract: POST `api.php?resource=oee&action=downtime-tag`, authenticated
with the existing session and X-CSRF-Token. Body:
`{event_id: 123, machine: "APP-06", event_type: "unplanned", reason_code: "HOIST", notes: "Reviewed"}`.
Success: `{ok: true, event_id: 123, updated: true}`. Errors use the existing
`{error: message}` envelope: 401 session, 403 permission, 419 CSRF, 422 invalid
input/inactive reason, 404 event not found for the supplied machine.

Implementation and acceptance sequence:

1. Add failing permission/storage tests for update-only access, active custom
   reasons, ID/line validation, exact interval preservation, status preservation,
   invalid inputs and repeated saves; implement a tag-only mutation (no insert).
2. Add failing browser coverage for opening/prefill, cancel without POST, saving
   correct event/line, post-save refresh, errors, read-only hiding and mobile
   layout; implement the dialog using the existing request/CSRF mechanism.
3. Run permission, OEE and dashboard regressions, inspect screenshots, commit and
   deploy through the existing cloud workflow. Verify live UI without retagging
   production stops. Rollback is a code revert; no migration is needed.

Trust boundary: HTTP/body and reason labels/notes are untrusted. The server MUST
check Update, bind SQL parameters, validate active registered reasons and match
the event's actual machine. Render labels/notes as text, never HTML. Existing
CSRF checks cover the new action. Only synthetic fixtures may be mutated in tests.
