# KARAM Anodizing OEE Cloud Upgrade

Status: slices 1-2 implemented locally
Version: 0.1
Date: 2026-10-21

## Purpose

Host the KARAM anodizing OEE application in the cloud and receive production
data from Unit 1 over an outbound HTTPS connection. The cloud application is
the system of record for imported reports, operator corrections, downtime
reasons, quality entries, and frozen OEE results.

## Target architecture

```text
Unit 1 SCADA API
  /scada-pns/report.php?date=YYYY-MM-DD
          |
          | HTTPS POST, API key, retry + idempotency
          v
Cloud ingest endpoint
  validates source, schema, date, rows, and payload hash
          |
          v
MySQL OEE storage
  raw import -> normalized FB runs -> events/quality -> OEE snapshots
          |
          v
KARAM portal authentication and OEE dashboard
```

The cloud must not connect directly to the Unit 1 private network or to the
plant SQL Server. Unit 1 is the source adapter and only makes outbound HTTPS
requests to the cloud.

## Local integration test

For local validation, use the PHP app on `127.0.0.1:4173` and point only the
OEE upload at the local endpoint. The source report can come from the live
Unit 1 reader:

```bash
export OEE_INGEST_API_KEY="$(<scada-pns/parent.key)"
export SCADA_APP06_REPORT_URL="http://172.20.3.32:8090/scada-pns/report.php"
export SCADA_OEE_PARENT_URL="http://127.0.0.1:4173/api/v1/ingest/scada-report.php"
python3 scada-pns/push_to_parent.py --oee-only --date 2026-10-17
```

The local PHP server must be started with `OEE_INGEST_API_KEY` in its
environment. This test does not call `apps.karam.in` and does not require the
cloud endpoint.

## Scope of this upgrade

### In scope

- Receive the complete 39-column APP-06 production report.
- Preserve the original payload and import metadata.
- Normalize one flight-bar row per `oee_fb_runs` record.
- Calculate OEE from imported data, configured cycle standards, approved loss
  events, and quality entries.
- Provide operator, supervisor, admin, and viewer access through the existing
  portal authentication.
- Support safe retries and replay without duplicate rows.
- Show the last successful Unit 1 synchronization time.

### Out of scope for the first release

- Direct cloud-to-plant database access.
- Live PLC/SCADA signal collection.
- Automatic approval of downtime reasons.
- Replacing the existing legacy six-field SCADA sync before the new path is
  verified.

## Cloud ingest contract

The planned endpoint is:

```text
POST /process-manufacturing/api/v1/ingest/scada-report.php
```

Headers:

```text
Content-Type: application/json
X-API-Key: <Unit 1 secret>
Idempotency-Key: <source>:<report_date>:<batch_id>
```

Request shape:

```json
{
  "schema_version": 1,
  "source": "UNIT-1-APP-06",
  "line_code": "UNIT-1",
  "report_date": "2026-10-17",
  "batch_id": "unit1-app06-20261017",
  "generated_at": "2026-10-21T10:00:00+05:30",
  "columns": ["si_no", "grade", "colour"],
  "rows": [
    {
      "si_no": 1,
      "grade": "7XXX",
      "colour": "BLACK-1",
      "loading_time": "00:07:12",
      "unloading_time": "01:58:17",
      "seq": "M10Y26-1531",
      "part": "AP-012(02)",
      "pcs": 72,
      "surface_area": 103.68,
      "raw_stage_data": {}
    }
  ]
}
```

The full Unit 1 report contains the authoritative stage values. The cloud
must retain them in JSON as well as extracting the fields required for OEE.
Unknown extra fields are rejected from calculations but may be preserved in
the raw payload for forward compatibility.

Successful response:

```json
{
  "ok": true,
  "batch_id": "unit1-app06-20261017",
  "import_id": 123,
  "status": "received",
  "inserted": 82,
  "updated": 0,
  "rejected": 0
}
```

## Upgrade sequence

### Slice 1 — foundation (started)

- Add the MySQL OEE schema migration.
- Add the cloud/Unit 1 contract and deployment notes.
- Add environment configuration names without storing secrets.

Done when the migration applies cleanly and existing `portal_*` tables are
unchanged.

### Slice 2 — authenticated ingest (implemented locally)

- The versioned endpoint is `api/v1/ingest/scada-report.php`.
- API key, JSON size, schema version, source, report date, and row limits are
  validated before database access.
- Immutable raw batches and normalized FB rows are stored transactionally.
- A matching replay is idempotent; a reused batch ID with different contents
  is rejected.

### Slice 3 — Unit 1 sender (implemented locally)

- `push_to_parent.py` still sends the legacy six-field stream.
- It now also calls `127.0.0.1:8090/scada-pns/report.php?date=YYYY-MM-DD` and
  posts the complete APP-06 rows to the versioned OEE endpoint.
- `--date YYYY-MM-DD` or `SCADA_REPORT_DATE` selects the report date; without
  either, the Unit 1 local date is used.
- GET and POST calls use bounded retry/backoff and the command prints separate
  legacy and OEE results.
- `SCADA_PARENT_URL` and `SCADA_OEE_PARENT_URL` can override the endpoints for
  staging.

### Slice 4 — OEE calculation

- Add cycle-time, shift, loss-reason, and quality administration.
- Generate proposed gaps and empty-rack events.
- Require operator reason assignment and supervisor approval.
- Freeze daily and shift snapshots.

### Slice 5 — dashboard and reports

- Add import, live shift, downtime review, OEE dashboard, Pareto, matrix,
  history, and trend screens.

## Security and reliability rules

- HTTPS is mandatory for Unit 1 to cloud traffic.
- The Unit 1 key is read from environment/secret storage and never committed.
- The ingest endpoint uses constant-time key comparison and generic auth errors.
- All SQL uses prepared statements.
- Payload size and row count are capped before parsing large data.
- Every batch has a canonical SHA-256 content hash (the transport timestamp is
  excluded) and an idempotency key.
- Raw imports and operator edits are auditable.
- API responses never include database credentials or API keys.
- Existing credentials found in legacy SCADA PHP files must be moved to secret
  storage and rotated before production rollout.

## Acceptance checks

For the supplied 17-10-2026 report, the first end-to-end release must show:

- 82 imported rows.
- 78 productive rows.
- 4 empty rows.
- 16 rows without unload time.
- 3 short-transit checks.
- 1 sequence jump check.
- Replaying the same batch creates no duplicate rows.
- Existing portal login and record screens still work.

## Decisions to confirm before production

1. Use MySQL for the first cloud release because the existing KARAM portal is
   already PHP/MySQL. The source specification's PostgreSQL model will be
   represented in MySQL-compatible tables.
2. Use the existing `apps.karam.in/process-manufacturing` host and add the
   versioned ingest path.
3. Push a final daily report first; add near-real-time current-day pushes after
   the daily path is stable.
4. Confirm shift A/B/C times, default cycle pitch, reject unit, and surface
   area unit before publishing OEE snapshots.

