# Cycle-based suspected downtime (approved 26 October 2026)

The fixed 14.6-minute deduction misses the user's registered colour cycle time,
and daily-only detection misses adjacent loads across midnight.

## Rules and acceptance criteria

### Plant-specific threshold setting (26 October 2026)

Problem: operators cannot configure different loading-gap limits for APP-06,
APP-07 and APP-10 without changing source code.

Requirements: OEE master data MUST show each plant's current threshold and allow
administrators to edit it in minutes (0.001–1440, up to three decimal places).
Existing plants MUST default to 20 minutes. A gap MUST be strictly greater than
the selected plant's threshold; the cycle deduction MUST remain unchanged.
Saving MUST be protected by the existing admin/session/CSRF checks and persist
across reloads. Current settings apply to historical reports when recalculated,
but MUST NOT overwrite confirmed, approved, rejected or manual events.

Acceptance: given the same 25-minute gap, limits of 20 and 30 minutes produce
one and zero gap proposals respectively. Equality produces none. Changing one
plant MUST NOT affect another. Lowering/raising the limit MUST create/remove
only automatic proposals on report refresh, including across midnight.
Invalid limits and unauthorized requests MUST fail without changing settings.
The form MUST show save/error states and work at 320/768/1024/1440px.

Non-goals: effective-dated threshold history, changing cycle times, the separate
empty-rack rule, broad historical backfills, or changes on the Unit 1 sender.
Constraint: additive migration, existing PHP/PDO and portal UI only; no new
dependencies. No blocking open questions. Implement persistence/detection first,
then master-data API/UI, then browser/regression checks and deployment.

- For consecutive loads ending in a productive FB, a gap strictly greater than
  the plant's registered threshold (default 20 minutes) proposes a stop from `previous load + arriving FB ideal cycle` to
  `arriving load`. Use that FB's effective colour/date master, with the existing
  flagged 12-minute fallback when no standard exists. A non-positive remainder
  creates no event. Gaps at or below the plant threshold remain performance losses.
- The ideal cycle is a throughput pitch per FB, not load-to-unload residence
  time. OEE formulas do not change; stops and slow running are not subtracted twice.
- Read the preceding and following calendar dates as boundary context. Infer
  only observed load-to-load gaps, not an arbitrary stop before the first or
  after the last available record. Do not bridge missing calendar-day reports.
- A midnight-crossing event is stored once, assigned to its start date, and
  clipped to each daily/12h reporting window. Recalculation of either adjacent
  date must give the same event and total, independent of refresh order.
- Preserve the existing empty-rack rule: from preceding productive load plus
  its registered cycle to the last empty load. Carry runs across midnight.
  Overlapping empty/gap/manual stops count once in the existing interval union.
- Stable evidence keys use line, reporting dates and sequence identifiers
  (SI fallback), never replaceable database row IDs. Preserve all confirmed,
  approved and rejected events, including operator-adjusted times and notes.
  Legacy unkeyed human gap events suppress overlapping regenerated candidates.
- Rebuild only proposed automatic events within the affected date/window, in
  a transaction. Repeated recalculation and re-import must not duplicate events.
  New notes identify source loads, raw gap, applied cycle and missing standards.
- Keep Unit 1 raw SCADA APIs/sender unchanged: calculations live in the cloud.
  No broad backfill or rewriting of exported user files is part of this fix.

Examples: 00:30:25→00:50:48 with CT 10 produces 00:40:25→00:50:48
(10m23s, with the default 20-minute threshold). 22 Oct 23:34:22→23 Oct 00:06:04 with CT 10 produces
23:44:22→00:06:04 (15m38s on the 22nd, 6m04s on the 23rd).

Verify pure candidate rules, SQLite persistence/idempotency and legacy decisions,
effective dates/fill-forward/fallback, midnight clipping, overlapping losses,
existing OEE/ingest/window/Excel tests, and live API/export parity. Migration
005 adds only nullable evidence keys and a unique index; apply it before code.
Migration 006 adds `oee_line_settings`; run `php migrate-oee-settings.php`
before deploying configurable thresholds. Re-running does not reset settings.

API: authenticated `GET api.php?resource=oee&action=masters` adds a `thresholds`
array with `line_code` and numeric `gap_threshold_minutes`. Administrators save
using the existing CSRF-protected `POST ...action=master-save`, JSON
`{"master_type":"threshold","line_code":"APP-06","gap_threshold_minutes":25}`.
Success returns `ok`, `line_code`, `gap_threshold_minutes`; validation is 422,
unauthenticated 401, non-admin 403, invalid CSRF 419, using existing error format.
The setting is a current per-plant value, not an effective-dated schedule.
