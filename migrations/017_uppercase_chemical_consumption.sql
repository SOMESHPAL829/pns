-- Normalize existing chemical master and consumption text to the format enforced by the application.
UPDATE chemical_items
SET item_code = UPPER(TRIM(item_code)),
    description = UPPER(TRIM(description)),
    uom = UPPER(TRIM(uom));

UPDATE chemical_consumption_entries
SET shift_name = UPPER(TRIM(shift_name)),
    operator_name = UPPER(TRIM(operator_name)),
    machine_code = UPPER(TRIM(machine_code)),
    process_description = UPPER(TRIM(process_description)),
    uom = UPPER(TRIM(uom)),
    used_for = UPPER(TRIM(used_for)),
    recommended_by = UPPER(TRIM(recommended_by)),
    remarks = CASE WHEN remarks IS NULL THEN NULL ELSE UPPER(TRIM(remarks)) END;
