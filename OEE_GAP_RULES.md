# Cycle-based suspected downtime (approved 26 October 2026)

The fixed 14.6-minute deduction misses the user's registered colour cycle time,
and daily-only detection misses adjacent loads across midnight.

## Rules and acceptance criteria

- For consecutive loads ending in a productive FB, a gap strictly greater than
  20 minutes proposes a stop from `previous load + arriving FB ideal cycle` to
  `arriving load`. Use that FB's effective colour/date master, with the existing
  flagged 12-minute fallback when no standard exists. A non-positive remainder
  creates no event. Gaps of 20 minutes or less remain performance losses.
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
(10m23s). 22 Oct 23:34:22→23 Oct 00:06:04 with CT 10 produces
23:44:22→00:06:04 (15m38s on the 22nd, 6m04s on the 23rd).

Verify pure candidate rules, SQLite persistence/idempotency and legacy decisions,
effective dates/fill-forward/fallback, midnight clipping, overlapping losses,
existing OEE/ingest/window/Excel tests, and live API/export parity. Migration
005 adds only nullable evidence keys and a unique index; apply it before code.
