(function () {
  const access = window.PortalAccess;
  const api = (...args) => window.PortalRequest(...args);
  const byId = id => document.getElementById(id);
  const state = { loading: false, loaded: false };

  function localDate(date) {
    return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  }

  function displayDate(value) {
    if (!value) return "—";
    const parts = value.split("-").map(Number);
    return new Intl.DateTimeFormat("en-IN", { day: "2-digit", month: "short", year: "numeric" })
      .format(new Date(parts[0], parts[1] - 1, parts[2]));
  }

  function showAlert(message) {
    const alert = byId("chemical-cost-report-alert");
    alert.textContent = message || "";
    alert.hidden = !message;
  }

  function cell(row, value, className) {
    const td = document.createElement("td");
    if (className) td.className = className;
    td.textContent = value == null || value === "" ? "—" : String(value);
    row.appendChild(td);
    return td;
  }

  function decimal(value, digits) {
    return new Intl.NumberFormat("en-IN", { minimumFractionDigits: 2, maximumFractionDigits: digits }).format(Number(value));
  }

  function money(value, digits) {
    return new Intl.NumberFormat("en-IN", { style: "currency", currency: "INR", minimumFractionDigits: 2, maximumFractionDigits: digits }).format(Number(value));
  }

  function reportTypeLabel(value) {
    return { weekly: "Weekly Basis", monthly: "Monthly Basis", consolidated: "Consolidated" }[value] || value;
  }

  function renderMachines(machines, selected) {
    const select = byId("chemical-cost-report-machine");
    select.replaceChildren(new Option("All Machines", "ALL"));
    (machines || []).forEach(machine => select.add(new Option(machine, machine)));
    select.value = (machines || []).includes(selected) ? selected : "ALL";
  }

  function render(data) {
    renderMachines(data.machines, data.machine);
    byId("chemical-cost-report-from-display").textContent = displayDate(data.from_date);
    byId("chemical-cost-report-to-display").textContent = displayDate(data.to_date);
    byId("chemical-cost-report-machine-display").textContent = data.machine === "ALL" ? "All Machines" : data.machine;
    byId("chemical-cost-report-type-display").textContent = reportTypeLabel(data.report_type);
    byId("chemical-cost-report-type").value = data.report_type;

    const body = byId("chemical-cost-report-body");
    body.replaceChildren();
    let missingRateEntries = 0;
    (data.rows || []).forEach((item, index) => {
      const row = document.createElement("tr");
      const missingRates = Number(item.unpriced_entries || 0);
      missingRateEntries += missingRates;
      cell(row, index + 1, "chemical-report-serial");
      cell(row, item.machine_code, "mono");
      cell(row, item.report_period);
      cell(row, decimal(item.total_surface_area, 4), "r");
      cell(row, missingRates ? "Rate Missing (" + missingRates + ")" : money(item.total_chemical_cost, 4), missingRates ? "chemical-cost-missing" : "r");
      cell(row, missingRates ? "Rate Missing" : item.chemical_cost_per_dm2 == null ? "Not Available" : money(item.chemical_cost_per_dm2, 6), missingRates ? "chemical-cost-missing" : "r");
      body.appendChild(row);
    });
    if (!(data.rows || []).length) {
      const row = document.createElement("tr");
      const empty = cell(row, "No production or chemical consumption data matches the selected filters.");
      empty.colSpan = 6;
      body.appendChild(row);
    }
    state.loaded = true;
    byId("chemical-cost-report-print").disabled = false;
    showAlert(missingRateEntries
      ? missingRateEntries + " consumption " + (missingRateEntries === 1 ? "entry has" : "entries have") + " no effective INR rate. Affected costs are withheld until rates are registered."
      : "");
  }

  async function load() {
    if (!access.can("chemical-cost-report") || state.loading) return;
    state.loading = true;
    const run = byId("chemical-cost-report-run");
    run.disabled = true;
    try {
      const data = await api("chemical-consumption", "cost-report", { query: {
        from_date: byId("chemical-cost-report-from").value,
        to_date: byId("chemical-cost-report-to").value,
        machine: byId("chemical-cost-report-machine").value || "ALL",
        report_type: byId("chemical-cost-report-type").value
      } });
      if (!data.available) throw new Error(data.message || "Chemical cost reporting is unavailable.");
      render(data);
    } catch (error) {
      showAlert(error.message);
    } finally {
      state.loading = false;
      run.disabled = false;
    }
  }

  byId("chemical-cost-report-filter-form").addEventListener("submit", function (event) {
    event.preventDefault();
    load();
  });

  byId("chemical-cost-report-print").addEventListener("click", function () {
    if (state.loaded) window.print();
  });

  const now = new Date();
  byId("chemical-cost-report-from").value = localDate(new Date(now.getFullYear(), now.getMonth(), 1));
  byId("chemical-cost-report-to").value = localDate(now);
  byId("chemical-cost-report-print").disabled = true;
  window.addEventListener("portal-view-changed", event => {
    if (event.detail.route === "chemical-cost-report") load();
  });
})();
