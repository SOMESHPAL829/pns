<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/oee_calculator.php';

$metrics = oee_metrics(100, 10, 20, 70, 10, 1, 1);
if (abs($metrics['planned_minutes'] - 90) > 0.001) throw new RuntimeException('planned time failed');
if (abs($metrics['run_minutes'] - 70) > 0.001) throw new RuntimeException('run time failed');
if (abs($metrics['quality'] - 0.8) > 0.001) throw new RuntimeException('quality failed');
if (abs($metrics['oee'] - (70 / 90 * 1 * 0.8)) > 0.001) throw new RuntimeException('oee failed');

$clamped = oee_metrics(100, 20, 100, 50, 5);
if ($clamped['run_minutes'] !== 0.0) throw new RuntimeException('run time must not be negative');
if ($clamped['availability'] !== 0.0) throw new RuntimeException('availability must be zero when no run time remains');

echo "OEE calculator tests passed\n";
