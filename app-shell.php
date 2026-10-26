<?php
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/permissions.php';
require_authentication();
$csrfToken = csrf_token();
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>App Portal</title>
  <link rel="stylesheet" href="css/portal.css?v=<?= filemtime(__DIR__ . '/css/portal.css') ?>">
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
            <div class="app-sidebar-brand-subtitle">PNS · Unit 1</div>
          </div>
        </div>
      </div>
      <nav class="app-sidebar-nav">
        <a class="app-sidebar-link is-active" href="#/dashboard" data-nav="dashboard" title="Dashboard"><span class="app-sidebar-icon" data-icon="dashboard"></span><span class="app-sidebar-label">OEE dashboard</span></a>
        <a class="app-sidebar-link" href="#/oee-range" data-nav="oee-range" title="Date-range OEE"><span class="app-sidebar-icon" data-icon="dashboard"></span><span class="app-sidebar-label">Date-range OEE</span></a>
        <a class="app-sidebar-link" href="#/oee-report" data-nav="oee-report" title="Production report"><span class="app-sidebar-icon" data-icon="report"></span><span class="app-sidebar-label">Production report</span></a>
        <a class="app-sidebar-link" href="#/scada-logs" data-nav="scada-logs" title="SCADA logs"><span class="app-sidebar-icon" data-icon="report"></span><span class="app-sidebar-label">SCADA logs</span></a>
        <a class="app-sidebar-link" href="#/downtime" data-nav="downtime" title="Downtime review"><span class="app-sidebar-icon" data-icon="report"></span><span class="app-sidebar-label">Downtime review</span></a>
        <div class="app-sidebar-section-label">Workspace</div>
        <a class="app-sidebar-link" href="#/requests" data-nav="requests" title="Records"><span class="app-sidebar-icon" data-icon="requests"></span><span class="app-sidebar-label">Records</span></a>
        <div class="app-sidebar-section-label">Administration</div>
        <a class="app-sidebar-link" href="#/roles" data-nav="roles" title="Roles & permissions"><span class="app-sidebar-icon" data-icon="users"></span><span class="app-sidebar-label">Roles & permissions</span></a>
        <a class="app-sidebar-link" href="#/users" data-nav="users" title="Users"><span class="app-sidebar-icon" data-icon="users"></span><span class="app-sidebar-label">Users</span></a>
        <a class="app-sidebar-link" href="#/oee-masters" data-nav="oee-masters" title="OEE master data"><span class="app-sidebar-icon" data-icon="master"></span><span class="app-sidebar-label">OEE master data</span></a>
        <a class="app-sidebar-link" href="#/profile" data-nav="profile" title="Profile"><span class="app-sidebar-icon" data-icon="profile"></span><span class="app-sidebar-label">My Profile</span></a>
        <form method="post" action="logout.php">
          <input type="hidden" name="csrf_token" value="<?= escape_html($csrfToken) ?>">
          <button class="app-sidebar-logout" type="submit" title="Sign out"><span class="app-sidebar-icon" data-icon="logout"></span><span class="app-sidebar-label">Sign out</span></button>
        </form>
      </nav>
      <div class="app-sidebar-footer">
        <button type="button" id="sidebar-toggle" class="app-sidebar-toggle" aria-label="Toggle sidebar" aria-expanded="true" title="Collapse sidebar"><span data-icon="chevron"></span></button>
      </div>
    </aside>

    <div class="app-main">
      <header class="app-topbar">
        <div class="app-topbar-inner">
          <div style="display:flex;align-items:center;gap:12px;min-width:0">
            <button type="button" id="sidebar-mobile-toggle" class="app-sidebar-mobile-toggle" aria-label="Open navigation"><span data-icon="menu"></span></button>
            <div class="app-topbar-title" id="page-title">Dashboard</div>
          </div>
          <div class="app-topbar-actions">
            <div class="app-header-menu" data-header-menu>
              <button type="button" class="app-header-profile-btn" data-header-toggle="profile" aria-label="User menu" aria-expanded="false" aria-controls="header-profile-panel">
                <span class="app-header-avatar">P</span>
                <span class="app-header-profile-text"><span class="app-header-profile-name">Account</span><span class="app-header-profile-user">username</span></span>
              </button>
              <div id="header-profile-panel" class="app-header-panel" data-header-panel="profile" hidden>
                <div class="app-header-profile-card"><span class="app-header-avatar app-header-avatar-lg">P</span><div><div class="app-header-profile-name">Account</div><div class="app-header-profile-user">username</div></div></div>
                <a class="app-header-profile-link" href="#/profile" data-goto="profile">View profile</a>
                <form method="post" action="logout.php">
                  <input type="hidden" name="csrf_token" value="<?= escape_html($csrfToken) ?>">
                  <button class="app-header-logout" type="submit">Sign out</button>
                </form>
              </div>
            </div>
          </div>
        </div>
      </header>

      <main class="app-content">
        <section class="view" data-view="access-loading"><h1>Loading permissions…</h1></section>
        <section class="view" data-view="access-denied"><h1>Access denied</h1><p>Your role does not allow this page or action. Choose an allowed page from the menu, or contact an administrator.</p></section>
        <section class="view active" data-view="dashboard">
          <div class="page-heading oee-page-heading"><div><div class="dash-eyebrow">Production intelligence</div><h1>PNS anodizing OEE</h1><p class="page-lead">Daily output, line losses, and synchronization health in one view.</p></div><div class="page-heading-actions"><label class="date-control">Machine <select id="dashboard-machine"><option value="ALL">All machines</option><option value="APP-06">APP-06</option><option value="APP-07">APP-07</option><option value="APP-10">APP-10</option></select></label><label class="date-control">Report date <input id="dashboard-date" type="date"></label><label class="date-control">Calculation duration <select id="dashboard-duration"><option value="1440">1440 min (24 hours)</option><option value="720">720 min (12 hours)</option></select></label><label class="date-control">Window start <input id="dashboard-start-time" type="time" value="00:00" step="60" disabled></label><button class="btn" id="dashboard-refresh" type="button">Refresh</button></div></div>
          <div id="dashboard-window" class="oee-window-summary" role="status"></div>
          <div id="oee-dashboard-alert" class="oee-alert" role="status" hidden></div>
          <div class="oee-status-strip"><span class="status-dot" id="oee-status-dot"></span><span id="oee-status-text">Checking Unit 1 sync…</span><span class="oee-status-meta" id="oee-last-sync">—</span></div>
          <div class="oee-kpis">
            <article class="oee-kpi"><div class="oee-kpi-label">OEE</div><div class="oee-kpi-value" id="oee-value">—</div><div class="oee-kpi-note">Availability × Performance × Quality</div></article>
            <article class="oee-kpi"><div class="oee-kpi-label">Availability</div><div class="oee-kpi-value" id="availability-value">—</div><div class="oee-kpi-note">Run time / planned time</div></article>
            <article class="oee-kpi"><div class="oee-kpi-label">Performance</div><div class="oee-kpi-value" id="performance-value">—</div><div class="oee-kpi-note">Ideal output / run time</div></article>
            <article class="oee-kpi"><div class="oee-kpi-label">Quality</div><div class="oee-kpi-value" id="quality-value">—</div><div class="oee-kpi-note">Good FB / productive FB</div></article>
          </div>
          <div class="oee-grid oee-grid-main">
            <section class="dash-card oee-card"><div class="dash-card-head"><span>Production summary</span><a href="#/oee-report" data-goto="oee-report" class="link">Open report</a></div><div class="oee-production-grid"><div><span class="oee-stat-label">Total FB</span><strong id="total-fb">—</strong></div><div><span class="oee-stat-label">Productive FB</span><strong id="productive-fb">—</strong></div><div><span class="oee-stat-label">Empty racks</span><strong id="empty-fb">—</strong></div><div><span class="oee-stat-label">Pieces</span><strong id="total-pcs">—</strong></div><div><span class="oee-stat-label">Surface area</span><strong id="total-area">—</strong></div><div><span class="oee-stat-label">Report date</span><strong id="oee-report-date">—</strong></div></div></section>
            <section class="dash-card oee-card"><div class="dash-card-head">Time losses</div><div class="oee-loss-list"><div><span>Planned stop</span><strong id="planned-minutes">—</strong></div><div><span>Unplanned downtime</span><strong id="unplanned-minutes">—</strong></div><div><span>Run time</span><strong id="run-minutes">—</strong></div><div><span id="utilization-label">24h utilization</span><strong id="utilization-value">—</strong></div></div></section>
          </div>
          <div class="oee-grid oee-grid-bottom"><section class="dash-card oee-card"><div class="dash-card-head">Recent imports</div><div class="data-scroll"><table class="data-table compact-table"><thead><tr><th>Batch</th><th>Date</th><th>Rows</th><th>Status</th><th>Received</th></tr></thead><tbody id="oee-imports-body"></tbody></table></div></section><section class="dash-card oee-card"><div class="dash-card-head">Sync health</div><div id="oee-sync-list" class="oee-sync-list"></div></section></div>
        </section>

        <section class="view" data-view="oee-range">
          <div class="page-heading"><div><div class="dash-eyebrow">Period intelligence</div><h1>Date-range OEE</h1><p class="page-lead">Combined OEE for the selected dates. Duration applies to each day and line.</p></div></div>
          <section class="data-card">
            <form id="range-form" class="report-filters">
              <label>From date<input id="range-from-date" type="date" required></label>
              <label>To date<input id="range-to-date" type="date" required></label>
              <label>Machine<select id="range-machine"><option value="ALL">All machines</option><option value="APP-06">APP-06</option><option value="APP-07">APP-07</option><option value="APP-10">APP-10</option></select></label>
              <label>Minutes per day<select id="range-duration"><option value="1440">1440 min (24 hours)</option><option value="720">720 min (12 hours)</option></select></label>
              <label>Daily window start<input id="range-start-time" type="time" value="00:00" step="60" disabled></label>
              <button class="btn" type="submit" id="range-load">Calculate OEE</button>
              <button class="btn ghost" type="button" id="range-export">Export Excel</button>
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
            <section class="dash-card oee-card"><div class="dash-card-head">Production summary</div><div class="oee-production-grid"><div><span class="oee-stat-label">Total FB</span><strong id="range-total-fb">—</strong></div><div><span class="oee-stat-label">Productive FB</span><strong id="range-productive-fb">—</strong></div><div><span class="oee-stat-label">Empty racks</span><strong id="range-empty-fb">—</strong></div><div><span class="oee-stat-label">Pieces</span><strong id="range-pcs">—</strong></div><div><span class="oee-stat-label">Surface area</span><strong id="range-area">—</strong></div><div><span class="oee-stat-label">Days selected</span><strong id="range-days">—</strong></div></div></section>
            <section class="dash-card oee-card"><div class="dash-card-head">Time accounting</div><div class="oee-loss-list"><div><span>Selected window time</span><strong id="range-calendar">—</strong></div><div><span>Planned stops</span><strong id="range-planned-stop">—</strong></div><div><span>Planned production time</span><strong id="range-planned">—</strong></div><div><span>Unplanned downtime</span><strong id="range-unplanned">—</strong></div><div><span>Run time</span><strong id="range-run">—</strong></div><div><span>Window utilization</span><strong id="range-utilization">—</strong></div></div></section>
          </div>
          <section class="data-card"><div class="dash-card-head">Line-wise OEE · selected period</div><div class="data-scroll"><table class="data-table compact-table"><thead><tr><th>Line</th><th>Productive FB</th><th>Run (min)</th><th>Ideal output (min)</th><th>Availability</th><th>Performance</th><th>Quality</th><th>OEE</th><th>Window utilization</th></tr></thead><tbody id="range-lines-body"></tbody></table></div></section>
          <section class="data-card"><div class="dash-card-head">Daily line breakdown</div><div class="data-scroll"><table class="data-table compact-table"><thead><tr><th>Reporting date</th><th>Line</th><th>Window start</th><th>Window end</th><th>Productive FB</th><th>Run (min)</th><th>OEE</th></tr></thead><tbody id="range-days-body"></tbody></table></div></section>
        </section>

        <section class="view" data-view="oee-report">
          <div class="page-heading oee-page-heading"><div><div class="dash-eyebrow">Traceable production data</div><h1>Production report</h1><p class="page-lead">Line-wise flight-bar data with historical date export.</p></div><div class="page-heading-actions"><label class="date-control">Report date <input id="report-date" type="date"></label><label class="date-control">Calculation duration <select id="report-duration"><option value="1440">1440 min (24 hours)</option><option value="720">720 min (12 hours)</option></select></label><label class="date-control">Window start <input id="report-start-time" type="time" value="00:00" step="60" disabled></label><button class="btn" id="report-load" type="button">Load report</button><button class="btn ghost" id="report-export" type="button">Export Excel</button></div></div>
          <div id="report-window" class="oee-window-summary" role="status"></div>
          <div id="oee-report-alert" class="oee-alert" role="status" hidden></div>
          <section class="data-card oee-report-card"><div class="report-filters"><label>Line / machine<select id="report-machine-filter"><option value="ALL">All lines</option><option value="APP-06">APP-06 · OEE feed</option><option value="APP-07">APP-07 · OEE feed</option><option value="APP-10">APP-10 · OEE feed</option></select></label><label>Search<input id="report-search" type="search" placeholder="Sequence, part, colour"></label><label>Grade<select id="report-grade-filter"><option value="">All grades</option></select></label><label>Colour<select id="report-colour-filter"><option value="">All colours</option></select></label><label>State<select id="report-state-filter"><option value="">All states</option><option value="productive">Productive</option><option value="empty">Empty</option></select></label></div><div class="oee-kpis report-kpis"><article class="oee-kpi"><div class="oee-kpi-label">OEE</div><div class="oee-kpi-value" id="report-oee-value">—</div></article><article class="oee-kpi"><div class="oee-kpi-label">Availability</div><div class="oee-kpi-value" id="report-availability-value">—</div></article><article class="oee-kpi"><div class="oee-kpi-label">Performance</div><div class="oee-kpi-value" id="report-performance-value">—</div></article><article class="oee-kpi"><div class="oee-kpi-label">Quality</div><div class="oee-kpi-value" id="report-quality-value">—</div></article></div><div class="report-summary" id="report-summary"></div><div class="data-scroll"><table class="data-table compact-table"><thead><tr><th>Line</th><th>OEE</th><th>Availability</th><th>Performance</th><th>Quality</th><th>Window utilization</th></tr></thead><tbody id="report-line-metrics-body"></tbody></table></div><div class="data-scroll"><table class="data-table oee-report-table"><thead><tr><th>Line</th><th>SI</th><th>Sequence</th><th>Part</th><th>Grade</th><th>Colour</th><th>Loading</th><th>Unloading</th><th>PCS</th><th>Total SA</th><th>State</th></tr></thead><tbody id="oee-report-body"></tbody></table></div></section>
        </section>

        <section class="view" data-view="downtime">
          <div class="page-heading"><div><div class="dash-eyebrow">Loss accounting</div><h1>Downtime review</h1><p class="page-lead">Tag planned and unplanned stops so OEE can calculate the loss correctly.</p></div><div class="page-heading-actions"><button class="btn" id="downtime-refresh" type="button">Refresh</button></div></div>
          <div id="downtime-alert" class="oee-alert" role="status" hidden></div>
          <section class="data-card downtime-card"><div class="report-filters"><label>Machine<select id="downtime-machine"><option value="APP-06">APP-06</option><option value="APP-07">APP-07</option><option value="APP-10">APP-10</option></select></label><label>Date<input id="downtime-date" type="date"></label></div>
            <form id="downtime-form" class="downtime-form"><label>Start<input id="downtime-start" type="datetime-local" required></label><label>End<input id="downtime-end" type="datetime-local" required></label><label>Type<select id="downtime-type"><option value="unplanned">Unplanned</option><option value="planned">Planned</option></select></label><label>Reason<select id="downtime-reason"><option value="UNK">Not yet classified</option><option value="BATH">Bath / chemical</option><option value="HOIST">Hoist / movement</option><option value="WAITING_MATERIAL">Waiting for material</option><option value="QUALITY">Quality hold</option><option value="PLANNED_STOP">Planned stop</option><option value="OTHER">Other</option></select></label><label class="form-span">Notes<input id="downtime-notes" maxlength="500"></label><button class="btn" type="submit">Save downtime</button></form>
            <div class="data-scroll"><table class="data-table"><thead><tr><th>Start</th><th>End</th><th>Type</th><th>Reason</th><th>Source</th><th>Status</th><th>Notes</th><th>Action</th></tr></thead><tbody id="downtime-body"></tbody></table></div></section>
        </section>

        <section class="view" data-view="scada-logs">
          <div class="page-heading"><div><div class="dash-eyebrow">Operations</div><h1>SCADA logs</h1><p class="page-lead">Import batches and Unit 1 synchronization events received by the local OEE app.</p></div><div class="page-heading-actions"><span class="sync-cadence">Expected sync: every 10 minutes</span><button class="btn" id="logs-refresh" type="button">Refresh logs</button></div></div>
          <div id="oee-logs-alert" class="oee-alert" role="status" hidden></div>
          <div class="oee-log-grid"><section class="data-card"><div class="dash-card-head">Source status</div><div id="scada-sync-body" class="oee-sync-list"></div></section><section class="data-card"><div class="dash-card-head">Import event log</div><div class="data-scroll"><table class="data-table compact-table"><thead><tr><th>Received</th><th>Source</th><th>Batch</th><th>Report date</th><th>Rows</th><th>Status</th><th>Error</th></tr></thead><tbody id="scada-logs-body"></tbody></table></div></section></div>
        </section>

        <section class="view" data-view="requests">
          <div class="page-toolbar">
            <form class="page-toolbar-left" id="record-filter-form">
              <div class="page-search"><input id="records-search" type="search" placeholder="Search number or title"></div>
              <select id="record-type-filter"><option value="">All types</option><option>Ticket</option><option>Change</option><option>Asset</option><option>Document</option></select>
              <select id="record-status-filter"><option value="">All statuses</option><option>Open</option><option>In review</option><option>Closed</option></select>
              <button class="btn" type="submit">Filter</button>
            </form>
            <div class="page-toolbar-right"><button class="btn" type="button" data-goto="create">+ New record</button></div>
          </div>
          <div class="data-card"><div class="data-scroll"><table class="data-table"><thead><tr><th>Number</th><th>Title</th><th>Owner</th><th>Type</th><th>Status</th><th>Created</th><th>Updated</th><th class="r">Actions</th></tr></thead><tbody id="records-body"></tbody></table></div></div>
        </section>

        <section class="view" data-view="create">
          <div class="page-heading"><nav class="page-breadcrumb"><a href="#/requests" data-goto="requests">Records</a> / New</nav><div class="page-heading-actions"><button class="btn ghost" type="button" data-goto="requests">Cancel</button></div></div>
          <form id="record-create-form" method="post" action="app.php">
            <div class="form-section"><div class="form-section-head">Record details</div><div class="form-section-body">
              <div><label class="form-label" for="record-type">Type <span class="req">*</span></label><select class="form-select" id="record-type" name="record_type" required><option>Ticket</option><option>Change</option><option>Asset</option><option>Document</option></select></div>
              <div><label class="form-label" for="record-organization">Organization <span class="req">*</span></label><select class="form-select" id="record-organization" name="organization" required><option>HQ</option><option>Plant A</option><option>Plant B</option></select></div>
              <div><label class="form-label" for="record-category">Category</label><select class="form-select" id="record-category" name="category"><option>General</option><option>IT</option><option>Operations</option><option>HR</option></select></div>
              <div><label class="form-label" for="record-priority">Priority</label><select class="form-select" id="record-priority" name="priority"><option>Normal</option><option>High</option><option>Low</option></select></div>
              <div class="form-span"><label class="form-label" for="record-title">Title <span class="req">*</span></label><input class="form-input" id="record-title" name="title" maxlength="200" required></div>
              <div class="form-span"><label class="form-label" for="record-description">Description</label><textarea class="form-textarea" id="record-description" name="description" maxlength="10000"></textarea></div>
            </div></div>
            <div class="form-footer"><button class="btn" type="submit">Create record</button></div>
          </form>
        </section>

        <section class="view" data-view="detail">
          <div class="page-heading"><nav class="page-breadcrumb"><a href="#/requests" data-goto="requests">Records</a> / <span id="detail-number">Record</span></nav><div class="page-heading-actions"><button class="btn ghost" id="record-edit-toggle" data-permission-page="records" data-permission-action="update" type="button">Edit</button></div></div>
          <div class="detail-layout"><div class="form-section"><div class="form-section-head">Record details</div><dl class="detail-dl">
            <div><dt>Type</dt><dd id="detail-type">—</dd></div><div><dt>Organization</dt><dd id="detail-organization">—</dd></div><div><dt>Category</dt><dd id="detail-category">—</dd></div><div><dt>Owner</dt><dd id="detail-owner">—</dd></div><div><dt>Status</dt><dd id="detail-status">—</dd></div><div class="form-span"><dt>Title</dt><dd id="detail-title">—</dd></div><div class="form-span"><dt>Description</dt><dd id="detail-description">—</dd></div>
          </dl><form id="record-edit-form" class="form-stack" hidden method="post" action="app.php"><div><label class="form-label" for="edit-record-title">Title</label><input class="form-input" id="edit-record-title" name="title" maxlength="200" required></div><div><label class="form-label" for="edit-record-description">Description</label><textarea class="form-textarea" id="edit-record-description" name="description" maxlength="10000"></textarea></div><button class="btn" type="submit">Save changes</button></form></div></div>
        </section>

        <section class="view" data-view="users">
          <div class="page-toolbar"><div class="page-lead">Manage account access.</div><button class="btn" type="button" data-goto="user-create">+ Add User</button></div>
          <div class="page-toolbar"><form class="page-toolbar-left" id="user-search-form"><div class="page-search"><input id="users-search" type="search" placeholder="Search users"></div><button class="btn ghost sm" type="submit">Search</button></form></div>
          <div class="data-card"><div class="data-scroll"><table class="data-table"><thead><tr><th>ID</th><th>Username</th><th>Full Name</th><th>Email</th><th>Role</th><th>Status</th><th>Created</th><th class="r">Actions</th></tr></thead><tbody id="users-body"></tbody></table></div></div>
        </section>

        <section class="view" data-view="user-create">
          <div class="page-heading"><nav class="page-breadcrumb"><a href="#/users" data-goto="users">Users</a> / New</nav><div class="page-heading-actions"><button class="btn ghost" type="button" data-goto="users">Cancel</button><button class="btn" type="submit" form="user-create-form">Create</button></div></div>
          <div class="form-card"><div class="form-card-title">Add User</div><form id="user-create-form" class="form-stack" method="post" action="app.php">
            <div class="form-grid-2"><div><label class="form-label" for="new-user-email">Email <span class="req">*</span></label><input class="form-input" id="new-user-email" name="email" type="email" required></div><div><label class="form-label" for="new-user-username">Username <span class="req">*</span></label><input class="form-input" id="new-user-username" name="username" minlength="3" maxlength="80" required></div></div>
            <div><label class="form-label" for="new-user-name">Full Name <span class="req">*</span></label><input class="form-input" id="new-user-name" name="full_name" maxlength="160" required></div><div><label class="form-label" for="new-user-password">Temporary Password <span class="req">*</span></label><input class="form-input" id="new-user-password" name="password" type="password" minlength="12" required></div><p class="form-note">The user can change this password in their profile.</p>
            <div><label class="form-label" for="new-user-role">Access role</label><select class="form-select" id="new-user-role" name="role_id" required></select></div>
          </form></div>
        </section>

        <section class="view" data-view="roles">
          <div class="page-heading"><div><div class="dash-eyebrow">Administration</div><h1>Roles & permissions</h1><p class="page-lead">Choose which pages and actions each role can use. Assign roles on the Users page.</p></div><button class="btn ghost" id="roles-refresh" type="button">Refresh</button></div>
          <div id="roles-alert" class="oee-alert" role="status" hidden></div>
          <section class="data-card"><div class="dash-card-head">Registered roles</div><div class="data-scroll"><table class="data-table"><thead><tr><th>Role</th><th>Type</th><th>Assigned users</th><th>Actions</th></tr></thead><tbody id="roles-body"><tr><td colspan="4">Loading roles…</td></tr></tbody></table></div></section>
          <form id="role-form" class="data-card role-editor"><div class="dash-card-head"><h2>Role permission editor</h2><button id="role-new" type="button" class="btn ghost sm">New role</button></div><input id="role-id" type="hidden"><div class="master-form"><label for="role-name">Role name<input id="role-name" maxlength="80" required placeholder="Example: OEE viewer"></label><button class="btn" id="role-save" type="submit">Create role</button></div><p class="form-note role-help">View is required for other actions. Dashes mean the page has no such operation. Role administration is reserved for protected administrators. Existing record ownership restrictions still apply.</p><div class="data-scroll"><table class="data-table permission-table"><thead><tr><th>Page</th><th>View</th><th>Create</th><th>Update</th><th>Delete</th></tr></thead><tbody id="role-permissions-body"></tbody></table></div></form>
        </section>

        <section class="view" data-view="profile">
          <div class="page-heading"><nav class="page-breadcrumb">My Profile</nav><div class="page-heading-actions"><button class="btn" type="submit" form="profile-form">Save</button></div></div>
          <div class="detail-layout"><div class="form-card"><div class="form-card-title">Profile</div><form id="profile-form" class="form-stack" method="post" action="app.php">
              <div><label class="form-label" for="profile-username">Username</label><input class="form-input" id="profile-username" readonly></div><div><label class="form-label" for="profile-name">Full Name</label><input class="form-input" id="profile-name" name="full_name" maxlength="160" required></div><div><label class="form-label" for="profile-email">Email</label><input class="form-input" id="profile-email" type="email" readonly><p class="form-note">Contact an administrator to change your account email.</p></div>
          </form><form id="password-form" class="form-stack" style="margin-top:1.25rem;padding-top:1.25rem;border-top:1px solid var(--line)" method="post" action="app.php"><h3 class="form-card-title">Change password</h3><div><label class="form-label" for="current-password">Current password</label><input class="form-input" id="current-password" name="current_password" type="password" autocomplete="current-password" required></div><div><label class="form-label" for="new-password">New password</label><input class="form-input" id="new-password" name="new_password" type="password" minlength="12" autocomplete="new-password" required></div><button class="btn" type="submit">Update password</button></form></div></div>
        </section>

        <section class="view" data-view="oee-masters">
          <div class="page-heading"><div><div class="dash-eyebrow">Administration</div><h1>OEE master data</h1><p class="page-lead">Register cycle times and downtime reasons used by the OEE calculation.</p></div><div class="page-heading-actions"><button class="btn" id="masters-refresh" type="button">Refresh</button></div></div>
          <div id="masters-alert" class="oee-alert" role="status" hidden></div>
          <section class="data-card threshold-card" aria-labelledby="threshold-heading">
            <div class="dash-card-head"><h2 id="threshold-heading">Plant gap threshold</h2></div>
            <p id="threshold-help" class="form-note threshold-help">A loading gap must be greater than this limit to propose downtime. Downtime = gap minus the arriving FB cycle time (minimum zero). Empty-rack detection is separate. Changes apply to historical reports on refresh; tagged events stay unchanged.</p>
            <form id="threshold-form" class="master-form" aria-describedby="threshold-help">
              <label for="threshold-line">Plant / machine<select id="threshold-line" disabled><option>APP-06</option><option>APP-07</option><option>APP-10</option></select></label>
              <label for="threshold-minutes">Gap threshold (minutes)<input id="threshold-minutes" type="number" min="0.001" max="1440" step="0.001" required disabled></label>
              <button id="threshold-save" class="btn" type="submit" disabled>Save threshold</button>
            </form>
            <div class="data-scroll"><table class="data-table"><thead><tr><th>Plant / machine</th><th>Gap threshold (minutes)</th><th>Action</th></tr></thead><tbody id="thresholds-body"><tr><td colspan="3">Loading plant thresholds…</td></tr></tbody></table></div>
          </section>
          <div class="oee-master-grid">
            <section class="data-card"><div class="dash-card-head">Reason registration</div><form id="reason-form" class="master-form"><input id="reason-id" type="hidden"><label>Code<input id="reason-code" maxlength="40" placeholder="HOIST" required></label><label>Label<input id="reason-label" maxlength="120" placeholder="Hoist / movement" required></label><button class="btn" type="submit">Save reason</button></form><div class="data-scroll"><table class="data-table"><thead><tr><th>Code</th><th>Label</th><th>Status</th><th>Action</th></tr></thead><tbody id="reasons-body"></tbody></table></div></section>
            <section class="data-card"><div class="dash-card-head">Cycle-time registration</div><form id="cycle-form" class="master-form"><input id="cycle-id" type="hidden"><label>Machine<select id="cycle-line"><option>APP-06</option><option>APP-07</option><option>APP-10</option></select></label><label>Colour<input id="cycle-colour" maxlength="120" placeholder="BLACK-1" required></label><label>Minutes / FB<input id="cycle-minutes" type="number" min="0.001" step="0.001" required></label><label>Effective from<input id="cycle-from" type="date" required></label><label>Effective to<input id="cycle-to" type="date"></label><button class="btn" type="submit">Save cycle time</button></form><div class="data-scroll"><table class="data-table"><thead><tr><th>Machine</th><th>Colour</th><th>Minutes/FB</th><th>From</th><th>To</th><th>Action</th></tr></thead><tbody id="cycles-body"></tbody></table></div></section>
          </div>
        </section>
      </main>
    </div>
  </div>
  <div id="toast-stack" class="app-toast-stack" aria-live="polite"></div>
  <script id="permission-catalog" type="application/json"><?= json_encode(portal_permission_catalog(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
  <script src="js/icons.js"></script>
  <script src="js/access.js?v=<?= filemtime(__DIR__ . '/js/access.js') ?>"></script>
  <script src="js/portal.js?v=<?= filemtime(__DIR__ . '/js/portal.js') ?>"></script>
  <script src="js/app.js?v=<?= filemtime(__DIR__ . '/js/app.js') ?>"></script>
  <script src="js/roles.js?v=<?= filemtime(__DIR__ . '/js/roles.js') ?>"></script>
</body>
</html>
