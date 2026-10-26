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
