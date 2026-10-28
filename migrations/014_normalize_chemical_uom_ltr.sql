-- Normalize the litre UOM label without changing quantities, rates, or relationships.
UPDATE chemical_items
SET uom = 'LTR'
WHERE UPPER(TRIM(uom)) = 'L';
