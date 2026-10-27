(function () {
  const access = window.PortalAccess;
  const api = (...args) => window.PortalRequest(...args);
  const byId = id => document.getElementById(id);
  const state = { items: [], rates: [], loaded: false, loading: false };

  function alertMessage(message) {
    const alert = byId("chemicals-alert");
    if (!alert) return;
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

  function button(label, attribute, value) {
    const control = document.createElement("button");
    control.type = "button";
    control.className = "link";
    control.textContent = label;
    control.dataset[attribute] = String(value);
    return control;
  }

  function money(value) {
    return new Intl.NumberFormat("en-IN", { style: "currency", currency: "INR", minimumFractionDigits: 2, maximumFractionDigits: 4 }).format(Number(value));
  }

  function today() {
    const now = new Date();
    return new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  }

  function periodStatus(rate, date) {
    if (rate.effective_from > date) return "Upcoming";
    if (rate.effective_to && rate.effective_to < date) return "Expired";
    return "Current";
  }

  function currentRate(itemId) {
    const date = today();
    return state.rates.find(rate => Number(rate.chemical_item_id) === Number(itemId) && rate.effective_from <= date && (!rate.effective_to || rate.effective_to >= date));
  }

  function resetItemForm() {
    const form = byId("chemical-item-form");
    form.reset();
    byId("chemical-item-id").value = "";
    byId("chemical-status").value = "active";
    byId("chemical-item-save").textContent = "Save Item";
    byId("chemical-item-cancel").hidden = true;
    applyControls();
  }

  function resetRateForm() {
    const form = byId("chemical-rate-form");
    form.reset();
    byId("chemical-rate-id").value = "";
    byId("chemical-rate-save").textContent = "Save Rate";
    byId("chemical-rate-cancel").hidden = true;
    applyControls();
  }

  function applyControls() {
    const itemEditing = Boolean(byId("chemical-item-id")?.value);
    const rateEditing = Boolean(byId("chemical-rate-id")?.value);
    const itemAllowed = access.can("chemical-registration", itemEditing ? "update" : "create");
    const rateAllowed = access.can("chemical-registration", rateEditing ? "update" : "create");
    [byId("chemical-item-form"), byId("chemical-rate-form")].forEach(form => {
      if (form) form.hidden = !access.can("chemical-registration", "create") && !access.can("chemical-registration", "update");
    });
    byId("chemical-item-form")?.querySelectorAll("input,select,button").forEach(control => { control.disabled = !itemAllowed; });
    byId("chemical-rate-form")?.querySelectorAll("input,select,button").forEach(control => { control.disabled = !rateAllowed; });
    if (byId("chemical-item-cancel")) byId("chemical-item-cancel").disabled = false;
    if (byId("chemical-rate-cancel")) byId("chemical-rate-cancel").disabled = false;
    document.querySelectorAll("[data-edit-chemical-item],[data-edit-chemical-rate]").forEach(control => { control.hidden = !access.can("chemical-registration", "update"); });
    document.querySelectorAll("[data-add-chemical-rate]").forEach(control => { control.hidden = !access.can("chemical-registration", "create"); });
  }

  function render() {
    const date = today();
    const itemSelect = byId("chemical-rate-item");
    const selectedItem = itemSelect.value;
    itemSelect.replaceChildren(new Option("Select an Item", ""));
    state.items.forEach(item => itemSelect.add(new Option(item.item_code + " · " + item.description, item.id)));
    itemSelect.value = state.items.some(item => String(item.id) === selectedItem) ? selectedItem : "";

    const itemBody = byId("chemical-items-body");
    itemBody.replaceChildren();
    state.items.forEach(item => {
      const row = document.createElement("tr");
      cell(row, item.item_code, "mono");
      cell(row, item.description);
      cell(row, item.status === "active" ? "Active" : "Inactive");
      cell(row, item.uom);
      const current = currentRate(item.id);
      cell(row, current ? money(current.rate_inr) : "Not Set");
      const actions = cell(row, "", "row-actions-cell");
      const controls = document.createElement("div");
      controls.className = "row-actions";
      if (access.can("chemical-registration", "update")) controls.appendChild(button("Edit Item", "editChemicalItem", item.id));
      if (access.can("chemical-registration", "create")) controls.appendChild(button("Add Rate", "addChemicalRate", item.id));
      actions.appendChild(controls);
      itemBody.appendChild(row);
    });
    if (!state.items.length) {
      const row = document.createElement("tr");
      const empty = cell(row, "No chemical items are registered yet.");
      empty.colSpan = 6;
      itemBody.appendChild(row);
    }

    const rateBody = byId("chemical-rates-body");
    rateBody.replaceChildren();
    state.rates.forEach(rate => {
      const row = document.createElement("tr");
      cell(row, rate.item_code, "mono");
      cell(row, money(rate.rate_inr));
      cell(row, rate.effective_from);
      cell(row, rate.effective_to || "Open-Ended");
      cell(row, periodStatus(rate, date));
      const actions = cell(row, "", "row-actions-cell");
      if (access.can("chemical-registration", "update")) actions.appendChild(button("Edit Rate", "editChemicalRate", rate.id));
      rateBody.appendChild(row);
    });
    if (!state.rates.length) {
      const row = document.createElement("tr");
      const empty = cell(row, "No chemical rates are registered yet.");
      empty.colSpan = 6;
      rateBody.appendChild(row);
    }
    applyControls();
  }

  async function load() {
    if (!access.can("chemical-registration") || state.loading) return;
    state.loading = true;
    byId("chemicals-refresh").disabled = true;
    try {
      const data = await api("chemicals", "list", {});
      if (!data.available) throw new Error(data.message || "Chemical registration is unavailable.");
      state.items = data.items || [];
      state.rates = data.rates || [];
      state.loaded = true;
      alertMessage("");
      render();
    } catch (error) {
      alertMessage(error.message);
    } finally {
      state.loading = false;
      byId("chemicals-refresh").disabled = false;
    }
  }

  byId("chemical-item-form").addEventListener("submit", async function (event) {
    event.preventDefault();
    const id = Number(byId("chemical-item-id").value) || null;
    if (!access.can("chemical-registration", id ? "update" : "create")) return;
    const save = byId("chemical-item-save");
    save.disabled = true;
    try {
      await api("chemicals", "item-save", { method: "POST", body: { id, item_code: byId("chemical-item-code").value, description: byId("chemical-description").value, status: byId("chemical-status").value, uom: byId("chemical-uom").value } });
      window.AppToast?.show(id ? "Chemical item updated" : "Chemical item registered", "ok");
      resetItemForm();
      await load();
    } catch (error) { alertMessage(error.message); }
    finally { save.disabled = false; }
  });

  byId("chemical-rate-form").addEventListener("submit", async function (event) {
    event.preventDefault();
    const id = Number(byId("chemical-rate-id").value) || null;
    if (!access.can("chemical-registration", id ? "update" : "create")) return;
    const save = byId("chemical-rate-save");
    save.disabled = true;
    try {
      await api("chemicals", "rate-save", { method: "POST", body: { id, chemical_item_id: byId("chemical-rate-item").value, rate_inr: byId("chemical-rate-inr").value, effective_from: byId("chemical-rate-from").value, effective_to: byId("chemical-rate-to").value } });
      window.AppToast?.show(id ? "Chemical rate updated" : "Chemical rate registered", "ok");
      resetRateForm();
      await load();
    } catch (error) { alertMessage(error.message); }
    finally { save.disabled = false; }
  });

  byId("chemical-item-cancel").addEventListener("click", resetItemForm);
  byId("chemical-rate-cancel").addEventListener("click", resetRateForm);
  byId("chemicals-refresh").addEventListener("click", load);

  document.addEventListener("click", function (event) {
    const editItem = event.target.closest("[data-edit-chemical-item]");
    if (editItem) {
      const item = state.items.find(entry => String(entry.id) === editItem.dataset.editChemicalItem);
      if (!item) return;
      byId("chemical-item-id").value = item.id;
      byId("chemical-item-code").value = item.item_code;
      byId("chemical-description").value = item.description;
      byId("chemical-status").value = item.status;
      byId("chemical-uom").value = item.uom;
      byId("chemical-item-save").textContent = "Update Item";
      byId("chemical-item-cancel").hidden = false;
      applyControls();
      byId("chemical-item-code").focus();
    }
    const addRate = event.target.closest("[data-add-chemical-rate]");
    if (addRate) {
      resetRateForm();
      byId("chemical-rate-item").value = addRate.dataset.addChemicalRate;
      byId("chemical-rate-from").value = today();
      byId("chemical-rate-inr").focus();
    }
    const editRate = event.target.closest("[data-edit-chemical-rate]");
    if (editRate) {
      const rate = state.rates.find(entry => String(entry.id) === editRate.dataset.editChemicalRate);
      if (!rate) return;
      byId("chemical-rate-id").value = rate.id;
      byId("chemical-rate-item").value = rate.chemical_item_id;
      byId("chemical-rate-inr").value = rate.rate_inr;
      byId("chemical-rate-from").value = rate.effective_from;
      byId("chemical-rate-to").value = rate.effective_to || "";
      byId("chemical-rate-save").textContent = "Update Rate";
      byId("chemical-rate-cancel").hidden = false;
      applyControls();
      byId("chemical-rate-inr").focus();
    }
  });

  window.addEventListener("portal-view-changed", event => { if (event.detail.route === "chemical-registration") load(); });
  window.addEventListener("portal-access-ready", function () { applyControls(); });
})();
