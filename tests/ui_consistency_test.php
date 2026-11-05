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
$dashboard = file_get_contents(dirname(__DIR__) . '/includes/dashboard.php');
$appScript = file_get_contents(dirname(__DIR__) . '/js/app.js');
$portalScript = file_get_contents(dirname(__DIR__) . '/js/portal.js');
$tablePaginationScript = file_get_contents(dirname(__DIR__) . '/js/table-pagination.js');
$chemicalCostScript = file_get_contents(dirname(__DIR__) . '/js/chemical-cost-report.js');
$requiredFields = file_get_contents(dirname(__DIR__) . '/js/required-fields.js');
$password = file_get_contents(dirname(__DIR__) . '/password.php');
ui_check(is_string($shell) && is_string($login) && is_string($styles) && is_string($dashboardStyles) && is_string($dashboard) && is_string($appScript) && is_string($portalScript) && is_string($tablePaginationScript) && is_string($chemicalCostScript) && is_string($requiredFields) && is_string($password), 'UI source files could not be read.');
ui_check(str_contains($requiredFields, 'form input[required]') && str_contains($requiredFields, 'required-marker') && str_contains($requiredFields, 'MutationObserver'), 'Required-field markers do not cover static and dynamic forms.');
ui_check(str_contains($shell, 'js/required-fields.js') && str_contains($login, 'js/required-fields.js') && str_contains($password, 'js/required-fields.js'), 'Required-field markers are not loaded on every authentication and portal form page.');
ui_check(!str_contains($shell, 'uppercase-fields.js') && str_contains($styles, '.data-table tbody td') && !str_contains($styles, '.uppercase-field'), 'Uppercase presentation must be limited to table values.');

