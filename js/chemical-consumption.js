(function () {
  const access = window.PortalAccess;
  const api = (...args) => window.PortalRequest(...args);
  const byId = id => document.getElementById(id);
  const state = { entries: [], items: [], defaultOperator: "", loading: false };

  function showAlert(message) {
    const alert = byId("chemical-consumption-alert");
    if (!alert) return;
    alert.textContent = message || "";
    alert.hidden = !message;
  }

  function localDate() {
    const now = new Date();
    return new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  }

  function cell(row, value, className) {
    const td = document.createElement("td");
    if (className) td.className = className;
    td.textContent = value == null || value === "" ? "—" : String(value);
    row.appendChild(td);
    return td;
  }

  function editButton(id) {
    const control = document.createElement("button");
    control.type = "button";
    control.className = "link";
    control.textContent = "Edit Entry";
    control.dataset.editChemicalConsumption = String(id);
    return control;
  }

  function formatQuantity(value, uom) {
    const number = Number(value);
    const quantity = Number.isFinite(number)
      ? new Intl.NumberFormat("en-IN", { maximumFractionDigits: 4 }).format(number)
      : String(value);
    return quantity + (uom ? " " + uom : "");
  }

  function selectedItem() {
    const id = byId("chemical-consumption-item").value;
    return state.items.find(item => String(item.id) === id);
  }

  function updateUom() {
    byId("chemical-consumption-uom").textContent = selectedItem()?.uom || "UOM";
  }

  function applyControls() {
    const form = byId("chemical-consumption-form");
    if (!form) return;
    const editing = Boolean(byId("chemical-consumption-id").value);
    const allowed = access.can("chemical-consumption", editing ? "update" : "create");
    form.hidden = !access.can("chemical-consumption", "create") && !access.can("chemical-consumption", "update");
    form.querySelectorAll("input,select,textarea,button").forEach(control => { control.disabled = !allowed; });
    byId("chemical-consumption-cancel").disabled = false;
    document.querySelectorAll("[data-edit-chemical-consumption]").forEach(control => {
      control.hidden = !access.can("chemical-consumption", "update");
    });
  }

  function resetForm() {
    const form = byId("chemical-consumption-form");
    form.reset();
    byId("chemical-consumption-id").value = "";
    byId("chemical-consumption-date").value = localDate();
    byId("chemical-consumption-operator").value = state.defaultOperator;
    byId("chemical-consumption-save").textContent = "Save Entry";
    byId("chemical-consumption-cancel").hidden = true;
    updateUom();
    applyControls();
  }

  function renderItems(selectedId) {
    const select = byId("chemical-consumption-item");
    select.replaceChildren(new Option("Select a Chemical", ""));
    state.items.forEach(item => {
      if (item.status !== "active" && String(item.id) !== String(selectedId || "")) return;
      const suffix = item.status === "active" ? "" : " (Inactive)";
      select.add(new Option(item.item_code + " — " + item.description + " [" + item.uom + "]" + suffix, item.id));
    });
    if (selectedId != null) select.value = String(selectedId);
    updateUom();
  }

  function renderEntries() {
    const body = byId("chemical-consumption-body");
    body.replaceChildren();
    state.entries.forEach(entry => {
      const row = document.createElement("tr");
      cell(row, entry.entry_date);
      cell(row, entry.shift_name);
      cell(row, entry.operator_name);
      cell(row, entry.machine_code, "mono");
      cell(row, entry.process_description);
      cell(row, entry.chemical_name);
      cell(row, formatQuantity(entry.consume_quantity, entry.uom));
      cell(row, entry.used_for);
      cell(row, entry.recommended_by);
      cell(row, entry.remarks);
      const actions = cell(row, "", "row-actions-cell");
      if (access.can("chemical-consumption", "update")) actions.appendChild(editButton(entry.id));
      body.appendChild(row);
    });
    if (!state.entries.length) {
      const row = document.createElement("tr");
      const empty = cell(row, "No chemical consumption entries have been recorded yet.");
      empty.colSpan = 11;
      body.appendChild(row);
    }
    applyControls();
  }

  async function load() {
    if (!access.can("chemical-consumption") || state.loading) return;
    state.loading = true;
    byId("chemical-consumption-refresh").disabled = true;
    try {
      const data = await api("chemical-consumption", "list", {});
      if (!data.available) throw new Error(data.message || "Chemical consumption is unavailable.");
      state.entries = data.entries || [];
      state.items = data.items || [];
      state.defaultOperator = data.default_operator || "";
      renderItems(byId("chemical-consumption-item").value);
      if (!byId("chemical-consumption-id").value) {
        if (!byId("chemical-consumption-date").value) byId("chemical-consumption-date").value = localDate();
        if (!byId("chemical-consumption-operator").value) byId("chemical-consumption-operator").value = state.defaultOperator;
      }
      renderEntries();
      showAlert("");
    } catch (error) {
      showAlert(error.message);
    } finally {
      state.loading = false;
      byId("chemical-consumption-refresh").disabled = false;
    }
  }

  byId("chemical-consumption-form").addEventListener("submit", async function (event) {
    event.preventDefault();
    const id = Number(byId("chemical-consumption-id").value) || null;
    if (!access.can("chemical-consumption", id ? "update" : "create")) return;
    const save = byId("chemical-consumption-save");
    save.disabled = true;
    try {
      await api("chemical-consumption", "save", { method: "POST", body: {
        id,
        entry_date: byId("chemical-consumption-date").value,
        shift_name: byId("chemical-consumption-shift").value,
        operator_name: byId("chemical-consumption-operator").value,
        machine_code: byId("chemical-consumption-machine").value,
        process_description: byId("chemical-consumption-process").value,
        chemical_item_id: byId("chemical-consumption-item").value,
        consume_quantity: byId("chemical-consumption-quantity").value,
        used_for: byId("chemical-consumption-used-for").value,
        recommended_by: byId("chemical-consumption-recommended-by").value,
        remarks: byId("chemical-consumption-remarks").value
      } });
      window.AppToast?.show(id ? "Chemical consumption entry updated" : "Chemical consumption entry recorded", "ok");
      resetForm();
      await load();
    } catch (error) {
      showAlert(error.message);
    } finally {
      applyControls();
    }
  });

  byId("chemical-consumption-item").addEventListener("change", updateUom);
  byId("chemical-consumption-cancel").addEventListener("click", resetForm);
  byId("chemical-consumption-refresh").addEventListener("click", load);

  document.addEventListener("click", function (event) {
    const control = event.target.closest("[data-edit-chemical-consumption]");
    if (!control || !access.can("chemical-consumption", "update")) return;
    const entry = state.entries.find(item => String(item.id) === control.dataset.editChemicalConsumption);
    if (!entry) return;
    renderItems(entry.chemical_item_id);
    byId("chemical-consumption-id").value = entry.id;
    byId("chemical-consumption-date").value = entry.entry_date;
    byId("chemical-consumption-shift").value = entry.shift_name;
    byId("chemical-consumption-operator").value = entry.operator_name;
    byId("chemical-consumption-machine").value = entry.machine_code;
    byId("chemical-consumption-process").value = entry.process_description;
    byId("chemical-consumption-item").value = entry.chemical_item_id;
    byId("chemical-consumption-quantity").value = entry.consume_quantity;
    byId("chemical-consumption-used-for").value = entry.used_for;
    byId("chemical-consumption-recommended-by").value = entry.recommended_by;
    byId("chemical-consumption-remarks").value = entry.remarks || "";
    byId("chemical-consumption-save").textContent = "Update Entry";
    byId("chemical-consumption-cancel").hidden = false;
    updateUom();
    applyControls();
    byId("chemical-consumption-date").focus();
  });

  window.addEventListener("portal-view-changed", event => {
    if (event.detail.route === "chemical-consumption") load();
  });
  window.addEventListener("portal-access-ready", applyControls);
  resetForm();
})();
