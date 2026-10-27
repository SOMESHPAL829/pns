<?php
declare(strict_types=1);

function sidebar_icon_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$shell = file_get_contents(dirname(__DIR__) . '/app-shell.php');
$registry = file_get_contents(dirname(__DIR__) . '/js/icons.js');
sidebar_icon_check($shell !== false && $registry !== false, 'Sidebar icon files could not be read.');

preg_match_all('/data-icon="([a-z0-9-]+)"/', $shell, $iconMatches);
$usedIcons = $iconMatches[1];
sidebar_icon_check(count($usedIcons) > 0, 'No centralized sidebar icons were found.');
sidebar_icon_check(count($usedIcons) === count(array_unique($usedIcons)), 'A sidebar heading or action reuses another icon.');
sidebar_icon_check(!str_contains($shell, '<svg'), 'Page template contains a hardcoded SVG icon.');

preg_match_all('/["\']?([a-z0-9-]+)["\']?\s*:\s*svg\(/', $registry, $registryMatches);
$registeredIcons = $registryMatches[1];
foreach ($usedIcons as $icon) {
    sidebar_icon_check(in_array($icon, $registeredIcons, true), 'Sidebar icon is missing from icons.js: ' . $icon);
}
sidebar_icon_check(str_contains($registry, 'https://heroicons.com'), 'Heroicons source is not documented in icons.js.');

echo "Unique centralized Heroicons sidebar test passed\n";
