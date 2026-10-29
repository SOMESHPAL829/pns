<?php
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/oee_settings.php';
require_authentication();
$csrfToken = csrf_token();
$welcomeMessage = is_string($_SESSION['welcome_message'] ?? null) ? $_SESSION['welcome_message'] : '';
unset($_SESSION['welcome_message']);
$oeeLines = oee_known_lines(db());
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>App Portal</title>
  <link rel="stylesheet" href="css/portal.css?v=<?= filemtime(__DIR__ . '/css/portal.css') ?>">
  <link rel="stylesheet" href="css/oee-dashboard.css?v=<?= filemtime(__DIR__ . '/css/oee-dashboard.css') ?>">
</head>
<body>
  <div id="app-shell" class="app-shell">
    <div id="sidebar-backdrop" class="app-sidebar-backdrop" aria-hidden="true"></div>
    <aside class="app-sidebar" aria-label="Main navigation">
      <div class="app-sidebar-header">
        <div class="app-sidebar-brand">
          <div class="app-sidebar-logo" aria-hidden="true">K</div>
          <div class="app-sidebar-brand-text">
            <div class="app-sidebar-brand-title">KARAM OEE</div>
            <div class="app-sidebar-brand-subtitle">PNS</div>
          </div>
        </div>
      </div>
      <nav class="app-sidebar-nav">
        <a class="app-sidebar-link is-active" href="#/dashboard" data-nav="dashboard" title="Dashboard"><span class="app-sidebar-icon" data-icon="squares-2x2"></span><span class="app-sidebar-label">OEE Dashboard</span></a>
        <a class="app-sidebar-link" href="#/oee-range" data-nav="oee-range" title="Date-Range OEE"><span class="app-sidebar-icon" data-icon="calendar-date-range"></span><span class="app-sidebar-label">Date-Range OEE</span></a>
        <a class="app-sidebar-link" href="#/oee-report" data-nav="oee-report" title="Production Report"><span class="app-sidebar-icon" data-icon="presentation-chart-bar"></span><span class="app-sidebar-label">Production Report</span></a>
        <a class="app-sidebar-link" href="#/scada-logs" data-nav="scada-logs" title="SCADA Logs"><span class="app-sidebar-icon" data-icon="cpu-chip"></span><span class="app-sidebar-label">SCADA Logs</span></a>
        <a class="app-sidebar-link" href="#/downtime" data-nav="downtime" title="Downtime Review"><span class="app-sidebar-icon" data-icon="clock"></span><span class="app-sidebar-label">Downtime Review</span></a>
        <div class="app-sidebar-section-label">Workspace</div>
        <a class="app-sidebar-link" href="#/requests" data-nav="requests" title="Records"><span class="app-sidebar-icon" data-icon="clipboard-document-list"></span><span class="app-sidebar-label">Records</span></a>
        <a class="app-sidebar-link" href="#/chemical-consumption" data-nav="chemical-consumption" title="Chemical Consumption"><span class="app-sidebar-icon" data-icon="scale"></span><span class="app-sidebar-label">Chemical Consumption</span></a>
        <a class="app-sidebar-link" href="#/chemical-consumption-report" data-nav="chemical-consumption-report" title="Chemical Consumption Report"><span class="app-sidebar-icon" data-icon="document-chart-bar"></span><span class="app-sidebar-label">Chemical Consumption Report</span></a>
        <a class="app-sidebar-link" href="#/chemical-cost-report" data-nav="chemical-cost-report" title="Chemical Cost Analytics"><span class="app-sidebar-icon" data-icon="currency-rupee"></span><span class="app-sidebar-label">Chemical Cost Analytics</span></a>
        <div class="app-sidebar-section-label">Administration</div>
        <a class="app-sidebar-link" href="#/roles" data-nav="roles" title="Roles & Permissions"><span class="app-sidebar-icon" data-icon="key"></span><span class="app-sidebar-label">Roles & Permissions</span></a>
        <a class="app-sidebar-link" href="#/users" data-nav="users" title="Users"><span class="app-sidebar-icon" data-icon="user-group"></span><span class="app-sidebar-label">Users</span></a>
        <a class="app-sidebar-link" href="#/oee-masters" data-nav="oee-masters" title="OEE Master Data"><span class="app-sidebar-icon" data-icon="cog-6-tooth"></span><span class="app-sidebar-label">OEE Master Data</span></a>
        <a class="app-sidebar-link" href="#/chemical-registration" data-nav="chemical-registration" title="Chemical Registration"><span class="app-sidebar-icon" data-icon="beaker"></span><span class="app-sidebar-label">Chemical Registration</span></a>
        <a class="app-sidebar-link" href="#/breakdown-registration" data-nav="breakdown-registration" title="Downtime Registration"><span class="app-sidebar-icon" data-icon="wrench-screwdriver"></span><span class="app-sidebar-label">Downtime Registration</span></a>
        <a class="app-sidebar-link" href="#/profile" data-nav="profile" title="Profile"><span class="app-sidebar-icon" data-icon="user-circle"></span><span class="app-sidebar-label">My Profile</span></a>
        <form method="post" action="logout.php" data-logout-form>
          <input type="hidden" name="csrf_token" value="<?= escape_html($csrfToken) ?>">
          <button class="app-sidebar-logout" type="submit" title="Sign Out"><span class="app-sidebar-icon" data-icon="arrow-left-start-on-rectangle"></span><span class="app-sidebar-label">Sign Out</span></button>
        </form>
      </nav>
      <div class="app-sidebar-footer">
        <button type="button" id="sidebar-toggle" class="app-sidebar-toggle" aria-label="Toggle sidebar" aria-expanded="true" title="Collapse sidebar"><span data-icon="chevron-left"></span></button>
      </div>
    </aside>

    <div class="app-main">
      <header class="app-topbar">
        <div class="app-topbar-inner">
          <div style="display:flex;align-items:center;gap:12px;min-width:0">
            <button type="button" id="sidebar-mobile-toggle" class="app-sidebar-mobile-toggle" aria-label="Open navigation"><span data-icon="bars-3"></span></button>
            <strong class="app-topbar-title" id="page-title" aria-live="polite">PNS OEE</strong>
          </div>
          <div class="app-topbar-actions">
            <div class="app-header-menu app-appearance-menu" data-header-menu>
              <button type="button" class="app-header-icon-btn" data-header-toggle="appearance" aria-label="Appearance settings" aria-expanded="false" aria-controls="appearance-panel" title="Appearance settings">
                <span data-icon="sun" id="appearance-trigger-icon"></span>
              </button>
              <div id="appearance-panel" class="app-header-panel app-appearance-panel" data-header-panel="appearance" hidden>
                <div class="app-appearance-modes" role="group" aria-label="Theme">
                  <button type="button" class="app-appearance-mode" data-appearance-theme="light" aria-label="Light theme" title="Light theme"><span data-icon="sun"></span></button>
                  <button type="button" class="app-appearance-mode" data-appearance-theme="dark" aria-label="Dark theme" title="Dark theme"><span data-icon="moon"></span></button>
                </div>
                <label class="app-appearance-brightness">
                  <span data-icon="sun-low" aria-hidden="true"></span>
                  <input id="appearance-brightness" type="range" min="80" max="120" step="5" value="100" aria-label="Dashboard brightness">
                  <span data-icon="sun-high" aria-hidden="true"></span>
                  <output id="appearance-brightness-value" for="appearance-brightness">100%</output>
                </label>
              </div>
            </div>
            <div class="app-header-menu" data-header-menu>
              <button type="button" class="app-header-profile-btn" data-header-toggle="profile" aria-label="User menu" aria-expanded="false" aria-controls="header-profile-panel">
                <span class="app-header-avatar">P</span>
                <span class="app-header-profile-text"><span class="app-header-profile-name">Account</span><span class="app-header-profile-user">username</span></span>
              </button>
              <div id="header-profile-panel" class="app-header-panel" data-header-panel="profile" hidden>
                <a class="app-header-profile-link" href="#/profile" data-goto="profile">View Profile</a>
                <form method="post" action="logout.php" data-logout-form>
                  <input type="hidden" name="csrf_token" value="<?= escape_html($csrfToken) ?>">
                  <button class="app-header-logout" type="submit">Sign Out</button>
                </form>
              </div>
            </div>
          </div>
        </div>
      </header>

      <main class="app-content">
        <section class="view" data-view="access-loading"><h1>Loading Permissions…</h1></section>
        <section class="view" data-view="access-denied"><h1>Access Denied</h1><p>Your role does not allow this page or action. Choose an allowed page from the menu, or contact an administrator.</p></section>
        <?php require __DIR__ . '/includes/dashboard.php'; ?>

        <section class="view" data-view="oee-range">
          <div class="page-heading"><div><div class="dash-eyebrow">Period Intelligence</div><h1>Date-Range OEE</h1><p class="page-lead">Combined OEE for the selected dates. Duration applies to each day and line.</p></div></div>
          <section class="data-card">
            <form id="range-form" class="report-filters">
              <label>From Date<input id="range-from-date" type="date" required></label>
              <label>To Date<input id="range-to-date" type="date" required></label>
              <label>Machine<select id="range-machine"><?= oee_machine_options_html($oeeLines, true) ?></select></label>
              <label>Minutes per Day<select id="range-duration"><option value="1440">1440 Min (24 Hours)</option><option value="960">960 Min (16 Hours)</option><option value="720">720 Min (12 Hours)</option><option value="600">600 Min (10 Hours)</option><option value="480">480 Min (8 Hours)</option></select></label>
              <button class="btn" type="submit" id="range-load">Calculate OEE</button>
              <button class="btn ghost" type="button" id="range-export" data-permission-page="oee-range" data-permission-action="export" hidden>Export Excel</button>
            </form>
            <p class="report-summary">Both dates included · Maximum 93 days · Percentages use combined totals, not daily averages.</p>
          </section>
          <div id="range-window" class="oee-window-summary" role="status" aria-live="polite"></div>
          <div id="range-alert" class="oee-alert" role="status" hidden></div>
          <div class="oee-kpis">
            <article class="oee-kpi"><div class="oee-kpi-label">OEE</div><div class="oee-kpi-value" id="range-oee">—</div><div class="oee-kpi-note">Availability × Performance × Quality</div></article>
            <article class="oee-kpi"><div class="oee-kpi-label">Availability</div><div class="oee-kpi-value" id="range-availability">—</div><div class="oee-kpi-note">Total run / total planned time</div></article>
            <article class="oee-kpi"><div class="oee-kpi-label">Performance</div><div class="oee-kpi-value" id="range-performance">—</div><div class="oee-kpi-note">Total ideal output time / total run time</div></article>
            <article class="oee-kpi"><div class="oee-kpi-label">Quality</div><div class="oee-kpi-value" id="range-quality">—</div><div class="oee-kpi-note">Total good FB / total productive FB</div></article>
          </div>
          <div class="oee-grid oee-grid-main">
            <section class="dash-card oee-card"><div class="dash-card-head"><h2>Production Summary</h2></div><div class="oee-production-grid"><div><span class="oee-stat-label">Total FB</span><strong id="range-total-fb">—</strong></div><div><span class="oee-stat-label">Productive FB</span><strong id="range-productive-fb">—</strong></div><div><span class="oee-stat-label">Empty Racks</span><strong id="range-empty-fb">—</strong></div><div><span class="oee-stat-label">Pieces</span><strong id="range-pcs">—</strong></div><div><span class="oee-stat-label">Surface Area</span><strong id="range-area">—</strong></div><div><span class="oee-stat-label">Days Selected</span><strong id="range-days">—</strong></div></div></section>
            <section class="dash-card oee-card"><div class="dash-card-head"><h2>Time Accounting</h2></div><div class="oee-loss-list"><div><span>Calculation Time</span><strong id="range-calendar">—</strong></div><div><span>Planned Stops</span><strong id="range-planned-stop">—</strong></div><div><span>Planned Production Time</span><strong id="range-planned">—</strong></div><div><span>Unplanned Downtime</span><strong id="range-unplanned">—</strong></div><div><span>Run Time</span><strong id="range-run">—</strong></div><div><span id="range-utilization-label">Utilization</span><strong id="range-utilization">—</strong></div></div></section>
          </div>
          <section class="data-card"><div class="dash-card-head"><h2>Line-Wise OEE · Selected Period</h2></div><div class="data-scroll"><table class="data-table compact-table"><thead><tr><th>Line</th><th>Productive FB</th><th>Run (Min)</th><th>Ideal Output (Min)</th><th>Availability</th><th>Performance</th><th>Quality</th><th>OEE</th><th><span id="range-table-utilization-heading">Utilization</span></th></tr></thead><tbody id="range-lines-body"></tbody></table></div></section>
          <section class="data-card"><div class="dash-card-head"><h2>Daily Line Breakdown</h2></div><div class="data-scroll"><table class="data-table compact-table"><thead><tr><th>Reporting Date</th><th>Line</th><th>End Time</th><th>Productive FB</th><th>Run (Min)</th><th>OEE</th></tr></thead><tbody id="range-days-body"></tbody></table></div></section>
        </section>

        <section class="view" data-view="oee-report">
          <div class="page-heading oee-page-heading"><div><div class="dash-eyebrow">Traceable Production Data</div><h1>Production Report</h1><p class="page-lead">Line-wise flight-bar data with historical date export.</p></div><div class="page-heading-actions"><label class="date-control">Report Date <input id="report-date" type="date"></label><label class="date-control">Calculation Duration <select id="report-duration"><option value="1440">1440 Min (24 Hours)</option><option value="960">960 Min (16 Hours)</option><option value="720">720 Min (12 Hours)</option><option value="600">600 Min (10 Hours)</option><option value="480">480 Min (8 Hours)</option></select></label><button class="btn" id="report-load" type="button">Load Report</button><button class="btn ghost" id="report-export" data-permission-page="oee-report" data-permission-action="export" hidden type="button">Export Excel</button></div></div>
          <div id="report-window" class="oee-window-summary" role="status"></div>
          <div id="oee-report-alert" class="oee-alert" role="status" hidden></div>
          <section class="data-card oee-report-card">
            <div class="report-filters">
              <label>Line / Machine
                <select id="report-machine-filter"><?= oee_machine_options_html($oeeLines, true, 'All Lines', ' · OEE Feed') ?></select>
              </label>
              <label>Search
                <input id="report-search" type="search" placeholder="Sequence, Part, Colour">
              </label>
              <label>Grade
                <select id="report-grade-filter"><option value="">All Grades</option></select>
              </label>
              <label>Colour
                <select id="report-colour-filter"><option value="">All Colours</option></select>
              </label>
              <label>State
                <select id="report-state-filter">
                  <option value="">All States</option>
                  <option value="productive">Productive</option>
                  <option value="empty">Empty</option>
                </select>
              </label>
            </div>

            <div class="oee-kpis report-kpis">
              <article class="oee-kpi">
                <div class="oee-kpi-label">OEE</div>
                <div class="oee-kpi-value" id="report-oee-value">—</div>
              </article>
              <article class="oee-kpi">
                <div class="oee-kpi-label">Availability</div>
                <div class="oee-kpi-value" id="report-availability-value">—</div>
              </article>
              <article class="oee-kpi">
                <div class="oee-kpi-label">Performance</div>
                <div class="oee-kpi-value" id="report-performance-value">—</div>
              </article>
              <article class="oee-kpi">
                <div class="oee-kpi-label">Quality</div>
                <div class="oee-kpi-value" id="report-quality-value">—</div>
              </article>
            </div>

            <div class="report-summary" id="report-summary"></div>

            <div class="data-scroll">
              <table class="data-table compact-table" data-export-title="Line Performance Metrics">
                <thead>
                  <tr>
                    <th>Line</th><th>OEE</th><th>Availability</th>
                    <th>Performance</th><th>Quality</th><th><span id="report-utilization-heading">Utilization</span></th>
                  </tr>
                </thead>
                <tbody id="report-line-metrics-body"></tbody>
              </table>
            </div>

            <div class="data-scroll">
              <table class="data-table oee-report-table" data-export-title="Flight Bar Details">
                <thead>
                  <tr>
                    <th>Line</th><th>SI</th><th>Sequence</th><th>Part</th>
                    <th>Grade</th><th>Colour</th><th>Loading</th><th>Unloading</th>
                    <th>PCS</th><th>Total SA</th><th>State</th>
                  </tr>
                </thead>
                <tbody id="oee-report-body"></tbody>
              </table>
            </div>
          </section>
        </section>

        <section class="view" data-view="downtime">
          <div class="page-heading"><div><div class="dash-eyebrow">Loss Accounting</div><h1>Downtime Review</h1><p class="page-lead">Tag planned and unplanned stops so OEE can calculate the loss correctly.</p></div><div class="page-heading-actions"><button class="btn" id="downtime-refresh" type="button">Refresh</button></div></div>
          <div id="downtime-alert" class="oee-alert" role="status" hidden></div>
          <section class="data-card downtime-card"><div class="report-filters"><label>Machine<select id="downtime-machine"><?= oee_machine_options_html($oeeLines) ?></select></label><label>Date<input id="downtime-date" type="date"></label></div>
            <form id="downtime-form" class="downtime-form"><label>Start<input id="downtime-start" type="datetime-local" required></label><label>End<input id="downtime-end" type="datetime-local" required></label><label>Type<select id="downtime-type"><option value="planned">Planned</option><option value="unplanned" selected>Unplanned</option></select></label><label>Reason<select id="downtime-reason" required><option value="">Select a reason</option></select></label><label class="form-span">Notes<input id="downtime-notes" maxlength="500"></label><button class="btn" type="submit">Save Downtime</button></form>
            <div class="data-scroll"><table class="data-table" data-export-title="Downtime Events"><thead><tr><th>Start Time</th><th>End Time</th><th>Type</th><th>Reason</th><th>Source</th><th>Status</th><th>Notes</th><th>Action</th></tr></thead><tbody id="downtime-body"></tbody></table></div></section>
        </section>

        <section class="view" data-view="scada-logs">
          <div class="page-heading"><div><div class="dash-eyebrow">Operations</div><h1>SCADA Logs</h1><p class="page-lead">Import batches and SCADA synchronization events received by the local OEE app.</p></div><div class="page-heading-actions"><span class="sync-cadence">Expected Sync: Every 10 Minutes</span><button class="btn" id="logs-refresh" type="button">Refresh Logs</button></div></div>
          <div id="oee-logs-alert" class="oee-alert" role="status" hidden></div>
          <div class="oee-log-grid"><section class="data-card"><div class="dash-card-head"><h2>Source Status</h2></div><div id="scada-sync-body" class="oee-sync-list"></div></section><section class="data-card"><div class="dash-card-head"><h2>Import Event Log</h2></div><div class="data-scroll"><table class="data-table compact-table"><thead><tr><th>Received</th><th>Source</th><th>Batch</th><th>Report Date</th><th>Rows</th><th>Status</th><th>Error</th></tr></thead><tbody id="scada-logs-body"></tbody></table></div></section></div>
        </section>

        <section class="view" data-view="requests">
          <div class="page-heading"><div><div class="dash-eyebrow">Workspace</div><h1>Records</h1><p class="page-lead">Search, review, and manage your records.</p></div><div class="page-heading-actions"><button class="btn" type="button" data-modal-open="record-create-form" data-modal-mode="create" data-permission-page="records" data-permission-action="create">+ New Record</button></div></div>
          <div class="page-toolbar">
            <form class="page-toolbar-left" id="record-filter-form">
              <div class="page-search"><input id="records-search" type="search" placeholder="Search Number or Title"></div>
              <select id="record-type-filter"><option value="">All Types</option><option>Ticket</option><option>Change</option><option>Asset</option><option>Document</option></select>
              <select id="record-status-filter"><option value="">All Statuses</option><option>Open</option><option value="In review">In Review</option><option>Closed</option></select>
              <button class="btn" type="submit">Filter</button>
            </form>
          </div>
          <div class="data-card"><div class="dash-card-head"><h2>Record List</h2></div><div class="data-scroll"><table class="data-table"><thead><tr><th>Number</th><th>Title</th><th>Owner</th><th>Type</th><th>Status</th><th>Created</th><th>Updated</th><th class="r">Actions</th></tr></thead><tbody id="records-body"></tbody></table></div></div>
        </section>

        <section class="view" data-view="create">
          <div class="page-heading"><div><nav class="page-breadcrumb"><a href="#/requests" data-goto="requests">Records</a> / New</nav><h1>New Record</h1><p class="page-lead">Enter the required information to create a record.</p></div><div class="page-heading-actions"><button class="btn ghost" type="button" data-goto="requests">Cancel</button></div></div>
          <form id="record-create-form" method="post" action="app.php">
            <div class="form-section"><div class="form-section-head">Record Details</div><div class="form-section-body">
              <div><label class="form-label" for="record-type">Type <span class="req">*</span></label><select class="form-select" id="record-type" name="record_type" required><option>Ticket</option><option>Change</option><option>Asset</option><option>Document</option></select></div>
              <div><label class="form-label" for="record-organization">Organization <span class="req">*</span></label><select class="form-select" id="record-organization" name="organization" required><option>HQ</option><option>Plant A</option><option>Plant B</option></select></div>
              <div><label class="form-label" for="record-category">Category</label><select class="form-select" id="record-category" name="category"><option>General</option><option>IT</option><option>Operations</option><option>HR</option></select></div>
              <div><label class="form-label" for="record-priority">Priority</label><select class="form-select" id="record-priority" name="priority"><option>Normal</option><option>High</option><option>Low</option></select></div>
              <div class="form-span"><label class="form-label" for="record-title">Title <span class="req">*</span></label><input class="form-input" id="record-title" name="title" maxlength="200" required></div>
              <div class="form-span"><label class="form-label" for="record-description">Description</label><textarea class="form-textarea" id="record-description" name="description" maxlength="10000"></textarea></div>
            </div></div>
            <div class="form-footer"><button class="btn" type="submit">Create Record</button></div>
          </form>
        </section>

        <section class="view" data-view="detail">
          <div class="page-heading"><div><nav class="page-breadcrumb"><a href="#/requests" data-goto="requests">Records</a> / <span id="detail-number">Record</span></nav><h1>Record Details</h1><p class="page-lead">Review the selected record and update permitted fields.</p></div><div class="page-heading-actions"><button class="btn ghost" id="record-edit-toggle" data-permission-page="records" data-permission-action="update" type="button">Edit</button></div></div>
          <div class="detail-layout"><div class="form-section"><div class="form-section-head">Record Details</div><dl class="detail-dl">
            <div><dt>Type</dt><dd id="detail-type">—</dd></div><div><dt>Organization</dt><dd id="detail-organization">—</dd></div><div><dt>Category</dt><dd id="detail-category">—</dd></div><div><dt>Owner</dt><dd id="detail-owner">—</dd></div><div><dt>Status</dt><dd id="detail-status">—</dd></div><div class="form-span"><dt>Title</dt><dd id="detail-title">—</dd></div><div class="form-span"><dt>Description</dt><dd id="detail-description">—</dd></div>
          </dl><form id="record-edit-form" class="form-stack" hidden method="post" action="app.php"><div><label class="form-label" for="edit-record-title">Title</label><input class="form-input" id="edit-record-title" name="title" maxlength="200" required></div><div><label class="form-label" for="edit-record-description">Description</label><textarea class="form-textarea" id="edit-record-description" name="description" maxlength="10000"></textarea></div><button class="btn" type="submit">Save Changes</button></form></div></div>
        </section>

        <section class="view" data-view="users">
          <div class="page-heading"><div><div class="dash-eyebrow">Administration</div><h1>Users</h1><p class="page-lead">Manage account access and role assignments.</p></div><div class="page-heading-actions"><button class="btn" type="button" data-modal-open="user-create-form" data-modal-mode="create" data-permission-page="users" data-permission-action="create">+ Add User</button></div></div>
          <div class="page-toolbar"><form class="page-toolbar-left" id="user-search-form"><div class="page-search"><input id="users-search" type="search" placeholder="Search Users"></div><button class="btn ghost sm" type="submit">Search</button></form></div>
          <div class="data-card"><div class="dash-card-head"><h2>User Accounts</h2></div><div class="data-scroll"><table class="data-table"><thead><tr><th>ID</th><th>Username</th><th>Full Name</th><th>Email</th><th>Role</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead><tbody id="users-body"></tbody></table></div></div>
          <form id="user-role-form" class="form-stack" hidden><input id="user-role-user-id" type="hidden"><div><label class="form-label" for="user-role-user-name">User</label><input class="form-input" id="user-role-user-name" readonly></div><div><label class="form-label" for="user-role-select">Access Role</label><select class="form-select" id="user-role-select" required></select></div><button class="btn" type="submit">Save Role</button></form>
        </section>

        <section class="view" data-view="user-create">
          <div class="page-heading"><div><nav class="page-breadcrumb"><a href="#/users" data-goto="users">Users</a> / New</nav><h1>Add User</h1><p class="page-lead">Create an account and assign an access role.</p></div><div class="page-heading-actions"><button class="btn ghost" type="button" data-goto="users">Cancel</button><button class="btn" type="submit" form="user-create-form">Create</button></div></div>
          <div class="form-card"><h2 class="form-card-title">Account Details</h2><form id="user-create-form" class="form-stack" method="post" action="app.php">
            <div class="form-grid-2"><div><label class="form-label" for="new-user-email">Email <span class="req">*</span></label><input class="form-input" id="new-user-email" name="email" type="email" required></div><div><label class="form-label" for="new-user-username">Username <span class="req">*</span></label><input class="form-input" id="new-user-username" name="username" minlength="3" maxlength="80" required></div></div>
            <div><label class="form-label" for="new-user-name">Full Name <span class="req">*</span></label><input class="form-input" id="new-user-name" name="full_name" maxlength="160" required></div><div><label class="form-label" for="new-user-password">Temporary Password <span class="req">*</span></label><input class="form-input" id="new-user-password" name="password" type="password" minlength="12" required></div><p class="form-note">The user can change this password in their profile.</p>
            <div><label class="form-label" for="new-user-role">Access Role</label><select class="form-select" id="new-user-role" name="role_id" required></select></div>
          </form></div>
        </section>

        <section class="view" data-view="roles">
          <div class="page-heading"><div><div class="dash-eyebrow">Administration</div><h1>Roles & Permissions</h1><p class="page-lead">Choose which pages and actions each role can use. Assign roles on the Users page.</p></div><button class="btn ghost" id="roles-refresh" type="button">Refresh</button></div>
          <div id="roles-alert" class="oee-alert" role="status" hidden></div>
          <section class="data-card"><div class="dash-card-head"><h2>Registered Roles</h2></div><div class="data-scroll"><table class="data-table"><thead><tr><th>Role</th><th>Type</th><th>Assigned Users</th><th>Actions</th></tr></thead><tbody id="roles-body"><tr><td colspan="4">Loading Roles…</td></tr></tbody></table></div></section>
          <form id="role-form" class="data-card role-editor"><div class="dash-card-head"><h2>Role Permission Editor</h2><button id="role-new" type="button" class="btn ghost sm">New Role</button></div><input id="role-id" type="hidden"><div class="master-form"><label for="role-name">Role Name<input id="role-name" maxlength="80" required placeholder="Example: OEE Viewer"></label><button class="btn" id="role-save" type="submit">Create Role</button></div><p class="form-note role-help">View is required for other actions, including Export. Dashes mean the page has no such operation. Role administration is reserved for protected administrators. Existing record ownership restrictions still apply.</p><div class="data-scroll"><table class="data-table permission-table"><thead><tr><th>Page</th><th>View</th><th>Create</th><th>Update</th><th>Delete</th><th>Export</th></tr></thead><tbody id="role-permissions-body"></tbody></table></div></form>
        </section>

        <section class="view" data-view="profile">
          <div class="page-heading"><div><div class="dash-eyebrow">Account</div><h1>My Profile</h1><p class="page-lead">Review and update your account details securely.</p></div></div>
          <div class="profile-settings-grid">
            <section class="form-card profile-settings-card" aria-labelledby="profile-details-heading"><h2 class="form-card-title" id="profile-details-heading">Profile Details</h2><p class="form-note">Update the name shown across the portal. Username and email are managed by an administrator.</p><form id="profile-form" class="form-stack" method="post" action="app.php">
              <div><label class="form-label" for="profile-username">Username</label><input class="form-input" id="profile-username" readonly></div><div><label class="form-label" for="profile-name">Full Name</label><input class="form-input" id="profile-name" name="full_name" maxlength="160" required></div><div><label class="form-label" for="profile-email">Email</label><input class="form-input" id="profile-email" type="email" readonly><p class="form-note">Contact an administrator to change your account email.</p></div><div class="form-footer"><button class="btn" form="profile-form" type="submit">Save Profile</button></div>
            </form></section>
            <section class="form-card profile-settings-card" aria-labelledby="change-password-heading"><h2 class="form-card-title" id="change-password-heading">Change Password</h2><p class="form-note">Use at least 12 characters and keep your password private.</p><form id="password-form" class="form-stack" method="post" action="app.php"><div><label class="form-label" for="current-password">Current Password</label><div class="password-input-wrap"><input class="form-input" id="current-password" name="current_password" type="password" autocomplete="current-password" required><button class="password-toggle" type="button" data-password-toggle="current-password" aria-label="Show current password" aria-pressed="false" title="Show current password"><span data-icon="eye"></span></button></div></div><div><label class="form-label" for="new-password">New Password</label><div class="password-input-wrap"><input class="form-input" id="new-password" name="new_password" type="password" minlength="12" autocomplete="new-password" required><button class="password-toggle" type="button" data-password-toggle="new-password" aria-label="Show new password" aria-pressed="false" title="Show new password"><span data-icon="eye"></span></button></div></div><div class="form-footer"><button class="btn" type="submit">Update Password</button></div></form></section>
          </div>
        </section>

        <section class="view" data-view="chemical-registration">
          <div class="page-heading"><div><div class="dash-eyebrow">Administration</div><h1>Chemical Registration</h1><p class="page-lead">Register chemical items and maintain effective-dated rates in INR.</p></div><div class="page-heading-actions"><button class="btn ghost" id="chemicals-refresh" type="button">Refresh</button></div></div>
          <div id="chemicals-alert" class="oee-alert" role="status" hidden></div>
          <div class="chemical-registration-grid">
            <section class="data-card" aria-labelledby="chemical-item-heading">
              <div class="dash-card-head"><h2 id="chemical-item-heading">Item Registration</h2></div>
              <form id="chemical-item-form" class="master-form">
                <input id="chemical-item-id" type="hidden">
                <label>Item Code<input id="chemical-item-code" maxlength="80" placeholder="CHEM-001" required></label>
                <label class="chemical-description-field">Description<input id="chemical-description" maxlength="500" placeholder="Chemical Description" required></label>
                <label>Status<select id="chemical-status" required><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
                <label>UOM<input id="chemical-uom" maxlength="40" list="chemical-uom-options" placeholder="KG" required><datalist id="chemical-uom-options"><option value="KG"><option value="LTR"><option value="ML"><option value="GM"><option value="NOS"></datalist></label>
                <button class="btn" id="chemical-item-save" type="submit">Save Item</button>
                <button class="btn ghost" id="chemical-item-cancel" type="button" hidden>Cancel Edit</button>
              </form>
              <div class="data-scroll"><table class="data-table chemical-items-table"><thead><tr><th>Item Code</th><th>Description</th><th>Status</th><th>UOM</th><th>Current Rate (INR)</th><th>Action</th></tr></thead><tbody id="chemical-items-body"><tr><td colspan="6">Loading Chemical Items…</td></tr></tbody></table></div>
            </section>

            <section class="data-card" aria-labelledby="chemical-rate-heading">
              <div class="dash-card-head"><h2 id="chemical-rate-heading">INR Rate Registration</h2></div>
              <form id="chemical-rate-form" class="master-form">
                <input id="chemical-rate-id" type="hidden">
                <label>Chemical Item<select id="chemical-rate-item" required><option value="">Select an Item</option></select></label>
                <label>Rate (INR)<input id="chemical-rate-inr" type="number" min="0.0001" max="9999999999.9999" step="0.0001" required></label>
                <label>Effective From<input id="chemical-rate-from" type="date" required></label>
                <label>Effective To<input id="chemical-rate-to" type="date"></label>
                <button class="btn" id="chemical-rate-save" type="submit">Save Rate</button>
                <button class="btn ghost" id="chemical-rate-cancel" type="button" hidden>Cancel Edit</button>
              </form>
              <p class="form-note chemical-rate-help">Effective dates are inclusive. Date ranges for the same item cannot overlap.</p>
              <div class="data-scroll"><table class="data-table chemical-rates-table"><thead><tr><th>Item Code</th><th>Rate (INR)</th><th>Effective From</th><th>Effective To</th><th>Period Status</th><th>Action</th></tr></thead><tbody id="chemical-rates-body"><tr><td colspan="6">Loading Chemical Rates…</td></tr></tbody></table></div>
            </section>
          </div>
        </section>

        <section class="view" data-view="breakdown-registration">
          <div class="page-heading"><div><div class="dash-eyebrow">Administration</div><h1>Downtime Registration</h1><p class="page-lead">Maintain planned and unplanned loss reasons used in downtime review.</p></div><div class="page-heading-actions"><button class="btn ghost" id="breakdowns-refresh" type="button">Refresh</button></div></div>
          <div id="breakdowns-alert" class="oee-alert" role="status" hidden></div>
          <section class="data-card breakdown-registration-card" aria-labelledby="breakdown-registration-heading">
            <div class="dash-card-head"><h2 id="breakdown-registration-heading">Register Downtime Reason</h2></div>
            <form id="breakdown-form" class="master-form">
              <input id="breakdown-id" type="hidden">
              <label for="breakdown-loss-type">Loss Type<select id="breakdown-loss-type" required><option value="planned">Planned</option><option value="unplanned">Unplanned</option></select></label>
              <label class="breakdown-name-field" for="breakdown-loss-name">Loss Name<input id="breakdown-loss-name" maxlength="120" required></label>
              <label class="breakdown-active-field" for="breakdown-is-active"><input id="breakdown-is-active" type="checkbox" checked><span>Active</span></label>
              <button id="breakdown-save" class="btn" type="submit">Save</button>
              <button id="breakdown-cancel" class="btn ghost" type="button" hidden>Cancel Edit</button>
            </form>
            <div class="data-scroll"><table class="data-table" data-export-title="Registered Downtime Reasons"><thead><tr><th>Type</th><th>Loss Name</th><th>Status</th><th>Actions</th></tr></thead><tbody id="breakdowns-body"><tr><td colspan="4">Loading downtime reasons…</td></tr></tbody></table></div>
          </section>
        </section>

        <section class="view" data-view="chemical-consumption">
          <div class="page-heading"><div><div class="dash-eyebrow">Workspace</div><h1>Chemical Consumption</h1><p class="page-lead">Record chemical usage by date, shift, operator, machine, and process.</p></div><div class="page-heading-actions"><button class="btn ghost" id="chemical-consumption-refresh" type="button">Refresh</button></div></div>
          <div id="chemical-consumption-alert" class="oee-alert" role="status" hidden></div>
          <section class="data-card chemical-consumption-card" aria-labelledby="chemical-consumption-entry-heading">
            <div class="dash-card-head"><h2 id="chemical-consumption-entry-heading">Consumption Entries</h2></div>
            <form id="chemical-consumption-form" class="chemical-consumption-form">
              <input id="chemical-consumption-id" type="hidden">
              <label>Date<input id="chemical-consumption-date" type="date" required></label>
              <label>Shift<input id="chemical-consumption-shift" maxlength="40" list="chemical-shift-options" placeholder="Shift A" required><datalist id="chemical-shift-options"><option value="Shift A"><option value="Shift B"><option value="Shift C"><option value="General Shift"></datalist></label>
              <label>Operator Name<input id="chemical-consumption-operator" maxlength="120" placeholder="Operator Name" required></label>
              <label>Machine Code<input id="chemical-consumption-machine" maxlength="80" list="chemical-machine-options" placeholder="APP-06" required><datalist id="chemical-machine-options"><?= oee_machine_options_html($oeeLines) ?></datalist></label>
              <label class="chemical-consumption-wide">Process Description<input id="chemical-consumption-process" maxlength="500" placeholder="Process Description" required></label>
              <label>Chemical Name<select id="chemical-consumption-item" required><option value="">Select a Chemical</option></select></label>
              <label>Consume Quantity<span class="chemical-quantity-control"><input id="chemical-consumption-quantity" type="number" min="0.0001" max="9999999999.9999" step="0.0001" required><select id="chemical-consumption-uom" class="chemical-quantity-uom" aria-label="Unit of measure" required><option value="">UOM</option></select></span></label>
              <label class="chemical-consumption-wide">Used For<select id="chemical-consumption-used-for" required><option value="">Select a Usage</option><option value="Fresh Makeup">Fresh Makeup</option><option value="Top-Up">Top-Up</option></select></label>
              <label>Recommended By<input id="chemical-consumption-recommended-by" maxlength="120" placeholder="Recommender Name" required></label>
              <label class="chemical-consumption-remarks">Remarks<textarea id="chemical-consumption-remarks" maxlength="1000" rows="2" placeholder="Optional Remarks"></textarea></label>
              <div class="chemical-consumption-actions"><button class="btn" id="chemical-consumption-save" type="submit">Save Entry</button><button class="btn ghost" id="chemical-consumption-cancel" type="button" hidden>Cancel Edit</button></div>
            </form>
            <div class="data-scroll"><table class="data-table chemical-consumption-table"><thead><tr><th>Date</th><th>Shift</th><th>Operator Name</th><th>Machine Code</th><th>Process Description</th><th>Chemical Name</th><th>Consume Quantity</th><th>Used For</th><th>Recommended By</th><th>Remarks</th><th>Action</th></tr></thead><tbody id="chemical-consumption-body"><tr><td colspan="11">Loading Chemical Consumption Entries…</td></tr></tbody></table></div>
          </section>
        </section>

        <section class="view" data-view="chemical-consumption-report">
          <div class="page-heading chemical-report-page-heading"><div><div class="dash-eyebrow">Workspace</div><h1>Chemical Consumption Report</h1><p class="page-lead">Review and print chemical consumption entries for a selected date range and machine.</p></div><div class="page-heading-actions"><button class="btn ghost" id="chemical-report-print" type="button">Print Report</button></div></div>
          <div id="chemical-report-alert" class="oee-alert" role="status" hidden></div>
          <section class="data-card chemical-report-filter-card" aria-labelledby="chemical-report-filter-heading">
            <div class="dash-card-head"><h2 id="chemical-report-filter-heading">Report Filters</h2></div>
            <form id="chemical-report-filter-form" class="master-form">
              <label>From Date<input id="chemical-report-from" type="date" required></label>
              <label>To Date<input id="chemical-report-to" type="date" required></label>
              <label>Machine<select id="chemical-report-machine"><option value="ALL">All Machines</option></select></label>
              <button class="btn" id="chemical-report-run" type="submit">Generate Report</button>
            </form>
          </section>
          <section class="data-card chemical-consumption-report-card" id="chemical-consumption-report-print" aria-label="Chemical Consumption Results">
            <dl class="chemical-report-meta">
              <div><dt>From Date</dt><dd id="chemical-report-from-display">—</dd></div>
              <div><dt>To Date</dt><dd id="chemical-report-to-display">—</dd></div>
              <div><dt>Machine</dt><dd id="chemical-report-machine-display">All Machines</dd></div>
            </dl>
            <div class="data-scroll"><table class="data-table chemical-report-table" data-export-title="Consumption Results"><thead><tr><th>Date</th><th>Shift</th><th>Machine</th><th>Name of Operator</th><th>Process Description</th><th>Chemical Name &amp; Code</th><th>Consumed Qty.</th><th>Used For<br><span>(Fresh Makeup / Top-Up)</span></th><th>Recommended By<br><span>(QC Manager / Production Manager)</span></th><th>Remarks</th></tr></thead><tbody id="chemical-report-body"><tr><td colspan="10">Choose report filters to load entries.</td></tr></tbody></table></div>
          </section>
        </section>

        <section class="view" data-view="chemical-cost-report">
          <div class="page-heading chemical-cost-report-page-heading">
            <div>
              <div class="dash-eyebrow">Workspace / Cost Intelligence</div>
              <h1>Chemical Cost Dashboard</h1>
              <p class="page-lead">Monitor chemical expenses, surface-area productivity and cost efficiency across periods.</p>
            </div>
            <div class="page-heading-actions">
              <span class="chemical-cost-badge" id="chemical-cost-report-scope">● Chemical cost monitoring</span>
              <button class="btn ghost" id="chemical-cost-report-export" type="button">Export CSV</button>
              <button class="btn ghost" id="chemical-cost-report-print" type="button">Print Report</button>
            </div>
          </div>
          <div id="chemical-cost-report-alert" class="oee-alert" role="status" hidden></div>
          <section class="data-card chemical-cost-report-filter-card" aria-labelledby="chemical-cost-report-filter-heading">
            <div class="dash-card-head"><h2 id="chemical-cost-report-filter-heading">Report Filters</h2><span class="chemical-cost-mini">Source: live consumption + OEE surface area</span></div>
            <form id="chemical-cost-report-filter-form" class="master-form chemical-cost-filters">
              <label>From Date<input id="chemical-cost-report-from" type="date" required></label>
              <label>To Date<input id="chemical-cost-report-to" type="date" required></label>
              <label>Machine<select id="chemical-cost-report-machine"><option value="ALL">All Machines</option></select></label>
              <label>Analysis View<select id="chemical-cost-report-type"><option value="weekly">Week on Week</option><option value="monthly">Month on Month</option><option value="consolidated">Consolidated</option></select></label>
              <button class="btn" id="chemical-cost-report-run" type="submit">Generate Dashboard</button>
            </form>
          </section>
          <section class="dash-kpis chemical-cost-kpis" id="chemical-cost-report-kpis" aria-label="Cost summary"></section>
          <section class="chemical-cost-chart-grid">
            <article class="data-card chemical-cost-chart-card">
              <div class="dash-card-head">
                <div>
                  <h2>Chemical Cost per dm² · Trend</h2>
                  <p class="chemical-cost-caption" id="chemical-cost-trend-caption">Weekly unit-cost variation</p>
                </div>
                <div class="chemical-cost-tabs" role="tablist" aria-label="Trend view">
                  <button type="button" data-cost-tab="weekly" class="selected">WoW</button>
                  <button type="button" data-cost-tab="monthly">MoM</button>
                </div>
              </div>
              <div class="chemical-cost-chartarea" id="chemical-cost-trend" role="img" aria-label="Chemical cost per dm2 trend"></div>
            </article>
            <article class="data-card chemical-cost-chart-card">
              <div class="dash-card-head">
                <div>
                  <h2>Chemical Cost · Period Comparison</h2>
                  <p class="chemical-cost-caption">₹ expenditure across selected periods</p>
                </div>
                <span class="chemical-cost-mini">INR</span>
              </div>
              <div class="chemical-cost-chartarea" id="chemical-cost-cost-chart" role="img" aria-label="Chemical cost by period"></div>
            </article>
          </section>
          <section class="chemical-cost-chart-grid equal">
            <article class="data-card chemical-cost-chart-card">
              <div class="dash-card-head">
                <div>
                  <h2>Surface Area Processed</h2>
                  <p class="chemical-cost-caption">Total processed surface area, dm²</p>
                </div>
                <span class="chemical-cost-mini">dm²</span>
              </div>
              <div class="chemical-cost-chartarea" id="chemical-cost-area-chart" role="img" aria-label="Surface area by period"></div>
            </article>
            <article class="data-card chemical-cost-chart-card">
              <div class="dash-card-head">
                <div>
                  <h2>Cost vs Surface Area</h2>
                  <p class="chemical-cost-caption">Trend comparison (indexed to first period = 100)</p>
                </div>
                <div class="chemical-cost-legend"><span><i class="chemical-cost-dot cost"></i>Cost</span><span><i class="chemical-cost-dot area"></i>Area</span></div>
              </div>
              <div class="chemical-cost-chartarea" id="chemical-cost-indexed-chart" role="img" aria-label="Indexed cost versus area"></div>
            </article>
          </section>
          <section class="data-card chemical-cost-report-card" aria-labelledby="chemical-cost-report-table-heading">
            <div class="dash-card-head chemical-cost-table-head">
              <div>
                <h2 id="chemical-cost-report-table-heading">Detailed Chemical Cost Report</h2>
                <p class="chemical-cost-caption" id="chemical-cost-table-caption">Period-wise financial and surface-area performance</p>
              </div>
              <dl class="chemical-cost-report-meta chemical-cost-report-meta-inline">
                <div><dt>From</dt><dd id="chemical-cost-report-from-display">—</dd></div>
                <div><dt>To</dt><dd id="chemical-cost-report-to-display">—</dd></div>
                <div><dt>Machine</dt><dd id="chemical-cost-report-machine-display">All Machines</dd></div>
                <div><dt>View</dt><dd id="chemical-cost-report-type-display">Week on Week</dd></div>
              </dl>
            </div>
            <div class="data-scroll"><table class="data-table chemical-cost-report-table" id="chemical-cost-report-table"><thead><tr><th>Machine</th><th>Report Period</th><th class="r">Total Surface Area (dm²)</th><th class="r">Total Chemical Cost (₹)</th><th class="r">Chemical Cost per dm² (₹)</th><th class="r">Vs Previous</th><th>Performance</th></tr></thead><tbody id="chemical-cost-report-body"><tr><td colspan="7">Choose report filters to load the cost summary.</td></tr></tbody></table></div>
            <div class="chemical-cost-report-footer">
              <span id="chemical-cost-report-row-count">Showing 0 rows</span>
              <p class="chemical-cost-report-formula"><strong>Formula:</strong> Chemical Cost per dm² = Total Chemical Cost ÷ Total Surface Area</p>
            </div>
          </section>
          <div class="chemical-cost-tip" id="chemical-cost-tip" hidden></div>
        </section>

        <section class="view" data-view="oee-masters">
          <div class="page-heading"><div><div class="dash-eyebrow">Administration</div><h1>OEE Master Data</h1><p class="page-lead">Maintain plant gap thresholds and cycle times used by the OEE calculation.</p></div><div class="page-heading-actions"><button class="btn" id="masters-refresh" type="button">Refresh</button></div></div>
          <div id="masters-alert" class="oee-alert" role="status" hidden></div>
          <section class="data-card threshold-card" aria-labelledby="threshold-heading">
            <div class="dash-card-head"><h2 id="threshold-heading">Plant Gap Threshold</h2></div>
            <p id="threshold-help" class="form-note threshold-help">A loading gap must be greater than this limit to propose downtime. Downtime = gap minus the arriving FB cycle time (minimum zero). Empty-rack detection is separate. Changes apply to historical reports on refresh; tagged events stay unchanged.</p>
            <form id="threshold-form" class="master-form" aria-describedby="threshold-help">
              <label for="threshold-line">Plant / Machine<select id="threshold-line" disabled><?= oee_machine_options_html($oeeLines) ?></select></label>
              <label for="threshold-minutes">Gap Threshold (Minutes)<input id="threshold-minutes" type="number" min="0.001" max="1440" step="0.001" required disabled></label>
              <button id="threshold-save" class="btn" type="submit" disabled>Save Threshold</button>
            </form>
            <div class="data-scroll"><table class="data-table"><thead><tr><th>Plant / Machine</th><th>Gap Threshold (Minutes)</th><th>Action</th></tr></thead><tbody id="thresholds-body"><tr><td colspan="3">Loading Plant Thresholds…</td></tr></tbody></table></div>
          </section>
          <div class="oee-master-grid oee-master-grid-single">
            <section class="data-card"><div class="dash-card-head"><h2>Cycle-Time Registration</h2></div><form id="cycle-form" class="master-form"><input id="cycle-id" type="hidden"><label>Machine<select id="cycle-line"><?= oee_machine_options_html($oeeLines) ?></select></label><label>Colour<input id="cycle-colour" maxlength="120" placeholder="BLACK-1" required></label><label>Minutes / FB<input id="cycle-minutes" type="number" min="0.001" step="0.001" required></label><label>Effective From<input id="cycle-from" type="date" required></label><label>Effective To<input id="cycle-to" type="date"></label><button class="btn" type="submit">Save Cycle Time</button></form><div class="data-scroll"><table class="data-table"><thead><tr><th>Machine</th><th>Colour</th><th>Minutes / FB</th><th>From</th><th>To</th><th>Action</th></tr></thead><tbody id="cycles-body"></tbody></table></div></section>
          </div>
        </section>
      </main>
    </div>
  </div>
  <div id="toast-stack" class="app-toast-stack" aria-live="polite"></div>
  <div id="page-transition" class="page-transition" role="status" aria-live="polite" hidden><div class="page-transition-dialog"><span class="loading-spinner" aria-hidden="true"></span><div class="page-transition-copy"><strong id="page-transition-message">Please Wait…</strong><span id="page-transition-note">Completing your request securely.</span></div></div></div>
  <script id="welcome-message" type="application/json"><?= json_encode($welcomeMessage, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
  <script id="permission-catalog" type="application/json"><?= json_encode(portal_permission_catalog(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
  <script src="js/icons.js?v=<?= filemtime(__DIR__ . '/js/icons.js') ?>"></script>
  <script src="js/access.js?v=<?= filemtime(__DIR__ . '/js/access.js') ?>"></script>
  <script src="js/portal.js?v=<?= filemtime(__DIR__ . '/js/portal.js') ?>"></script>
  <script src="js/dashboard.js?v=<?= filemtime(__DIR__ . '/js/dashboard.js') ?>"></script>
  <script src="js/app.js?v=<?= filemtime(__DIR__ . '/js/app.js') ?>"></script>
  <script src="js/roles.js?v=<?= filemtime(__DIR__ . '/js/roles.js') ?>"></script>
  <script src="js/chemicals.js?v=<?= filemtime(__DIR__ . '/js/chemicals.js') ?>"></script>
  <script src="js/chemical-consumption.js?v=<?= filemtime(__DIR__ . '/js/chemical-consumption.js') ?>"></script>
  <script src="js/chemical-consumption-report.js?v=<?= filemtime(__DIR__ . '/js/chemical-consumption-report.js') ?>"></script>
  <script src="js/chemical-cost-report.js?v=<?= filemtime(__DIR__ . '/js/chemical-cost-report.js') ?>"></script>
  <script src="js/breakdowns.js?v=<?= filemtime(__DIR__ . '/js/breakdowns.js') ?>"></script>
  <script src="js/modal-forms.js?v=<?= filemtime(__DIR__ . '/js/modal-forms.js') ?>"></script>
  <script src="js/table-export.js?v=<?= filemtime(__DIR__ . '/js/table-export.js') ?>"></script>
  <script src="js/table-pagination.js?v=<?= filemtime(__DIR__ . '/js/table-pagination.js') ?>"></script>
  <script src="js/auth.js?v=<?= filemtime(__DIR__ . '/js/auth.js') ?>"></script>
</body>
</html>
