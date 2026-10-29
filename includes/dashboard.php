<section class="view pns-dashboard" data-view="dashboard" aria-label="OEE Dashboard">
  <div class="page-heading oee-page-heading">
    <div><div class="dash-eyebrow">Production Intelligence</div><h1>PNS OEE</h1><p class="page-lead">Production, Batches, Downtime and Line performance in one view.</p></div>
    <div class="page-heading-actions">
      <label class="date-control">Machine<select id="dashboard-machine"><?= oee_machine_options_html($oeeLines ?? [], true) ?></select></label>
      <label class="date-control">Report Date<input id="dashboard-date" type="date"></label>
      <label class="date-control">Calculation Duration<select id="dashboard-duration"><option value="1440">1440 Min (24 Hours)</option><option value="960">960 Min (16 Hours)</option><option value="720">720 Min (12 Hours)</option><option value="600">600 Min (10 Hours)</option><option value="480">480 Min (8 Hours)</option></select></label>
      <button class="btn ghost" id="dashboard-duration-save" type="button" title="Save this duration for the selected report date">Save Duration</button>
      <button class="btn" id="dashboard-refresh" type="button">Refresh</button>
    </div>
  </div>
  <div id="dashboard-window" class="oee-window-summary" role="status"></div>
  <div id="dashboard-loading" class="pns-loading" role="status" hidden>Updating Selected Calculation…</div>
  <div id="oee-dashboard-alert" class="oee-alert" role="status" hidden></div>
  <div class="oee-status-strip"><span class="status-dot" id="oee-status-dot"></span><span id="oee-status-text">Checking SCADA Sync…</span><span class="oee-status-meta" id="oee-last-sync">—</span></div>
  <div class="oee-kpis">
    <article class="oee-kpi"><h2 class="oee-kpi-label">OEE</h2><div class="oee-kpi-value" id="oee-value">—</div><div class="oee-kpi-note">Availability × Performance × Quality</div><div class="pns-kpi-detail" id="dashboard-oee-detail">Selected Calculation</div></article>
    <article class="oee-kpi"><h2 class="oee-kpi-label">Availability</h2><div class="oee-kpi-value" id="availability-value">—</div><div class="oee-kpi-note">Run Time / Planned Time</div><div class="pns-kpi-detail" id="dashboard-availability-detail">—</div></article>
    <article class="oee-kpi"><h2 class="oee-kpi-label">Performance</h2><div class="oee-kpi-value" id="performance-value">—</div><div class="oee-kpi-note">Ideal Output Time / Run Time</div><div class="pns-kpi-detail" id="dashboard-performance-detail">—</div></article>
    <article class="oee-kpi"><h2 class="oee-kpi-label">Quality</h2><div class="oee-kpi-value" id="quality-value">—</div><div class="oee-kpi-note">Good FB / Productive FB</div><div class="pns-kpi-detail" id="dashboard-quality-detail">—</div></article>
  </div>
  <div class="pns-section-heading"><h2>Production Overview</h2><span>Selected Calculation Snapshot</span></div>
  <div class="oee-grid oee-grid-main">
    <section class="dash-card oee-card"><div class="dash-card-head"><h3>Production Summary</h3><a href="#/oee-report" data-goto="oee-report" data-dashboard-link="report" class="btn ghost sm dashboard-link-button"><span data-icon="arrow-right"></span><span>View Batches</span></a></div><div class="oee-production-grid">
      <div><span class="oee-stat-label">Total FB</span><strong id="total-fb">—</strong></div><div><span class="oee-stat-label">Productive FB</span><strong id="productive-fb">—</strong></div><div><span class="oee-stat-label">Empty Racks</span><strong id="empty-fb">—</strong></div><div><span class="oee-stat-label">Pieces</span><strong id="total-pcs">—</strong></div><div><span class="oee-stat-label">Surface Area</span><strong id="total-area">—</strong></div><div><span class="oee-stat-label">Productive Rack Rate</span><strong id="dashboard-rack-rate">—</strong></div>
    </div><p class="pns-card-foot">Report Date <span id="oee-report-date">—</span></p></section>
    <section class="dash-card oee-card"><div class="dash-card-head"><h3>Time Losses</h3><a href="#/downtime" data-goto="downtime" data-dashboard-link="downtime" class="btn ghost sm dashboard-link-button"><span data-icon="arrow-right"></span><span>View Downtime</span></a></div><div class="pns-loss-body">
      <div class="oee-loss-list"><div><span>Planned Stop</span><strong id="planned-minutes">—</strong></div><div><span>Unplanned Downtime</span><strong id="unplanned-minutes">—</strong></div><div><span>Run Time</span><strong id="run-minutes">—</strong></div><div><span id="utilization-label">24h Utilization</span><strong id="utilization-value">—</strong></div></div>
      <div id="dashboard-time-bar" class="pns-time-bar" role="img" aria-label="Time allocation unavailable"><span id="dashboard-run-bar" class="pns-segment-running"></span><span id="dashboard-unplanned-bar" class="pns-segment-stopped"></span><span id="dashboard-planned-bar" class="pns-segment-planned"></span></div>
      <div class="pns-legend"><span><i class="pns-segment-running"></i>Running <b id="dashboard-running-share">—</b></span><span><i class="pns-segment-stopped"></i>Unplanned <b id="dashboard-stopped-share">—</b></span><span><i class="pns-segment-planned"></i>Planned <b id="dashboard-planned-share">—</b></span></div>
    </div></section>
  </div>
  <div class="pns-section-heading"><h2>Live Operations</h2><span>Latest Calculation Records</span></div>
  <div class="pns-operations">
    <section class="dash-card oee-card pns-reason-card" data-permission-page="downtime" hidden><div class="dash-card-head"><h3>Downtime by Reason</h3><a href="#/downtime" data-goto="downtime" data-dashboard-link="downtime" class="btn ghost sm dashboard-link-button"><span data-icon="arrow-right"></span><span>View Downtime</span></a></div><div id="dashboard-reasons" class="pns-reasons"><p class="pns-empty">Loading Downtime Reasons…</p></div><p class="pns-card-foot">Overlapping stops count once per line. Unplanned takes precedence; confirmed/approved reasons take precedence over proposals.</p></section>
    <section class="dash-card oee-card" data-permission-page="downtime" hidden><div class="dash-card-head"><h3>Recent Downtime Events</h3><a href="#/downtime" data-goto="downtime" data-dashboard-link="downtime" class="btn ghost sm dashboard-link-button"><span data-icon="arrow-right"></span><span>View Downtime</span></a></div><div class="data-scroll"><table class="data-table compact-table"><thead><tr><th>Line / End</th><th>Reason</th><th>Source</th><th>Status</th></tr></thead><tbody id="dashboard-stops-body"><tr><td colspan="4">Loading Downtime…</td></tr></tbody></table></div></section>
    <p id="dashboard-detail-message" class="pns-empty" hidden>Detailed operations are restricted by your role. Aggregate production and time-loss KPIs remain available above.</p>
  </div>
  <section class="pns-batches-section" data-permission-page="oee-report" hidden>
    <div class="pns-section-heading"><h2>Production Batches</h2><span>Latest Flight Bars in This Calculation</span></div>
    <p id="dashboard-export-alert" class="oee-alert" role="alert" hidden></p>
    <div class="dash-card oee-card"><div class="dash-card-head"><h3>Recent Batches</h3><div class="table-heading-actions"><a href="#/oee-report" data-goto="oee-report" data-dashboard-link="report" class="btn ghost sm dashboard-link-button"><span data-icon="arrow-right"></span><span>View Batches</span></a><button class="btn ghost" id="dashboard-export" type="button" data-permission-page="oee-report" data-permission-action="export" title="Export all batches and OEE calculations for the selected calculation" hidden><span data-icon="arrow-up-tray"></span><span>Export Excel</span></button></div></div><div class="data-scroll"><table class="data-table compact-table" data-no-table-export><thead><tr><th>Line / Part</th><th>Grade / Colour</th><th>PCS</th><th>Total SA</th><th>State</th></tr></thead><tbody id="dashboard-batches-body"><tr><td colspan="5">Loading Batches…</td></tr></tbody></table></div></div>
  </section>
  <p class="pns-review-note">Automatic gap and empty-rack stops are estimates until reviewed. KPI values use the current rules and selected calculation duration.</p>
  <details class="pns-sync-details"><summary>Synchronization Details & Recent Imports</summary><div class="oee-grid oee-grid-bottom"><section class="dash-card oee-card"><div class="dash-card-head"><h3>Recent Imports</h3></div><div class="data-scroll"><table class="data-table compact-table"><thead><tr><th>Batch</th><th>Date</th><th>Rows</th><th>Status</th><th>Received</th></tr></thead><tbody id="oee-imports-body"></tbody></table></div></section><section class="dash-card oee-card"><div class="dash-card-head"><h3>Sync Health</h3></div><div id="oee-sync-list" class="oee-sync-list"></div></section></div></details>
  <p class="pns-dashboard-footer">PNS Anodizing · SCADA Reporting Data · Refresh to Load the Latest Calculation</p>
  <dialog id="dashboard-tag-dialog" class="pns-tag-dialog" aria-labelledby="dashboard-tag-title" aria-describedby="dashboard-tag-context dashboard-tag-help">
    <form id="dashboard-tag-form">
      <h2 id="dashboard-tag-title">Tag Downtime Reason</h2>
      <p id="dashboard-tag-context" class="pns-tag-context"></p>
      <p id="dashboard-tag-help" class="pns-tag-help">The original interval and SCADA source are preserved. Saving confirms a proposed stop; other review statuses remain unchanged.</p>
      <p id="dashboard-tag-error" class="oee-alert" role="alert" hidden></p>
      <div class="pns-tag-fields">
        <label class="form-label" for="dashboard-tag-type">Type<select class="form-select" id="dashboard-tag-type" required><option value="planned">Planned</option><option value="unplanned">Unplanned</option></select></label>
        <label class="form-label" for="dashboard-tag-reason">Reason<select class="form-select" id="dashboard-tag-reason" required autofocus></select></label>
        <label class="form-label pns-tag-notes" for="dashboard-tag-notes">Notes<textarea class="form-textarea" id="dashboard-tag-notes" maxlength="500" rows="3"></textarea></label>
      </div>
      <div class="pns-tag-actions"><button type="button" class="btn ghost" id="dashboard-tag-cancel">Cancel</button><button type="submit" class="btn" id="dashboard-tag-save" disabled>Save Reason</button></div>
    </form>
  </dialog>
</section>
