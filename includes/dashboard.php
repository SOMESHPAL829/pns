<section class="view pns-dashboard" data-view="dashboard" aria-label="OEE dashboard">
  <div class="page-heading oee-page-heading">
    <div><div class="dash-eyebrow">Production intelligence</div><h1>PNS anodizing OEE</h1><p class="page-lead">Production, batches, downtime and line performance in one view.</p></div>
    <div class="page-heading-actions">
      <label class="date-control">Machine<select id="dashboard-machine"><option value="ALL">All machines</option><option>APP-06</option><option>APP-07</option><option>APP-10</option></select></label>
      <label class="date-control">Report date<input id="dashboard-date" type="date"></label>
      <label class="date-control">Calculation duration<select id="dashboard-duration"><option value="1440">1440 min (24 hours)</option><option value="720">720 min (12 hours)</option></select></label>
      <label class="date-control">Window start<input id="dashboard-start-time" type="time" value="00:00" step="60" disabled></label>
      <button class="btn" id="dashboard-refresh" type="button">Refresh</button>
    </div>
  </div>
  <div id="dashboard-window" class="oee-window-summary" role="status"></div>
  <div id="dashboard-loading" class="pns-loading" role="status" hidden>Updating selected reporting window…</div>
  <div id="oee-dashboard-alert" class="oee-alert" role="status" hidden></div>
  <div class="oee-status-strip"><span class="status-dot" id="oee-status-dot"></span><span id="oee-status-text">Checking Unit 1 sync…</span><span class="oee-status-meta" id="oee-last-sync">—</span></div>
  <div class="oee-kpis">
    <article class="oee-kpi"><h2 class="oee-kpi-label">OEE</h2><div class="oee-kpi-value" id="oee-value">—</div><div class="oee-kpi-note">Availability × Performance × Quality</div><div class="pns-kpi-detail" id="dashboard-oee-detail">Selected reporting window</div></article>
    <article class="oee-kpi"><h2 class="oee-kpi-label">Availability</h2><div class="oee-kpi-value" id="availability-value">—</div><div class="oee-kpi-note">Run time / planned time</div><div class="pns-kpi-detail" id="dashboard-availability-detail">—</div></article>
    <article class="oee-kpi"><h2 class="oee-kpi-label">Performance</h2><div class="oee-kpi-value" id="performance-value">—</div><div class="oee-kpi-note">Ideal output time / run time</div><div class="pns-kpi-detail" id="dashboard-performance-detail">—</div></article>
    <article class="oee-kpi"><h2 class="oee-kpi-label">Quality</h2><div class="oee-kpi-value" id="quality-value">—</div><div class="oee-kpi-note">Good FB / productive FB</div><div class="pns-kpi-detail" id="dashboard-quality-detail">—</div></article>
  </div>
  <div class="pns-section-heading"><h2>Production overview</h2><span>Snapshot for the selected reporting window</span></div>
  <div class="oee-grid oee-grid-main">
    <section class="dash-card oee-card"><div class="dash-card-head"><h3>Production summary</h3><a href="#/oee-report" data-goto="oee-report" data-dashboard-link="report" class="link">View batches →</a></div><div class="oee-production-grid">
      <div><span class="oee-stat-label">Total FB</span><strong id="total-fb">—</strong></div><div><span class="oee-stat-label">Productive FB</span><strong id="productive-fb">—</strong></div><div><span class="oee-stat-label">Empty racks</span><strong id="empty-fb">—</strong></div><div><span class="oee-stat-label">Pieces</span><strong id="total-pcs">—</strong></div><div><span class="oee-stat-label">Surface area</span><strong id="total-area">—</strong></div><div><span class="oee-stat-label">Productive rack rate</span><strong id="dashboard-rack-rate">—</strong></div>
    </div><p class="pns-card-foot">Report date <span id="oee-report-date">—</span></p></section>
    <section class="dash-card oee-card"><div class="dash-card-head"><h3>Time losses</h3><a href="#/downtime" data-goto="downtime" data-dashboard-link="downtime" class="link">View log →</a></div><div class="pns-loss-body">
      <div class="oee-loss-list"><div><span>Planned stop</span><strong id="planned-minutes">—</strong></div><div><span>Unplanned downtime</span><strong id="unplanned-minutes">—</strong></div><div><span>Run time</span><strong id="run-minutes">—</strong></div><div><span id="utilization-label">24h utilization</span><strong id="utilization-value">—</strong></div></div>
      <div id="dashboard-time-bar" class="pns-time-bar" role="img" aria-label="Time allocation unavailable"><span id="dashboard-run-bar" class="pns-segment-running"></span><span id="dashboard-unplanned-bar" class="pns-segment-stopped"></span><span id="dashboard-planned-bar" class="pns-segment-planned"></span></div>
      <div class="pns-legend"><span><i class="pns-segment-running"></i>Running <b id="dashboard-running-share">—</b></span><span><i class="pns-segment-stopped"></i>Unplanned <b id="dashboard-stopped-share">—</b></span><span><i class="pns-segment-planned"></i>Planned <b id="dashboard-planned-share">—</b></span></div>
    </div></section>
  </div>
  <div class="pns-section-heading"><h2>Live operations</h2><span>Latest records in this reporting window</span></div>
  <div class="pns-operations">
    <section class="dash-card oee-card pns-reason-card" data-permission-page="downtime" hidden><div class="dash-card-head"><h3>Downtime by reason</h3><a href="#/downtime" data-goto="downtime" data-dashboard-link="downtime" class="link">Explore reasons →</a></div><div id="dashboard-reasons" class="pns-reasons"><p class="pns-empty">Loading downtime reasons…</p></div><p class="pns-card-foot">Overlapping stops count once per line. Unplanned takes precedence; confirmed/approved reasons take precedence over proposals.</p></section>
    <section class="dash-card oee-card" data-permission-page="downtime" hidden><div class="dash-card-head"><h3>Recent downtime events</h3><a href="#/downtime" data-goto="downtime" data-dashboard-link="downtime" class="link">Full log →</a></div><div class="data-scroll"><table class="data-table compact-table"><thead><tr><th>Line / end</th><th>Reason</th><th>Source</th><th>Status</th></tr></thead><tbody id="dashboard-stops-body"><tr><td colspan="4">Loading downtime…</td></tr></tbody></table></div></section>
    <p id="dashboard-detail-message" class="pns-empty" hidden>Detailed operations are restricted by your role. Aggregate production and time-loss KPIs remain available above.</p>
  </div>
  <section class="pns-batches-section" data-permission-page="oee-report" hidden>
    <div class="pns-section-heading"><h2>Production batches</h2><div class="pns-batches-actions"><span>Latest flight bars in this reporting window</span><button class="btn ghost" id="dashboard-export" type="button" data-permission-page="oee-report" data-permission-action="export" title="Export all batches and OEE calculations for the selected reporting window" hidden>Export Excel</button></div></div>
    <p id="dashboard-export-alert" class="oee-alert" role="alert" hidden></p>
    <div class="dash-card oee-card"><div class="dash-card-head"><h3>Recent batches</h3><a href="#/oee-report" data-goto="oee-report" data-dashboard-link="report" class="link">All batches →</a></div><div class="data-scroll"><table class="data-table compact-table"><thead><tr><th>Line / part</th><th>Grade / colour</th><th>PCS</th><th>Total SA</th><th>State</th></tr></thead><tbody id="dashboard-batches-body"><tr><td colspan="5">Loading batches…</td></tr></tbody></table></div></div>
  </section>
  <p class="pns-review-note">Automatic gap and empty-rack stops are estimates until reviewed. KPI values use the current calculation rules and the selected reporting window.</p>
  <details class="pns-sync-details"><summary>Synchronization details & recent imports</summary><div class="oee-grid oee-grid-bottom"><section class="dash-card oee-card"><div class="dash-card-head"><h3>Recent imports</h3></div><div class="data-scroll"><table class="data-table compact-table"><thead><tr><th>Batch</th><th>Date</th><th>Rows</th><th>Status</th><th>Received</th></tr></thead><tbody id="oee-imports-body"></tbody></table></div></section><section class="dash-card oee-card"><div class="dash-card-head"><h3>Sync health</h3></div><div id="oee-sync-list" class="oee-sync-list"></div></section></div></details>
  <p class="pns-dashboard-footer">PNS Anodizing · SCADA reporting data · Refresh to load the latest reporting window</p>
  <dialog id="dashboard-tag-dialog" class="pns-tag-dialog" aria-labelledby="dashboard-tag-title" aria-describedby="dashboard-tag-context dashboard-tag-help">
    <form id="dashboard-tag-form">
      <h2 id="dashboard-tag-title">Tag downtime reason</h2>
      <p id="dashboard-tag-context" class="pns-tag-context"></p>
      <p id="dashboard-tag-help" class="pns-tag-help">The original interval and SCADA source are preserved. Saving confirms a proposed stop; other review statuses remain unchanged.</p>
      <p id="dashboard-tag-error" class="oee-alert" role="alert" hidden></p>
      <div class="pns-tag-fields">
        <label class="form-label" for="dashboard-tag-type">Type<select class="form-select" id="dashboard-tag-type" required><option value="unplanned">Unplanned</option><option value="planned">Planned</option></select></label>
        <label class="form-label" for="dashboard-tag-reason">Reason<select class="form-select" id="dashboard-tag-reason" required autofocus></select></label>
        <label class="form-label pns-tag-notes" for="dashboard-tag-notes">Notes<textarea class="form-textarea" id="dashboard-tag-notes" maxlength="500" rows="3"></textarea></label>
      </div>
      <div class="pns-tag-actions"><button type="button" class="btn ghost" id="dashboard-tag-cancel">Cancel</button><button type="submit" class="btn" id="dashboard-tag-save" disabled>Save reason</button></div>
    </form>
  </dialog>
</section>
