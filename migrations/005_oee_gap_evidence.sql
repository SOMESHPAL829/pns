-- Additive. Apply before deploying the cycle-based gap detector.
-- NULL retains legacy/manual records; application never rewrites human decisions.
ALTER TABLE oee_downtime_events
    ADD COLUMN evidence_key VARCHAR(64) NULL,
    ADD UNIQUE KEY oee_downtime_evidence_key (line_code, evidence_key);
