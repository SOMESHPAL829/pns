-- Standard planned and unplanned breakdown/loss names.
-- The migration runner adds oee_reason_codes.loss_type before executing this seed.

INSERT INTO oee_reason_codes (code, label, loss_type) VALUES
('TEA_LUNCH_DINNER_BREAK', 'Tea, Lunch & Dinner Break', 'planned'),
('PREVENTIVE_MAINTENANCE', 'Preventive Maintenance', 'planned'),
('WORKER_TRAINING', 'Worker Training', 'planned'),
('TOOL_BOX_TALK', 'Tool Box Talk', 'planned'),
('NO_PLAN', 'No Plan', 'planned'),
('TRANSPORTER_BREAKDOWN', 'Transporter Breakdown', 'unplanned'),
('CHILLER_PROBLEM', 'Chiller Problem', 'unplanned'),
('HEATER_PROBLEM', 'Heater Problem', 'unplanned'),
('RECTIFIER_PROBLEM', 'Rectifier Problem', 'unplanned'),
('NO_MANPOWER', 'No Manpower', 'unplanned'),
('NO_MATERIAL', 'No Material', 'unplanned'),
('CHEMICAL_ADDITION', 'Chemical Addition', 'unplanned'),
('NEW_BATH_PREPARATION', 'New Bath Preparation', 'unplanned'),
('COMPRESSED_AIR_PRESSURE_NOT_AVAILABLE', 'Compressed Air Pressure Not Available', 'unplanned'),
('PNG_GAS_SUPPLY_NOT_AVAILABLE', 'PNG Gas Supply Not Available', 'unplanned'),
('OVEN_BREAKDOWN', 'Oven Breakdown', 'unplanned'),
('CHEMICAL_FLOW_PUMP_NOT_WORKING', 'Chemical Flow Pump Not Working', 'unplanned'),
('POWER_SUPPLY_FAILURE', 'Power Supply Failure', 'unplanned'),
('JIG_AND_FLIGHT_BAR_FAILURE', 'Jig and Flight Bar Failure', 'unplanned'),
('AIR_KNIFE_NOT_WORKING', 'Air Knife Not Working', 'unplanned'),
('V_BLOCK_DAMAGED', 'V-Block Damaged', 'unplanned'),
('CHEMICAL_NOT_AVAILABLE', 'Chemical Not Available', 'unplanned'),
('SHOT_BLASTING_MACHINE_NOT_WORKING', 'Shot Blasting Machine Not Working', 'unplanned'),
('LIMIT_SWITCH_FAILURE', 'Limit Switch Failure', 'unplanned'),
('WATER_SUPPLY_NOT_AVAILABLE', 'Water Supply Not Available', 'unplanned'),
('CHEMICAL_TANK_LEAKAGE', 'Chemical Tank Leakage', 'unplanned'),
('POWER_SUPPLY_FLUCTUATION', 'Power Supply Fluctuation', 'unplanned'),
('PLC_SYSTEM_NOT_WORKING', 'PLC System Not Working', 'unplanned'),
('SENSOR_NOT_WORKING', 'Sensor Not Working', 'unplanned')
ON DUPLICATE KEY UPDATE label=VALUES(label),loss_type=VALUES(loss_type),is_active=1;
