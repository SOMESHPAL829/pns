(function () {
  const node = id => document.getElementById(id);
  const text = (id, value) => { if (node(id)) node(id).textContent = value; };
  const number = value => value == null || value === "" || !Number.isFinite(Number(value)) ? null : Number(value);
  const pct = value => value === null ? "—" : (value * 100).toFixed(1) + "%";
  const minutes = value => value === null ? "—" : value.toFixed(1) + " min";
  let stopEvents = [], editingStop = null, tagRequest = 0, savingTag = false;
  function cell(row, value, detail) {
    const td = document.createElement("td"); td.textContent = value ?? "—";
    if (detail) { const small = document.createElement("span"); small.className = "pns-cell-detail"; small.textContent = detail; td.appendChild(small); }
    row.appendChild(td); return td;
  }
  function empty(body, columns, message) {
    body.replaceChildren(); const row = document.createElement("tr"); cell(row, message).colSpan = columns; body.appendChild(row);
  }
  function badge(td, label, status) {
    const span = document.createElement("span"); span.className = "status-pill " + status; span.textContent = label; td.appendChild(span);
  }
  function render(data) {
    const metrics = data.metrics || {}, oee = data.oee || {};
    const run = number(oee.run_minutes), planned = number(oee.planned_stop_minutes), stopped = number(oee.unplanned_minutes);
    const budget = number(oee.planned_minutes) !== null && planned !== null ? number(oee.planned_minutes) + planned : null;
    text("dashboard-rack-rate", Number(metrics.total_fb) > 0 ? pct(Number(metrics.productive_fb || 0) / Number(metrics.total_fb)) : "—");
    text("dashboard-oee-detail", (data.machine === "ALL" ? "All machines" : data.machine || "Selected machine") + " · " + (data.window?.duration_minutes || 1440) + " min per line");
    text("dashboard-availability-detail", run === null ? "No run-time total" : minutes(run) + " across " + (data.machine === "ALL" ? "3 lines" : "1 line"));
    text("dashboard-performance-detail", "Ideal output: " + minutes(number(oee.ideal_output_minutes)));
    text("dashboard-quality-detail", number(oee.good_fb) === null ? "No good-FB total" : Number(oee.good_fb).toLocaleString() + " good / " + Number(oee.productive_fb || 0).toLocaleString() + " productive FB");
    const descriptions = [];
    [["run", "running", run], ["unplanned", "stopped", stopped], ["planned", "planned", planned]].forEach(function ([id, label, value]) {
      const share = budget > 0 && value !== null ? value / budget : null;
      node("dashboard-" + id + "-bar").style.width = share === null ? "0%" : Math.max(0, Math.min(100, share * 100)) + "%";
      text("dashboard-" + label + "-share", pct(share)); descriptions.push(label + " " + pct(share));
    });
    node("dashboard-time-bar").setAttribute("aria-label", descriptions.join(", "));
    const reasons = node("dashboard-reasons"); reasons.replaceChildren();
    (data.downtime_breakdown?.items || []).forEach(function (item) {
      const root = document.createElement("div"); root.className = "pns-reason";
      const head = document.createElement("div"); head.className = "pns-reason-head";
      const label = document.createElement("span"); label.textContent = item.label + " · " + item.event_type;
      const value = document.createElement("strong"); value.textContent = Number(item.minutes).toFixed(1) + " min · " + Number(item.percentage).toFixed(1) + "%"; head.append(label, value);
      const track = document.createElement("div"); track.className = "pns-reason-track"; const bar = document.createElement("span"); bar.style.width = Math.max(0, Math.min(100, Number(item.percentage))) + "%"; track.appendChild(bar);
      root.append(head, track); reasons.appendChild(root);
    });
    if (!reasons.children.length) { const p = document.createElement("p"); p.className = "pns-empty"; p.textContent = "No counted downtime in this reporting window."; reasons.appendChild(p); }
    const batches = node("dashboard-batches-body"); batches.replaceChildren();
    (data.recent_batches || []).forEach(function (item) {
      const row = document.createElement("tr"); cell(row, item.part_code || item.seq || "—", item.line_code); cell(row, item.grade || "—", item.used_colour || item.report_colour || "No colour"); cell(row, Number(item.pcs || 0).toLocaleString()); cell(row, Number(item.total_surface_area || 0).toFixed(2));
      const state = Number(item.is_productive) !== 1 ? "Empty rack" : item.unloading_at ? "Completed" : "In process";
      badge(cell(row, ""), state, state === "Completed" ? "ok" : "proposed"); batches.appendChild(row);
    });
    if (!batches.children.length) empty(batches, 5, "No batches in this reporting window.");
    const stops = node("dashboard-stops-body"); stops.replaceChildren();
    stopEvents = data.recent_downtime || [];
    stopEvents.forEach(function (item) {
      const row = document.createElement("tr"); cell(row, String(item.ends_at || "—").slice(0, 16), item.line_code); cell(row, item.reason_label || item.reason_code || "Not yet classified"); cell(row, item.source);
      const statusCell = cell(row, "");
      badge(statusCell, item.status, ["approved", "confirmed"].includes(item.status) ? "ok" : item.status === "rejected" ? "rejected" : "proposed");
      if (item.id && window.PortalAccess.can("downtime", "update")) {
        const button = document.createElement("button"); button.type = "button"; button.className = "link pns-tag-action";
        button.textContent = item.status === "proposed" ? "Tag reason" : "Edit reason"; button.dataset.dashboardTag = String(item.id);
        button.dataset.permissionPage = "downtime"; button.dataset.permissionAction = "update";
        button.setAttribute("aria-label", button.textContent + " for " + item.line_code + " ending " + item.ends_at);
        statusCell.appendChild(button);
      }
      stops.appendChild(row);
    });
    if (!stops.children.length) empty(stops, 4, "No downtime events in this reporting window.");
    node("dashboard-detail-message").hidden = window.PortalAccess.can("oee-report") || window.PortalAccess.can("downtime");
    window.PortalAccess.applyControls();
  }
  function loading(active) {
    document.querySelector('[data-view="dashboard"]').setAttribute("aria-busy", String(active));
    node("dashboard-loading").hidden = !active; node("dashboard-refresh").disabled = active;
  }
  function error() {
    ["oee-value","availability-value","performance-value","quality-value","total-fb","productive-fb","empty-fb","total-pcs","total-area","oee-report-date","planned-minutes","unplanned-minutes","run-minutes","utilization-value"].forEach(id => text(id, "—"));
    render({}); text("oee-status-text", "Data could not be refreshed"); text("oee-last-sync", "—"); node("oee-status-dot").className = "status-dot warn";
    empty(node("dashboard-batches-body"), 5, "Data unavailable. Refresh to retry."); empty(node("dashboard-stops-body"), 4, "Data unavailable. Refresh to retry.");
    text("dashboard-reasons", "Downtime reasons unavailable."); text("oee-sync-list", "Synchronization data unavailable."); empty(node("oee-imports-body"), 5, "Import history unavailable.");
    text("dashboard-window", "Reporting window could not be loaded.");
  }
  function tagError(message) {
    text("dashboard-tag-error", message); node("dashboard-tag-error").hidden = !message;
  }
  async function openTag(id) {
    if (!window.PortalAccess.can("downtime", "update")) return;
    const item = stopEvents.find(stop => String(stop.id) === id); if (!item) return;
    editingStop = item; const requestId = ++tagRequest, dialog = node("dashboard-tag-dialog");
    node("dashboard-tag-form").reset(); tagError("");
    text("dashboard-tag-context", item.line_code + " · " + item.starts_at + " → " + item.ends_at + " · " + item.source + " · " + item.status);
    node("dashboard-tag-type").value = item.event_type;
    node("dashboard-tag-notes").value = item.notes || "";
    node("dashboard-tag-reason").replaceChildren(new Option("Loading reasons…", ""));
    node("dashboard-tag-reason").disabled = true; node("dashboard-tag-save").disabled = true;
    dialog.showModal();
    try {
      const data = await window.PortalRequest("oee", "masters", {});
      if (requestId !== tagRequest || !dialog.open) return;
      const select = node("dashboard-tag-reason"); select.replaceChildren(new Option("Select a reason", ""));
      (data.reasons || []).filter(reason => Number(reason.is_active) === 1).forEach(reason => select.appendChild(new Option(reason.label, reason.code)));
      select.value = item.reason_code; if (select.selectedIndex < 0) select.value = "";
      select.disabled = false; node("dashboard-tag-save").disabled = select.options.length <= 1;
      if (select.options.length <= 1) tagError("No active reasons are registered. Ask an administrator to add one in OEE master data.");
      select.focus();
    } catch (error) { if (requestId === tagRequest && dialog.open) tagError(error.message); }
  }
  const tagDialog = node("dashboard-tag-dialog");
  tagDialog.addEventListener("close", function () { editingStop = null; ++tagRequest; });
  tagDialog.addEventListener("cancel", function (event) { if (savingTag) event.preventDefault(); });
  node("dashboard-tag-cancel").addEventListener("click", function () { if (!savingTag) tagDialog.close(); });
  node("dashboard-tag-form").addEventListener("submit", async function (event) {
    event.preventDefault();
    if (!editingStop || savingTag || node("dashboard-tag-save").disabled) return;
    if (!window.PortalAccess.can("downtime", "update")) { tagError("You no longer have permission to tag downtime."); return; }
    savingTag = true; tagError("");
    node("dashboard-tag-form").setAttribute("aria-busy", "true");
    node("dashboard-tag-save").disabled = true; node("dashboard-tag-cancel").disabled = true;
    try {
      await window.PortalRequest("oee", "downtime-tag", { method: "POST", body: { event_id: Number(editingStop.id), machine: editingStop.line_code, event_type: node("dashboard-tag-type").value, reason_code: node("dashboard-tag-reason").value, notes: node("dashboard-tag-notes").value } });
      tagDialog.close(); window.AppToast?.show("Downtime reason saved", "ok");
      window.dispatchEvent(new Event("pns-downtime-tagged"));
    } catch (error) { tagError(error.message); }
    finally { savingTag = false; node("dashboard-tag-form").setAttribute("aria-busy", "false"); node("dashboard-tag-save").disabled = false; node("dashboard-tag-cancel").disabled = false; }
  });
  window.addEventListener("portal-access-ready", function () {
    if (tagDialog.open && !window.PortalAccess.can("downtime", "update")) tagDialog.close();
  });
  document.addEventListener("click", function (event) {
    const button = event.target.closest("[data-dashboard-tag]"); if (button) openTag(button.dataset.dashboardTag);
  });
  document.addEventListener("click", function (event) {
    const link = event.target.closest("[data-dashboard-link]"); if (!link) return;
    const target = link.dataset.dashboardLink, date = node("dashboard-date").value;
    if (target === "report") {
      node("report-date").value = date; node("report-machine-filter").value = node("dashboard-machine").value;
      node("report-duration").value = node("dashboard-duration").value; node("report-start-time").value = node("dashboard-start-time").value;
    } else {
      node("downtime-date").value = date; if (node("dashboard-machine").value !== "ALL") node("downtime-machine").value = node("dashboard-machine").value;
    }
  }, true);
  window.PnsDashboard = { render, loading, error };
})();
