(function () {
  const app = { csrf: "", user: null, users: [], records: [], isAdmin: false, currentRecord: null, oee: null, reportRows: [], reportDate: "", reportData: null, downtimeEditId: null, durationMinutes: "1440", windowStart: "00:00", dashboardRequest: 0, reportRequest: 0 };
  const apiUrl = "api.php";

  function toast(message, kind) {
    if (window.AppToast) window.AppToast.show(message, kind || "");
  }

  async function request(resource, action, options) {
    const settings = options || {};
    const headers = { Accept: "application/json" };
    if (settings.body) headers["Content-Type"] = "application/json";
    if (settings.method && settings.method !== "GET") headers["X-CSRF-Token"] = app.csrf;
    const query = settings.query || {};
    const queryString = Object.keys(query).map(function (key) { return "&" + encodeURIComponent(key) + "=" + encodeURIComponent(query[key]); }).join("");
    const response = await fetch(apiUrl + "?resource=" + encodeURIComponent(resource || "") + "&action=" + encodeURIComponent(action || "") + queryString, {
      method: settings.method || "GET",
      credentials: "same-origin",
      headers: headers,
      body: settings.body ? JSON.stringify(settings.body) : undefined,
    });
    let payload = {};
    try { payload = await response.json(); } catch (error) { payload = {}; }
    if (!response.ok) throw new Error(payload.error || "The request could not be completed.");
    return payload;
  }

  function makeCell(row, value, className) {
    const cell = document.createElement("td");
    if (className) cell.className = className;
    cell.textContent = value == null ? "" : String(value);
    row.appendChild(cell);
    return cell;
  }

  function makeLink(label, href, className) {
    const link = document.createElement("a");
    link.href = href;
    link.textContent = label;
    if (className) link.className = className;
    return link;
  }

  function readableDate(value) {
    if (!value) return "—";
    const date = new Date(String(value).replace(" ", "T") + (String(value).includes("Z") ? "" : "Z"));
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleDateString();
  }

  function readableDateTime(value) {
    if (!value) return "—";
    return String(value).replace("T", " ").slice(0, 16);
  }

  function percent(value) {
    if (value === null || value === undefined || value === "") return "—";
    return (Number(value) * 100).toFixed(1) + "%";
  }

  function setText(id, value) {
    const node = document.getElementById(id);
    if (node) node.textContent = value == null || value === "" ? "—" : String(value);
  }

  function showOeeAlert(id, message, visible) {
    const node = document.getElementById(id);
    if (!node) return;
    node.hidden = !visible;
    node.textContent = message || "";
  }

  function oeeWindowQuery(prefix) {
    app.durationMinutes = document.getElementById(prefix + "-duration")?.value || app.durationMinutes;
    app.windowStart = app.durationMinutes === "1440" ? "00:00" : (document.getElementById(prefix + "-start-time")?.value || "00:00");
    ["dashboard", "report"].forEach(function (name) {
      const duration = document.getElementById(name + "-duration");
      const start = document.getElementById(name + "-start-time");
      if (duration) duration.value = app.durationMinutes;
      if (start) { start.value = app.windowStart; start.disabled = app.durationMinutes === "1440"; }
    });
    return { duration_minutes: app.durationMinutes, start_time: app.windowStart };
  }

  function renderOeeWindow(prefix, data) {
    const window = data.window || {};
    setText(prefix + "-window", window.window_start ? window.duration_minutes + " min (" + window.duration_hours + "h) · " + readableDateTime(window.window_start) + " → " + readableDateTime(window.window_end) : "");
  }

  function oeeQualityWarning(snapshot) {
    let flags = snapshot.calculation_flags || {};
    if (typeof flags === "string") { try { flags = JSON.parse(flags); } catch (error) { flags = {}; } }
    return flags.shift_quality_not_entered ? "12h quality is missing for one or more selected lines; zero quality losses are assumed. Daily quality totals are not applied to this window." : "";
  }

  function renderSyncList(targetId, sync) {
    const root = document.getElementById(targetId);
    if (!root) return;
    root.replaceChildren();
    if (!sync || sync.length === 0) {
      root.textContent = "No synchronization has been received yet.";
      return;
    }
    sync.forEach(function (item) {
      const row = document.createElement("div");
      row.className = "oee-sync-row";
      const left = document.createElement("div");
      const source = document.createElement("strong");
      source.textContent = item.source || "Unknown source";
      const detail = document.createElement("span");
      detail.textContent = (item.last_batch_id || "No batch") + " · " + (item.last_report_date || "No report date");
      left.append(source, detail);
      const state = document.createElement("span");
      state.className = "status-pill " + (item.last_status === "ok" ? "ok" : "crit");
      state.textContent = item.last_status === "ok" ? "Healthy" : "Failed";
      row.append(left, state);
      root.appendChild(row);
    });
  }

  function renderDashboard(data) {
    app.oee = data;
    const dateInput = document.getElementById("dashboard-date");
    if (dateInput && data.date && !dateInput.value) dateInput.value = data.date;
    if (!data.available) {
      showOeeAlert("oee-dashboard-alert", data.message, true);
      setText("oee-status-text", "OEE database is not connected");
      return;
    }
    showOeeAlert("oee-dashboard-alert", "", false);
    const metrics = data.metrics || {};
    const snapshot = data.oee || {};
    renderOeeWindow("dashboard", data);
    let calculationFlags = snapshot.calculation_flags || {};
    if (typeof calculationFlags === "string") { try { calculationFlags = JSON.parse(calculationFlags); } catch (error) { calculationFlags = {}; } }
    const checks = [];
    if (calculationFlags.missing_cycle_time) {
      const colours = Array.isArray(calculationFlags.missing_cycle_colours) && calculationFlags.missing_cycle_colours.length ? " Missing: " + calculationFlags.missing_cycle_colours.join(", ") + "." : "";
      checks.push("Cycle time is missing; default 12 min/FB is being used." + colours);
    }
    if (calculationFlags.performance_above_100) checks.push("Performance is above 100%; verify cycle time and downtime.");
    if (oeeQualityWarning(snapshot)) checks.push(oeeQualityWarning(snapshot));
    showOeeAlert("oee-dashboard-alert", checks.join(" "), checks.length > 0);
    setText("oee-value", percent(snapshot.oee));
    setText("availability-value", percent(snapshot.availability));
    setText("performance-value", percent(snapshot.performance));
    setText("quality-value", percent(snapshot.quality));
    setText("total-fb", metrics.total_fb || 0);
    setText("productive-fb", metrics.productive_fb || 0);
    setText("empty-fb", metrics.empty_fb || 0);
    setText("total-pcs", Number(metrics.pcs || 0).toLocaleString());
    setText("total-area", Number(metrics.total_surface_area || 0).toFixed(2));
    setText("oee-report-date", data.date);
    setText("planned-minutes", snapshot.planned_stop_minutes == null ? "—" : Number(snapshot.planned_stop_minutes).toFixed(1) + " min");
    setText("unplanned-minutes", snapshot.unplanned_minutes == null ? "—" : Number(snapshot.unplanned_minutes).toFixed(1) + " min");
    setText("run-minutes", snapshot.run_minutes == null ? "—" : Number(snapshot.run_minutes).toFixed(1) + " min");
    setText("utilization-value", percent(snapshot.utilization_window ?? snapshot.utilization_24h));
    setText("utilization-label", (data.window?.duration_hours || 24) + "h utilization");
    const sync = data.sync || [];
    const latest = sync[0];
    setText("oee-status-text", latest && latest.last_status === "ok" ? "Unit 1 sync is healthy" : "Waiting for Unit 1 sync");
    setText("oee-last-sync", latest && latest.last_received_at ? "Last received " + readableDateTime(latest.last_received_at) : "No sync received");
    const dot = document.getElementById("oee-status-dot");
    if (dot) dot.className = "status-dot " + (latest && latest.last_status === "ok" ? "ok" : "warn");
    renderSyncList("oee-sync-list", sync);
    const importBody = document.getElementById("oee-imports-body");
    if (importBody) {
      importBody.replaceChildren();
      (data.imports || []).forEach(function (item) {
        const row = document.createElement("tr");
        makeCell(row, item.batch_id, "mono"); makeCell(row, item.report_date); makeCell(row, item.row_count); makeCell(row, item.status); makeCell(row, readableDateTime(item.received_at));
        importBody.appendChild(row);
      });
      if (!data.imports || data.imports.length === 0) { const row = document.createElement("tr"); const cell = makeCell(row, "No imports received yet."); cell.colSpan = 5; importBody.appendChild(row); }
    }
  }

  function renderReportRows(rows) {
    const body = document.getElementById("oee-report-body");
    if (!body) return;
    body.replaceChildren();
    const search = (document.getElementById("report-search")?.value || "").trim().toLowerCase();
    const grade = document.getElementById("report-grade-filter")?.value || "";
    const colour = document.getElementById("report-colour-filter")?.value || "";
    const state = document.getElementById("report-state-filter")?.value || "";
    const filtered = (rows || []).filter(function (item) {
      const text = [item.seq, item.part_code, item.used_colour, item.report_colour].join(" ").toLowerCase();
      const itemState = Number(item.is_productive) === 1 ? "productive" : "empty";
      return (!search || text.includes(search)) && (!grade || item.grade === grade) && (!colour || (item.used_colour || item.report_colour) === colour) && (!state || itemState === state);
    });
    filtered.forEach(function (item) {
      const row = document.createElement("tr");
      makeCell(row, item.line_code || "—"); makeCell(row, item.si_no); makeCell(row, item.seq, "mono"); makeCell(row, item.part_code); makeCell(row, item.grade); makeCell(row, item.used_colour || item.report_colour); makeCell(row, item.loading_at); makeCell(row, item.unloading_at); makeCell(row, item.pcs); makeCell(row, item.total_surface_area == null ? "—" : Number(item.total_surface_area).toFixed(2)); makeCell(row, Number(item.is_productive) === 1 ? "Productive" : "Empty");
      body.appendChild(row);
    });
    if (filtered.length === 0) { const row = document.createElement("tr"); const cell = makeCell(row, "No rows match these filters."); cell.colSpan = 11; body.appendChild(row); }
    const data = app.reportData || {};
    const snapshot = data.oee || {};
    const kpis = snapshot.oee == null ? "" : " · OEE " + percent(snapshot.oee) + " · Availability " + percent(snapshot.availability) + " · Performance " + percent(snapshot.performance) + " · Quality " + percent(snapshot.quality);
    setText("report-summary", (app.reportDate || document.getElementById("report-date")?.value || "") + " · " + (data.machine === "ALL" ? "All lines" : (data.machine || "")) + " · " + filtered.length + " of " + (rows || []).length + " flight bars shown" + kpis);
  }

  function populateReportFilters(rows) {
    ["report-grade-filter", "report-colour-filter"].forEach(function (id) {
      const select = document.getElementById(id);
      if (!select) return;
      const current = select.value;
      const values = Array.from(new Set((rows || []).map(function (item) { return id.includes("grade") ? item.grade : (item.used_colour || item.report_colour); }).filter(Boolean))).sort();
      select.replaceChildren(new Option(id.includes("grade") ? "All grades" : "All colours", ""));
      values.forEach(function (value) { select.appendChild(new Option(value, value)); });
      select.value = values.includes(current) ? current : "";
    });
  }

  function renderReport(data) {
    const dateInput = document.getElementById("report-date");
    if (dateInput && data.date && !dateInput.value) dateInput.value = data.date;
    if (!data.available) { showOeeAlert("oee-report-alert", data.message, true); return; }
    const qualityWarning = oeeQualityWarning(data.oee || {});
    showOeeAlert("oee-report-alert", qualityWarning, Boolean(qualityWarning));
    renderOeeWindow("report", data);
    app.reportDate = data.date || "";
    app.reportData = data;
    app.reportRows = data.rows || [];
    setText("report-oee-value", percent((data.oee || {}).oee));
    setText("report-availability-value", percent((data.oee || {}).availability));
    setText("report-performance-value", percent((data.oee || {}).performance));
    setText("report-quality-value", percent((data.oee || {}).quality));
    const lineBody = document.getElementById("report-line-metrics-body");
    if (lineBody) {
      lineBody.replaceChildren();
      (data.line_metrics || []).forEach(function (item) {
        const row = document.createElement("tr");
        makeCell(row, item.line_code); makeCell(row, percent(item.oee)); makeCell(row, percent(item.availability)); makeCell(row, percent(item.performance)); makeCell(row, percent(item.quality)); makeCell(row, percent(item.utilization_window ?? item.utilization_24h));
        lineBody.appendChild(row);
      });
    }
    populateReportFilters(app.reportRows);
    renderReportRows(app.reportRows);
  }

  async function exportReportExcel() {
    if (!app.reportData) { showOeeAlert("oee-report-alert", "Load a report before exporting.", true); return; }
    try {
    const date = document.getElementById("report-date")?.value || app.reportDate || "";
    const machine = document.getElementById("report-machine-filter")?.value || "ALL";
    const selection = oeeWindowQuery("report");
    const response = await fetch(apiUrl + "?resource=oee&action=export&machine=" + encodeURIComponent(machine) + "&date=" + encodeURIComponent(date) + "&duration_minutes=" + selection.duration_minutes + "&start_time=" + encodeURIComponent(selection.start_time), { credentials: "same-origin", headers: { Accept: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" } });
    if (!response.ok) { let message = "Excel export failed."; try { const payload = await response.json(); message = payload.error || message; } catch (error) {} showOeeAlert("oee-report-alert", message, true); return; }
    const blob = await response.blob();
    const signature = new Uint8Array(await blob.slice(0, 4).arrayBuffer());
    if (!response.headers.get("Content-Type")?.includes("application/vnd.openxmlformats-officedocument.spreadsheetml.sheet") || signature.length !== 4 || signature[0] !== 80 || signature[1] !== 75 || signature[2] !== 3 || signature[3] !== 4) {
      throw new Error("The server did not return a valid Excel workbook. Refresh the page and try again.");
    }
    const link = document.createElement("a"); link.href = URL.createObjectURL(blob); link.download = "pns-oee-" + date + "-" + machine + "-" + selection.duration_minutes + "min-" + selection.start_time.replace(":", "") + ".xlsx"; link.click(); setTimeout(function () { URL.revokeObjectURL(link.href); }, 1000);
    } catch (error) {
      showOeeAlert("oee-report-alert", error.message || "Excel export failed. Please try again.", true);
    }
  }

  function renderLogs(data) {
    if (!data.available) { showOeeAlert("oee-logs-alert", data.message, true); return; }
    showOeeAlert("oee-logs-alert", "", false);
    renderSyncList("scada-sync-body", data.sync || []);
    const body = document.getElementById("scada-logs-body");
    if (!body) return;
    body.replaceChildren();
    (data.imports || []).forEach(function (item) {
      const row = document.createElement("tr");
      makeCell(row, readableDateTime(item.received_at)); makeCell(row, item.source); makeCell(row, item.batch_id, "mono"); makeCell(row, item.report_date); makeCell(row, item.row_count); makeCell(row, item.status); makeCell(row, item.error_message || "—");
      body.appendChild(row);
    });
    if (!data.imports || data.imports.length === 0) { const row = document.createElement("tr"); const cell = makeCell(row, "No SCADA import events yet."); cell.colSpan = 7; body.appendChild(row); }
  }

  async function loadOeeDashboard() {
    const date = document.getElementById("dashboard-date")?.value || "";
    const machine = document.getElementById("dashboard-machine")?.value || "ALL";
    const query = oeeWindowQuery("dashboard");
    const requestId = ++app.dashboardRequest;
    setText("dashboard-window", "Calculating " + query.duration_minutes + " min window…");
    try { const data = await request("oee", "dashboard", { query: { machine: machine, ...query, ...(date ? { date: date } : {}) } }); if (requestId === app.dashboardRequest) renderDashboard(data); }
    catch (error) { if (requestId === app.dashboardRequest) showOeeAlert("oee-dashboard-alert", error.message, true); }
  }

  async function loadOeeReport() {
    const date = document.getElementById("report-date")?.value || document.getElementById("dashboard-date")?.value || "";
    const machine = document.getElementById("report-machine-filter")?.value || "APP-06";
    const query = oeeWindowQuery("report");
    const requestId = ++app.reportRequest;
    setText("report-window", "Calculating " + query.duration_minutes + " min window…");
    try { const data = await request("oee", "report", { query: { machine: machine, ...query, ...(date ? { date: date } : {}) } }); if (requestId === app.reportRequest) renderReport(data); }
    catch (error) { if (requestId === app.reportRequest) showOeeAlert("oee-report-alert", error.message, true); }
  }

  function renderDowntime(data) {
    const body = document.getElementById("downtime-body");
    if (!body) return;
    body.replaceChildren();
    (data.events || []).forEach(function (item) {
      const row = document.createElement("tr");
      makeCell(row, readableDateTime(item.starts_at)); makeCell(row, readableDateTime(item.ends_at)); makeCell(row, item.event_type); makeCell(row, item.reason_code); makeCell(row, item.source); makeCell(row, item.status); makeCell(row, item.notes || "—");
      const actionCell = makeCell(row, "");
      if (item.status === "proposed" || item.status === "confirmed") {
        const tag = document.createElement("button");
        tag.type = "button"; tag.className = "link"; tag.textContent = item.status === "proposed" ? "Tag" : "Edit"; tag.dataset.tagDowntime = String(item.id); tag.dataset.startsAt = item.starts_at || ""; tag.dataset.endsAt = item.ends_at || ""; tag.dataset.eventType = item.event_type || "unplanned"; tag.dataset.reasonCode = item.reason_code || "UNK"; tag.dataset.notes = item.notes || "";
        actionCell.appendChild(tag);
      }
      body.appendChild(row);
    });
    if (!data.events || data.events.length === 0) { const row = document.createElement("tr"); const cell = makeCell(row, "No downtime tagged for this machine and date yet."); cell.colSpan = 8; body.appendChild(row); }
  }

  async function loadDowntime() {
    const dateControl = document.getElementById("downtime-date");
    if (dateControl && !dateControl.value) dateControl.value = document.getElementById("dashboard-date")?.value || new Date().toISOString().slice(0, 10);
    const date = dateControl?.value || "";
    const machine = document.getElementById("downtime-machine")?.value || "APP-06";
    try { renderDowntime(await request("oee", "downtime", { query: { machine: machine, ...(date ? { date: date } : {}) } })); renderMasters(await request("oee", "masters", {})); }
    catch (error) { showOeeAlert("downtime-alert", error.message, true); }
  }

  function renderMasters(data) {
    const reasonsBody = document.getElementById("reasons-body");
    const cyclesBody = document.getElementById("cycles-body");
    if (reasonsBody) {
      reasonsBody.replaceChildren();
      (data.reasons || []).forEach(function (item) { const row = document.createElement("tr"); makeCell(row, item.code, "mono"); makeCell(row, item.label); makeCell(row, Number(item.is_active) === 1 ? "Active" : "Inactive"); const cell = makeCell(row, ""); const button = document.createElement("button"); button.type = "button"; button.className = "link"; button.textContent = "Edit"; button.dataset.editReason = JSON.stringify(item); cell.appendChild(button); reasonsBody.appendChild(row); });
      const downtimeReason = document.getElementById("downtime-reason");
      if (downtimeReason && data.reasons) { const selected = downtimeReason.value; downtimeReason.replaceChildren(); data.reasons.filter(function (item) { return Number(item.is_active) === 1; }).forEach(function (item) { downtimeReason.appendChild(new Option(item.label, item.code)); }); downtimeReason.value = selected; }
    }
    if (cyclesBody) {
      cyclesBody.replaceChildren();
      (data.cycles || []).forEach(function (item) { const row = document.createElement("tr"); makeCell(row, item.line_code); makeCell(row, item.colour); makeCell(row, Number(item.minutes_per_fb).toFixed(3)); makeCell(row, item.effective_from); makeCell(row, item.effective_to || "—"); const cell = makeCell(row, ""); const button = document.createElement("button"); button.type = "button"; button.className = "link"; button.textContent = "Edit"; button.dataset.editCycle = JSON.stringify(item); cell.appendChild(button); cyclesBody.appendChild(row); });
    }
  }

  async function loadMasters() {
    try { renderMasters(await request("oee", "masters", {})); showOeeAlert("masters-alert", "", false); }
    catch (error) { showOeeAlert("masters-alert", error.message, true); }
  }

  async function loadScadaLogs() {
    try { renderLogs(await request("oee", "logs", {})); }
    catch (error) { showOeeAlert("oee-logs-alert", error.message, true); }
  }

  function renderRecords() {
    const body = document.getElementById("records-body");
    if (!body) return;
    body.replaceChildren();
    const search = (document.getElementById("records-search")?.value || "").trim().toLowerCase();
    const type = document.getElementById("record-type-filter")?.value || "";
    const status = document.getElementById("record-status-filter")?.value || "";
    const visibleRecords = app.records.filter(function (record) {
      const matchesSearch = !search || (record.record_number + " " + record.title + " " + record.owner_name).toLowerCase().includes(search);
      return matchesSearch && (!type || record.record_type === type) && (!status || record.status === status);
    });
    visibleRecords.forEach(function (record) {
      const row = document.createElement("tr");
      makeCell(row, record.record_number, "mono");
      makeCell(row, record.title);
      makeCell(row, record.owner_name);
      makeCell(row, record.record_type);
      makeCell(row, record.status);
      makeCell(row, readableDate(record.created_at));
      makeCell(row, record.status === "Closed" ? readableDate(record.updated_at) : "—");
      const actions = makeCell(row, "", "r");
      const group = document.createElement("div");
      group.className = "row-actions";
      const view = makeLink("View", "#/detail/" + encodeURIComponent(record.id), "link");
      view.dataset.recordId = String(record.id);
      group.appendChild(view);
      const remove = document.createElement("button");
      remove.type = "button";
      remove.className = "link";
      remove.textContent = "Delete";
      remove.dataset.deleteRecord = String(record.id);
      group.appendChild(remove);
      actions.appendChild(group);
      body.appendChild(row);
    });
    if (app.records.length === 0) {
      const row = document.createElement("tr");
      const cell = makeCell(row, "No records yet. Create your first record to get started.");
      cell.colSpan = 8;
      body.appendChild(row);
    } else if (visibleRecords.length === 0) {
      const row = document.createElement("tr");
      const cell = makeCell(row, "No records match these filters.");
      cell.colSpan = 8;
      body.appendChild(row);
    }
    const recordCount = document.getElementById("record-count");
    if (recordCount) recordCount.textContent = String(app.records.length);
    const dashboardBody = document.getElementById("dashboard-records-body");
    if (dashboardBody) {
      dashboardBody.replaceChildren();
      app.records.slice(0, 5).forEach(function (record) {
        const row = document.createElement("tr");
        makeCell(row, record.record_number, "mono");
        makeCell(row, record.title);
        makeCell(row, record.record_type);
        makeCell(row, record.status);
        makeCell(row, readableDate(record.created_at));
        dashboardBody.appendChild(row);
      });
    }
  }

  function renderUsers(users) {
    const body = document.getElementById("users-body");
    if (!body) return;
    body.replaceChildren();
    const search = (document.getElementById("users-search")?.value || "").trim().toLowerCase();
    const visibleUsers = users.filter(function (user) {
      return !search || (user.username + " " + user.full_name + " " + user.email).toLowerCase().includes(search);
    });
    visibleUsers.forEach(function (user) {
      const row = document.createElement("tr");
      makeCell(row, user.id, "mono");
      makeCell(row, user.username);
      makeCell(row, user.full_name);
      makeCell(row, user.email);
      makeCell(row, user.role);
      makeCell(row, Number(user.is_active) === 1 ? "Active" : "Inactive");
      makeCell(row, readableDate(user.created_at), "muted");
      const actions = makeCell(row, "", "r");
      const toggle = document.createElement("button");
      toggle.type = "button";
      toggle.className = "link";
      toggle.textContent = Number(user.is_active) === 1 ? "Deactivate" : "Activate";
      toggle.dataset.toggleUser = String(user.id);
      actions.appendChild(toggle);
      body.appendChild(row);
    });
    if (visibleUsers.length === 0) {
      const row = document.createElement("tr");
      const cell = makeCell(row, "No users match this search.");
      cell.colSpan = 8;
      body.appendChild(row);
    }
  }

  function setDetail(record) {
    app.currentRecord = record;
    const values = {
      "detail-number": record.record_number,
      "detail-type": record.record_type,
      "detail-organization": record.organization,
      "detail-category": record.category,
      "detail-owner": record.owner_name,
      "detail-title": record.title,
      "detail-description": record.description || "—",
      "detail-status": record.status,
    };
    Object.keys(values).forEach(function (id) {
      const target = document.getElementById(id);
      if (target) target.textContent = values[id] || "—";
    });
    const edit = document.getElementById("record-edit-form");
    if (edit) {
      edit.elements.title.value = record.title;
      edit.elements.description.value = record.description || "";
      edit.hidden = true;
    }
  }

  function updateIdentity(user) {
    const initials = user.full_name.trim().split(/\s+/).slice(0, 2).map(function (part) { return part.charAt(0); }).join("").toUpperCase();
    document.querySelectorAll(".app-header-profile-name").forEach(function (node) { node.textContent = user.full_name; });
    document.querySelectorAll(".app-header-profile-user").forEach(function (node) { node.textContent = user.username; });
    document.querySelectorAll(".app-header-avatar").forEach(function (node) { node.textContent = initials; });
    const name = document.getElementById("profile-name");
    const email = document.getElementById("profile-email");
    const username = document.getElementById("profile-username");
    if (name) name.value = user.full_name;
    if (email) email.value = user.email;
    if (username) username.value = user.username;
  }

  async function refresh() {
    const data = await request("bootstrap", "", {});
    app.csrf = data.csrf_token;
    app.user = data.user;
    app.records = data.records;
    app.isAdmin = data.is_admin;
    updateIdentity(app.user);
    renderRecords();
    const detailMatch = location.hash.match(/^#\/detail\/(\d+)$/);
    if (detailMatch) {
      const selected = app.records.find(function (record) { return Number(record.id) === Number(detailMatch[1]); });
      if (selected) setDetail(selected);
    }
    const userCount = document.getElementById("user-count");
    if (userCount && data.user_count !== null) userCount.textContent = String(data.user_count);
    document.querySelectorAll('[data-nav="users"], [data-nav="roles"]').forEach(function (link) {
      link.hidden = !app.isAdmin;
    });
    const addUser = document.querySelector('[data-goto="user-create"]');
    if (addUser) addUser.hidden = !app.isAdmin;
    if (app.isAdmin) {
      const users = await request("users", "", {});
      app.users = users.users;
      renderUsers(users.users);
    } else {
      const usersCard = document.querySelector('[data-goto="users"]');
      if (usersCard) usersCard.hidden = true;
      if (location.hash === "#/users" || location.hash === "#/user-create") location.hash = "#/dashboard";
    }
    await loadOeeDashboard();
  }

  async function submitForm(form, action, fields, success, resource) {
    const body = {};
    fields.forEach(function (field) { body[field] = form.elements[field].value; });
    if (action === "record-update" || action === "record-delete") body.id = app.currentRecord.id;
    try {
      await request(resource || "", action, { method: "POST", body: body });
      toast(success, "ok");
      if (form.id === "password-form") form.reset();
      else if (form.id === "record-create-form" || form.id === "user-create-form") form.reset();
      await refresh();
      if (action === "record-update" && app.currentRecord) {
        const updatedRecord = app.records.find(function (record) { return Number(record.id) === Number(app.currentRecord.id); });
        if (updatedRecord) setDetail(updatedRecord);
      }
      if (form.id === "record-create-form") location.hash = "#/requests";
      if (form.id === "user-create-form") location.hash = "#/users";
    } catch (error) {
      toast(error.message, "crit");
    }
  }

  const recordForm = document.getElementById("record-create-form");
  if (recordForm) recordForm.addEventListener("submit", function (event) {
    event.preventDefault();
    submitForm(recordForm, "record-create", ["record_type", "organization", "category", "priority", "title", "description"], "Record created", "records");
  });

  const profileForm = document.getElementById("profile-form");
  if (profileForm) profileForm.addEventListener("submit", function (event) {
    event.preventDefault();
    submitForm(profileForm, "profile", ["full_name"], "Profile updated");
  });

  const passwordForm = document.getElementById("password-form");
  if (passwordForm) passwordForm.addEventListener("submit", function (event) {
    event.preventDefault();
    submitForm(passwordForm, "password", ["current_password", "new_password"], "Password updated");
  });

  const userForm = document.getElementById("user-create-form");
  if (userForm) userForm.addEventListener("submit", function (event) {
    event.preventDefault();
    submitForm(userForm, "user-create", ["username", "email", "full_name", "password"], "User created", "users");
  });

  const recordFilter = document.getElementById("record-filter-form");
  if (recordFilter) recordFilter.addEventListener("submit", function (event) {
    event.preventDefault();
    renderRecords();
  });

  ["records-search", "record-type-filter", "record-status-filter"].forEach(function (id) {
    const control = document.getElementById(id);
    if (control) control.addEventListener("input", renderRecords);
    if (control && control.tagName === "SELECT") control.addEventListener("change", renderRecords);
  });

  const userFilter = document.getElementById("user-search-form");
  if (userFilter) userFilter.addEventListener("submit", function (event) {
    event.preventDefault();
    renderUsers(app.users);
  });
  const userSearch = document.getElementById("users-search");
  if (userSearch) userSearch.addEventListener("input", function () { renderUsers(app.users); });

  const dashboardRefresh = document.getElementById("dashboard-refresh");
  if (dashboardRefresh) dashboardRefresh.addEventListener("click", loadOeeDashboard);
  const dashboardDate = document.getElementById("dashboard-date");
  if (dashboardDate) dashboardDate.addEventListener("change", loadOeeDashboard);
  const dashboardMachine = document.getElementById("dashboard-machine");
  if (dashboardMachine) dashboardMachine.addEventListener("change", loadOeeDashboard);
  ["dashboard", "report"].forEach(function (prefix) {
    ["duration", "start-time"].forEach(function (suffix) {
      const control = document.getElementById(prefix + "-" + suffix);
      if (control) control.addEventListener("change", function () {
        oeeWindowQuery(prefix);
        if (prefix === "dashboard") loadOeeDashboard(); else loadOeeReport();
      });
    });
  });
  const reportLoad = document.getElementById("report-load");
  if (reportLoad) reportLoad.addEventListener("click", loadOeeReport);
  const reportDateControl = document.getElementById("report-date");
  if (reportDateControl) reportDateControl.addEventListener("change", loadOeeReport);
  const reportExport = document.getElementById("report-export");
  if (reportExport) reportExport.addEventListener("click", exportReportExcel);
  const reportMachine = document.getElementById("report-machine-filter");
  if (reportMachine) reportMachine.addEventListener("change", loadOeeReport);
  ["report-search", "report-grade-filter", "report-colour-filter", "report-state-filter"].forEach(function (id) {
    const control = document.getElementById(id);
    if (control) control.addEventListener("input", function () { renderReportRows(app.reportRows); });
    if (control && control.tagName === "SELECT") control.addEventListener("change", function () { renderReportRows(app.reportRows); });
  });
  const downtimeRefresh = document.getElementById("downtime-refresh");
  if (downtimeRefresh) downtimeRefresh.addEventListener("click", loadDowntime);
  ["downtime-date", "downtime-machine"].forEach(function (id) { const control = document.getElementById(id); if (control) control.addEventListener("change", loadDowntime); });
  const downtimeForm = document.getElementById("downtime-form");
  if (downtimeForm) downtimeForm.addEventListener("submit", async function (event) {
    event.preventDefault();
    try {
      await request("oee", "downtime-save", { method: "POST", body: { event_id: app.downtimeEditId, machine: document.getElementById("downtime-machine").value, report_date: document.getElementById("downtime-date").value, starts_at: document.getElementById("downtime-start").value, ends_at: document.getElementById("downtime-end").value, event_type: document.getElementById("downtime-type").value, reason_code: document.getElementById("downtime-reason").value, notes: document.getElementById("downtime-notes").value } });
      toast(app.downtimeEditId ? "Downtime tagged" : "Downtime saved", "ok"); app.downtimeEditId = null; downtimeForm.reset(); await loadDowntime();
    } catch (error) { showOeeAlert("downtime-alert", error.message, true); }
  });
  const mastersRefresh = document.getElementById("masters-refresh");
  if (mastersRefresh) mastersRefresh.addEventListener("click", loadMasters);
  const reasonForm = document.getElementById("reason-form");
  if (reasonForm) reasonForm.addEventListener("submit", async function (event) { event.preventDefault(); try { await request("oee", "master-save", { method: "POST", body: { master_type: "reason", id: Number(document.getElementById("reason-id").value) || null, code: document.getElementById("reason-code").value, label: document.getElementById("reason-label").value } }); toast("Reason saved", "ok"); reasonForm.reset(); await loadMasters(); } catch (error) { showOeeAlert("masters-alert", error.message, true); } });
  const cycleForm = document.getElementById("cycle-form");
  if (cycleForm) cycleForm.addEventListener("submit", async function (event) { event.preventDefault(); try { await request("oee", "master-save", { method: "POST", body: { master_type: "cycle", id: Number(document.getElementById("cycle-id").value) || null, line_code: document.getElementById("cycle-line").value, colour: document.getElementById("cycle-colour").value, minutes_per_fb: document.getElementById("cycle-minutes").value, effective_from: document.getElementById("cycle-from").value, effective_to: document.getElementById("cycle-to").value } }); toast("Cycle time saved", "ok"); cycleForm.reset(); await loadMasters(); } catch (error) { showOeeAlert("masters-alert", error.message, true); } });
  document.addEventListener("click", function (event) {
    const reason = event.target.closest("[data-edit-reason]");
    if (reason) { const item = JSON.parse(reason.dataset.editReason); document.getElementById("reason-id").value = item.id; document.getElementById("reason-code").value = item.code; document.getElementById("reason-label").value = item.label; }
    const cycle = event.target.closest("[data-edit-cycle]");
    if (cycle) { const item = JSON.parse(cycle.dataset.editCycle); document.getElementById("cycle-id").value = item.id; document.getElementById("cycle-line").value = item.line_code; document.getElementById("cycle-colour").value = item.colour; document.getElementById("cycle-minutes").value = item.minutes_per_fb; document.getElementById("cycle-from").value = item.effective_from; document.getElementById("cycle-to").value = item.effective_to || ""; }
  });
  document.addEventListener("click", function (event) {
    const tag = event.target.closest("[data-tag-downtime]");
    if (!tag) return;
    app.downtimeEditId = Number(tag.dataset.tagDowntime);
    const toLocal = function (value) { return String(value || "").replace(" ", "T").slice(0, 16); };
    document.getElementById("downtime-start").value = toLocal(tag.dataset.startsAt);
    document.getElementById("downtime-end").value = toLocal(tag.dataset.endsAt);
    document.getElementById("downtime-type").value = tag.dataset.eventType;
    document.getElementById("downtime-reason").value = tag.dataset.reasonCode;
    document.getElementById("downtime-notes").value = tag.dataset.notes;
    document.getElementById("downtime-start").focus();
  });
  const logsRefresh = document.getElementById("logs-refresh");
  if (logsRefresh) logsRefresh.addEventListener("click", loadScadaLogs);
  window.addEventListener("hashchange", function () {
    const route = (location.hash || "#/dashboard").replace("#/", "").split("/")[0];
    if (route === "dashboard") loadOeeDashboard();
    if (route === "oee-report") loadOeeReport();
    if (route === "scada-logs") loadScadaLogs();
    if (route === "downtime") loadDowntime();
    if (route === "oee-masters") loadMasters();
  });
  document.addEventListener("click", function (event) {
    const navigation = event.target.closest("[data-nav], [data-goto]");
    if (!navigation) return;
    const route = navigation.getAttribute("data-nav") || navigation.getAttribute("data-goto");
    setTimeout(function () {
      if (route === "dashboard") loadOeeDashboard();
      if (route === "oee-report") loadOeeReport();
      if (route === "scada-logs") loadScadaLogs();
      if (route === "downtime") loadDowntime();
      if (route === "oee-masters") loadMasters();
    }, 0);
  });

  document.addEventListener("click", async function (event) {
    const view = event.target.closest("[data-record-id]");
    if (view) {
      const record = app.records.find(function (item) { return String(item.id) === view.dataset.recordId; });
      if (record) setDetail(record);
    }
    const remove = event.target.closest("[data-delete-record]");
    if (remove) {
      if (!window.confirm("Delete this record? This cannot be undone.")) return;
      try {
        await request("records", "record-delete", { method: "POST", body: { id: Number(remove.dataset.deleteRecord) } });
        toast("Record deleted", "ok");
        await refresh();
      } catch (error) { toast(error.message, "crit"); }
    }
    const toggle = event.target.closest("[data-toggle-user]");
    if (toggle) {
      try {
        await request("users", "user-toggle", { method: "POST", body: { id: Number(toggle.dataset.toggleUser) } });
        toast("User status updated", "ok");
        await refresh();
      } catch (error) { toast(error.message, "crit"); }
    }
    const logout = event.target.closest("[data-app-logout]");
    if (logout) {
      event.preventDefault();
      try {
        await request("", "logout", { method: "POST", body: {} });
        location.href = "index.php";
      } catch (error) { toast(error.message, "crit"); }
    }
  });

  const editToggle = document.getElementById("record-edit-toggle");
  if (editToggle) editToggle.addEventListener("click", function () {
    const form = document.getElementById("record-edit-form");
    if (form && app.currentRecord) form.hidden = !form.hidden;
  });

  const editForm = document.getElementById("record-edit-form");
  if (editForm) editForm.addEventListener("submit", function (event) {
    event.preventDefault();
    submitForm(editForm, "record-update", ["title", "description"], "Record updated", "records");
  });

  refresh().catch(function (error) {
    if (/sign in/i.test(error.message)) location.href = "index.php";
    else toast(error.message, "crit");
  });
  const initialRoute = (location.hash || "#/dashboard").replace("#/", "").split("/")[0];
  if (initialRoute === "oee-report") loadOeeReport();
  if (initialRoute === "scada-logs") loadScadaLogs();
  if (initialRoute === "downtime") loadDowntime();
  if (initialRoute === "oee-masters") loadMasters();
})();
