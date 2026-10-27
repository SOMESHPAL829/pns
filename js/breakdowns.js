(function () {
  const access = window.PortalAccess;
  const api = (...args) => window.PortalRequest(...args);
  const byId = id => document.getElementById(id);
  const state = { breakdowns: [], loading: false };

  function alertMessage(message) {
    const alert = byId("breakdowns-alert");
    alert.textContent = message || "";
    alert.hidden = !message;
  }

  function cell(row, value) {
    const td = document.createElement("td");
    td.textContent = value == null ? "—" : String(value);
    row.appendChild(td);
    return td;
  }

  function action(label, attribute, id) {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "link";
    button.textContent = label;
    button.dataset[attribute] = String(id);
    return button;
  }

  function applyControls() {
    const editing = Boolean(byId("breakdown-id").value);
    const allowed = access.can("breakdown-registration", editing ? "update" : "create");
    const form = byId("breakdown-form");
    form.hidden = !access.can("breakdown-registration", "create") && !access.can("breakdown-registration", "update");
    form.querySelectorAll("input,select,button").forEach(control => { control.disabled = !allowed; });
    byId("breakdown-cancel").disabled = false;
    document.querySelectorAll("[data-edit-breakdown]").forEach(button => { button.hidden = !access.can("breakdown-registration", "update"); });
    document.querySelectorAll("[data-delete-breakdown]").forEach(button => { button.hidden = !access.can("breakdown-registration", "delete"); });
  }

  function resetForm() {
    byId("breakdown-form").reset();
    byId("breakdown-id").value = "";
    byId("breakdown-loss-type").value = "planned";
    byId("breakdown-save").textContent = "Save";
    byId("breakdown-cancel").hidden = true;
    applyControls();
  }

  function render() {
    const body = byId("breakdowns-body");
    body.replaceChildren();
    state.breakdowns.forEach((item, index) => {
      const row = document.createElement("tr");
      cell(row, index + 1);
      cell(row, item.loss_type === "planned" ? "Planned" : "Unplanned");
      cell(row, item.label);
      const actions = cell(row, "");
      actions.className = "row-actions-cell";
      const controls = document.createElement("div");
      controls.className = "row-actions";
      controls.appendChild(action("Edit", "editBreakdown", item.id));
      const remove = action(Number(item.assigned_count) > 0 ? "In Use" : "Delete", "deleteBreakdown", item.id);
      remove.disabled = Number(item.assigned_count) > 0;
      if (remove.disabled) remove.title = "Assigned downtime reasons cannot be deleted.";
      controls.appendChild(remove);
      actions.appendChild(controls);
      body.appendChild(row);
    });
    if (!state.breakdowns.length) {
      const row = document.createElement("tr");
      const empty = cell(row, "No breakdown reasons are registered yet.");
      empty.colSpan = 4;
      body.appendChild(row);
    }
    applyControls();
  }

  async function load() {
    if (!access.can("breakdown-registration") || state.loading) return;
    state.loading = true;
    byId("breakdowns-refresh").disabled = true;
    try {
      const data = await api("breakdowns", "list", {});
      if (!data.available) throw new Error(data.message || "Breakdown registration is unavailable.");
      state.breakdowns = data.breakdowns || [];
      render();
      alertMessage("");
    } catch (error) {
      alertMessage(error.message);
    } finally {
      state.loading = false;
      byId("breakdowns-refresh").disabled = false;
    }
  }

  byId("breakdown-form").addEventListener("submit", async event => {
    event.preventDefault();
    const id = Number(byId("breakdown-id").value) || null;
    if (!access.can("breakdown-registration", id ? "update" : "create")) return;
    const save = byId("breakdown-save");
    save.disabled = true;
    try {
      await api("breakdowns", "save", { method: "POST", body: {
        id,
        loss_type: byId("breakdown-loss-type").value,
        loss_name: byId("breakdown-loss-name").value,
      } });
      window.AppToast?.show(id ? "Breakdown reason updated" : "Breakdown reason registered", "ok");
      resetForm();
      await load();
    } catch (error) {
      alertMessage(error.message);
    } finally { applyControls(); }
  });

  byId("breakdown-cancel").addEventListener("click", resetForm);
  byId("breakdowns-refresh").addEventListener("click", load);

  document.addEventListener("click", async event => {
    const edit = event.target.closest("[data-edit-breakdown]");
    if (edit) {
      if (!access.can("breakdown-registration", "update")) return;
      const item = state.breakdowns.find(entry => String(entry.id) === edit.dataset.editBreakdown);
      if (!item) return;
      byId("breakdown-id").value = item.id;
      byId("breakdown-loss-type").value = item.loss_type;
      byId("breakdown-loss-name").value = item.label;
      byId("breakdown-save").textContent = "Update";
      byId("breakdown-cancel").hidden = false;
      applyControls();
      byId("breakdown-loss-name").focus();
      return;
    }

    const remove = event.target.closest("[data-delete-breakdown]");
    if (!remove || !access.can("breakdown-registration", "delete")) return;
    const item = state.breakdowns.find(entry => String(entry.id) === remove.dataset.deleteBreakdown);
    if (!item || !window.confirm("Delete \"" + item.label + "\"?")) return;
    remove.disabled = true;
    try {
      await api("breakdowns", "delete", { method: "POST", body: { id: Number(item.id) } });
      alertMessage("");
      window.AppToast?.show("Breakdown reason deleted", "ok");
      await load();
    } catch (error) {
      alertMessage(error.message);
    } finally {
      remove.disabled = false;
    }
  });

  window.addEventListener("portal-view-changed", event => {
    if (event.detail.route === "breakdown-registration") load();
  });
  window.addEventListener("portal-access-ready", applyControls);
})();
