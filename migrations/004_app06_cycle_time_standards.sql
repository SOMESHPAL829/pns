-- Confirmed cycle-time standards from Anodizing_OEE_Software_Spec.md section 12 (T4).
-- Rebuild only automatically proposed gap events; preserve manual/confirmed edits.
DELETE FROM oee_downtime_events WHERE source = 'gap' AND status = 'proposed';

INSERT INTO oee_cycle_time_standards (line_code, colour, minutes_per_fb, effective_from)
VALUES
('APP-06', 'BLACK-1', 14.000, '2026-10-17'),
('APP-06', 'BLACK-2', 15.000, '2026-10-17'),
('APP-06', 'CLEAR', 12.000, '2026-10-17')
ON DUPLICATE KEY UPDATE minutes_per_fb = VALUES(minutes_per_fb);
