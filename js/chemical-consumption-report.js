(function () {
  const access = window.PortalAccess;
  const api = (...args) => window.PortalRequest(...args);
  const byId = id => document.getElementById(id);
  const state = { loading: false, loaded: false };

  function localDate(date) {
    const value = date || new Date();
    return new Date(value.getTime() - value.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  }

  function displayDate(value) {
    if (!value) return "—";
    const parts = value.split("-").map(Number);
    return new Intl.DateTimeFormat("en-IN", { day: "2-digit", month: "short", year: "numeric" })
      .format(new Date(parts[0], parts[1] - 1, parts[2]));
  }

  function showAlert(message) {
    const alert = byId("chemical-report-alert");
    alert.textContent = message || "";
    alert.hidden = !message;
  }

  function cell(row, value, className) {
    const td = document.createElement("td");
    if (className) td.className = className;
    td.textContent = value === "" ? "" : (value == null ? "—" : String(value));
    row.appendChild(td);
    return td;
  }

  function quantity(value, uom) {
    const number = Number(value);
    const formatted = Number.isFinite(number)
      ? new Intl.NumberFormat("en-IN", { maximumFractionDigits: 4 }).format(number)
      : String(value);
    return formatted + (uom ? " " + uom : "");
  }

  function renderMachines(machines, selected) {
    const select = byId("chemical-report-machine");
    select.replaceChildren(new Option("All Machines", "ALL"));
    (machines || []).forEach(machine => select.add(new Option(machine, machine)));
    select.value = (machines || []).includes(selected) ? selected : "ALL";
  }

  function render(data) {
    renderMachines(data.machines, data.machine);
    byId("chemical-report-from-display").textContent = displayDate(data.from_date);
    byId("chemical-report-to-display").textContent = displayDate(data.to_date);
    byId("chemical-report-machine-display").textContent = data.machine === "ALL" ? "All Machines" : data.machine;
    const body = byId("chemical-report-body");
    body.replaceChildren();
    (data.rows || []).forEach((entry) => {
      const row = document.createElement("tr");
      cell(row, displayDate(entry.entry_date));
      cell(row, entry.shift_name);
      cell(row, entry.machine_code, "mono");
      cell(row, entry.operator_name);
      cell(row, entry.process_description);
      cell(row, entry.chemical_name + " (" + entry.item_code + ")");
      cell(row, quantity(entry.consume_quantity, entry.uom));
      cell(row, entry.used_for);
      cell(row, entry.recommended_by);
      cell(row, entry.remarks);
      body.appendChild(row);
    });
    if (!(data.rows || []).length) {
      const row = document.createElement("tr");
      const empty = cell(row, "No chemical consumption entries match the selected filters.");
      empty.colSpan = 10;
      body.appendChild(row);
    }
    state.loaded = true;
    byId("chemical-report-print").disabled = false;
    showAlert(data.truncated ? "The report contains more than 5,000 entries. Refine the date range or machine to view all records." : "");
  }

  async function load() {
    if (!access.can("chemical-consumption-report") || state.loading) return;
    state.loading = true;
    const run = byId("chemical-report-run");
    run.disabled = true;
    try {
      const data = await api("chemical-consumption", "report", { query: {
        from_date: byId("chemical-report-from").value,
        to_date: byId("chemical-report-to").value,
        machine: byId("chemical-report-machine").value || "ALL"
      } });
      if (!data.available) throw new Error(data.message || "Chemical consumption reporting is unavailable.");
      render(data);
    } catch (error) {
      showAlert(error.message);
    } finally {
      state.loading = false;
      run.disabled = false;
    }
  }

  byId("chemical-report-filter-form").addEventListener("submit", function (event) {
    event.preventDefault();
    load();
  });

  byId("chemical-report-print").addEventListener("click", function () {
    if (state.loaded) window.print();
  });

  const now = new Date();
  byId("chemical-report-from").value = localDate(new Date(now.getFullYear(), now.getMonth(), 1));
  byId("chemical-report-to").value = localDate(now);
  byId("chemical-report-print").disabled = true;

  window.addEventListener("portal-view-changed", event => {
    if (event.detail.route === "chemical-consumption-report") load();
  });
})();