ui_check(!str_contains($shell, 'app-header-profile-card'), 'Profile dropdown contains content beyond Profile and Sign Out options.');
ui_check(substr_count($shell, 'data-logout-form') === 2, 'Both portal logout actions must use loading feedback.');
ui_check(str_contains($shell, 'class="app-sidebar-account"') && str_contains($shell, 'data-goto="profile"') && str_contains($shell, 'class="app-sidebar-footer-logout"') && str_contains($shell, 'data-icon="arrow-right-start-on-rectangle"'), 'Sidebar account footer must open Profile and expose an outward-facing logout icon.');
$sidebarTogglePosition = strpos($shell, 'id="sidebar-toggle"');
$sidebarEndPosition = strpos($shell, '</aside>');
$topbarPosition = strpos($shell, 'class="app-topbar"');
ui_check($sidebarTogglePosition !== false && $sidebarEndPosition !== false && $topbarPosition !== false && $sidebarTogglePosition < $sidebarEndPosition && $sidebarTogglePosition < $topbarPosition, 'The desktop sidebar toggle must live beside the sidebar brand, not in the topbar.');
ui_check(str_contains($portalScript, 'event.detail > 0') && str_contains($portalScript, 'button.blur()'), 'The collapsed sidebar logo can remain hidden by stale pointer focus.');
ui_check(str_contains($login, 'data-loading-form') && str_contains($login, 'auth-message-transient'), 'Login/logout feedback is incomplete.');
ui_check(str_contains($login, 'Your account is inactive. Contact your administrator.'), 'Inactive users must receive the requested sign-in guidance after valid credentials.');
ui_check(!str_contains($appScript, 'appendChild(toggle)') && str_contains($appScript, 'window.PortalFormatDateTime = readableDateTime'), 'User rendering still references the removed status toggle or table date formatting is unavailable.');
ui_check(str_contains($shell, 'class="data-table downtime-events-table"') && str_contains($styles, '.downtime-events-table .downtime-col-notes { width: 34rem; }') && str_contains($styles, '.data-table td.table-date-cell { white-space: nowrap; }'), 'Downtime notes sizing or one-line table dates are missing.');
ui_check(str_contains($tablePaginationScript, 'Date.UTC(Number(match[3])'), 'DD-MM-YYYY table sorting is not date-aware.');
ui_check(str_contains($shell, 'id="user-role-active"') && !str_contains($appScript, 'data-toggle-user') && !str_contains($appScript, '" — " + scopeLabel'), 'User status must be managed in the access modal and role selects must not repeat plant scope.');
ui_check(str_contains($shell, 'id="welcome-message"') && str_contains($shell, 'id="page-transition"'), 'Welcome or transition UI is missing.');
ui_check(substr_count($shell, 'class="page-transition-dialog"') === 1 && str_contains($login, 'class="page-transition-dialog"'), 'Premium login/logout transition dialogs are missing.');
ui_check(str_contains($styles, '.data-table th') && str_contains($styles, 'background: var(--accent-deep)'), 'Shared table headers do not use the blue theme.');
ui_check(str_contains($dashboardStyles, '.pns-dashboard .data-table th { background: var(--accent-deep); color: #fff;'), 'Dashboard table headers do not use the blue theme.');
ui_check(str_contains($styles, '.oee-kpi:hover') && str_contains($styles, 'border-color: var(--accent)'), 'KPI hover highlighting is missing.');
ui_check(!str_contains($styles, 'border-top: 3px solid var(--accent)'), 'KPI cards still use the heavy blue top border.');
ui_check(str_contains($styles, '.data-scroll { width: 100%; scrollbar-gutter: auto; }'), 'Table containers still reserve a blank right-side gutter.');
ui_check(str_contains($styles, '.data-table th:not(.is-filter-heading) { min-width: max-content; }') && str_contains($styles, 'min-width: clamp(8.5rem, 11vw, 10.5rem);'), 'Table headings can still collapse or clip after filters load.');
ui_check(str_contains($styles, '.table-page-navigation { max-width: 100%; flex-wrap: wrap; justify-content: flex-end; }'), 'Table pagination can still clip its Next button in narrow cards.');
ui_check(!preg_match('/<th[^>]*>\s*(?:SR\.?\s*No\.?|Sr\.?|SRNO)\s*<\/th>/i', $shell), 'Serial number columns should not be rendered.');
ui_check(str_contains($shell, 'class="app-topbar-title" id="page-title"') && !str_contains($shell, 'id="page-eyebrow"') && !str_contains($shell, 'id="page-subtitle"'), 'Topbar should contain only the main page heading.');
ui_check(str_contains($styles, '.table-action') && str_contains($styles, '.user-actions'), 'Shared table action button styling is missing.');
ui_check(str_contains($styles, '*::-webkit-scrollbar-thumb') && str_contains($styles, 'scrollbar-color: var(--scroll-thumb) var(--scroll-track)'), 'Shared themed scrollbar styling is missing.');
ui_check(str_contains($styles, '.app-sidebar-header {') && str_contains($styles, 'border-bottom: 1px solid var(--line)'), 'Sidebar/topbar divider alignment is missing.');
ui_check(str_contains($styles, '.app-sidebar-link.is-active') && str_contains($styles, 'box-shadow: inset 3px 0 0 var(--accent)'), 'Sidebar active spacing/highlight treatment is missing.');
ui_check(str_contains($styles, '.oee-log-grid { display: grid; grid-template-columns: minmax(0, 1fr);'), 'SCADA cards are not stacked vertically.');
ui_check(str_contains($shell, 'class="profile-settings-grid"') && !str_contains($shell, 'data-modal-open="profile-form"'), 'Profile forms are not visible inline.');
ui_check(str_contains($dashboard, 'id="dashboard-duration-save"') && !str_contains($dashboard, 'id="dashboard-start-time"') && !str_contains($shell, 'id="report-start-time"') && !str_contains($shell, 'id="range-start-time"'), 'Date-specific duration save control or Window Start removal is incomplete.');
ui_check(str_contains($dashboard, 'id="dashboard-greeting"') && str_contains($dashboard, 'id="dashboard-greeting-date"') && str_contains($dashboard, 'id="dashboard-greeting-time"') && str_contains($appScript, 'refreshDashboardGreeting') && str_contains($appScript, 'titleCaseName') && str_contains($appScript, 'hour < 16 ? "Good afternoon"') && str_contains($dashboardStyles, '.dashboard-control-fields .date-control { font-weight: 400; }'), 'Dashboard greeting title case, time-based salutation, regular filter labels, date or live time is missing.');
ui_check(str_contains($appScript, 'function updateDashboardMessageTooltip') && str_contains($appScript, 'message.title = fullText') && str_contains($dashboardStyles, '.dashboard-live-messages .oee-window-summary:focus-visible') && str_contains($dashboardStyles, '.dashboard-live-messages .oee-status-strip:focus-visible'), 'Every truncated dashboard message must expose its complete text on hover and keyboard focus.');
ui_check(substr_count($dashboard, 'dashboard-link-button') >= 4 && str_contains($dashboard, 'data-icon="arrow-right"'), 'Dashboard navigation controls are not styled consistently with export buttons.');
ui_check(str_contains($login, 'data-password-toggle="password"') && str_contains($shell, 'data-password-toggle="current-password"') && str_contains($shell, 'data-password-toggle="new-password"'), 'Login or profile password visibility controls are missing.');
ui_check(!str_contains($dashboard, 'Reporting Window') && str_contains($shell, '>End Time</th>') && str_contains($shell, 'Calculation Time') && str_contains($appScript, '"h Utilization"'), 'Calculation-time terminology is inconsistent in the UI.');
ui_check(str_contains($shell, 'id="chemical-cost-item-chart"') && str_contains($chemicalCostScript, 'function itemCostChart') && str_contains($chemicalCostScript, 'data.item_costs || []'), 'The item-wise chemical cost chart is not connected to report data.');

echo "Portal UI consistency and authentication feedback tests passed\n";
