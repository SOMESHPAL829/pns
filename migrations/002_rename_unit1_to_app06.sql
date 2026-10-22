-- Rename the internal Unit 1 APP-06 line code without changing report history.
-- The ingest endpoint continues to accept legacy payloads using UNIT-1.

UPDATE oee_import_batches SET line_code = 'APP-06' WHERE line_code = 'UNIT-1';
UPDATE oee_fb_runs SET line_code = 'APP-06' WHERE line_code = 'UNIT-1';
UPDATE oee_cycle_time_standards SET line_code = 'APP-06' WHERE line_code = 'UNIT-1';
UPDATE oee_shift_definitions SET line_code = 'APP-06' WHERE line_code = 'UNIT-1';
UPDATE oee_downtime_events SET line_code = 'APP-06' WHERE line_code = 'UNIT-1';
UPDATE oee_quality_entries SET line_code = 'APP-06' WHERE line_code = 'UNIT-1';
UPDATE oee_snapshots SET line_code = 'APP-06' WHERE line_code = 'UNIT-1';
