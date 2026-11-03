-- Preserve the unit recorded on each consumption entry.
-- Existing entries inherit the current unit from their chemical item.

ALTER TABLE chemical_consumption_entries
    ADD COLUMN uom VARCHAR(40) NULL AFTER consume_quantity;

UPDATE chemical_consumption_entries e
JOIN chemical_items i ON i.id = e.chemical_item_id
SET e.uom = i.uom
WHERE e.uom IS NULL OR TRIM(e.uom) = '';

ALTER TABLE chemical_consumption_entries
    MODIFY uom VARCHAR(40) NOT NULL;
