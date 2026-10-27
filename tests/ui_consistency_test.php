<?php
declare(strict_types=1);

function ui_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$shell = file_get_contents(dirname(__DIR__) . '/app-shell.php');
$login = file_get_contents(dirname(__DIR__) . '/index.php');
$styles = file_get_contents(dirname(__DIR__) . '/css/portal.css');
$dashboardStyles = file_get_contents(dirname(__DIR__) . '/css/oee-dashboard.css');
ui_check(is_string($shell) && is_string($login) && is_string($styles) && is_string($dashboardStyles), 'UI source files could not be read.');

ui_check(!str_contains($shell, 'app-header-profile-card'), 'Profile dropdown contains content beyond Profile and Sign Out options.');
ui_check(substr_count($shell, 'data-logout-form') === 2, 'Both portal logout actions must use loading feedback.');
ui_check(str_contains($login, 'data-loading-form') && str_contains($login, 'auth-message-transient'), 'Login/logout feedback is incomplete.');
ui_check(str_contains($shell, 'id="welcome-message"') && str_contains($shell, 'id="page-transition"'), 'Welcome or transition UI is missing.');
ui_check(substr_count($shell, 'class="page-transition-dialog"') === 1 && str_contains($login, 'class="page-transition-dialog"'), 'Premium login/logout transition dialogs are missing.');
ui_check(str_contains($styles, '.data-table th') && str_contains($styles, 'background: var(--accent-deep)'), 'Shared table headers do not use the blue theme.');
ui_check(str_contains($dashboardStyles, '.pns-dashboard .data-table th { background: var(--accent-deep); color: #fff;'), 'Dashboard table headers do not use the blue theme.');
ui_check(str_contains($styles, '.oee-kpi:hover') && str_contains($styles, 'border-color: var(--accent)'), 'KPI hover highlighting is missing.');
ui_check(!str_contains($styles, 'border-top: 3px solid var(--accent)'), 'KPI cards still use the heavy blue top border.');
ui_check(str_contains($styles, '.data-scroll { width: 100%; scrollbar-gutter: auto; }'), 'Table containers still reserve a blank right-side gutter.');
ui_check(substr_count($shell, 'SR. No.') === 3 && !preg_match('/S[lr]\. No\./', str_replace('SR. No.', '', $shell)), 'Serial number table headings are inconsistent.');
ui_check(str_contains($styles, '.table-action') && str_contains($styles, '.user-actions'), 'Shared table action button styling is missing.');
ui_check(str_contains($styles, '.app-sidebar-link.is-active') && str_contains($styles, 'box-shadow: inset 3px 0 0 var(--accent)'), 'Sidebar active spacing/highlight treatment is missing.');

echo "Portal UI consistency and authentication feedback tests passed\n";
