(function () {
  const api = (...args) => window.PortalRequest(...args);
  const access = window.PortalAccess;
  const alert = document.getElementById("roles-alert");
  const form = document.getElementById("role-form");
  let roles = [], catalog = access.catalog, busy = false, initialized = false;
  function message(text) { alert.textContent = text; alert.hidden = !text; }
  function cell(row, text) { const td = document.createElement("td"); td.textContent = text; row.appendChild(td); return td; }
  function editor(role = null) {
    document.getElementById("role-id").value = role?.id || "";
    document.getElementById("role-name").value = role?.name || "";
    const readonly = Number(role?.is_builtin) === 1;
    document.getElementById("role-name").disabled = readonly;
    const save = document.getElementById("role-save");
    save.textContent = role ? "Save permissions" : "Create role";
    save.disabled = readonly || !access.can("roles", role ? "update" : "create");
    const body = document.getElementById("role-permissions-body"); body.replaceChildren();
    Object.entries(catalog).forEach(function ([page, item]) {
      const row = document.createElement("tr"); cell(row, item.label);
      ["view", "create", "update", "delete", "export"].forEach(function (action) {
        const td = cell(row, "");
        if (!item.actions.includes(action)) { td.textContent = "—"; return; }
        const input = document.createElement("input"); input.type = "checkbox";
        input.dataset.page = page; input.dataset.action = action;
        input.setAttribute("aria-label", item.label + ": " + action);
        input.checked = role?.permissions?.[page]?.[action] === true;
        input.disabled = readonly || page === "roles";
        td.appendChild(input);
      });
      body.appendChild(row);
    });
  }
  function render() {
    const body = document.getElementById("roles-body"); body.replaceChildren();
    const admin = document.createElement("tr"); cell(admin, "Administrator"); cell(admin, "Protected full access"); cell(admin, "Existing administrator accounts"); cell(admin, "Cannot be modified here"); body.appendChild(admin);
    roles.forEach(function (role) {
      const row = document.createElement("tr");
      cell(row, role.name); cell(row, Number(role.is_builtin) === 1 ? "Built-in" : "Custom"); cell(row, role.assigned_users);
      const actions = cell(row, "");
      const edit = document.createElement("button"); edit.type = "button"; edit.className = "link";
      edit.textContent = Number(role.is_builtin) === 1 ? "Inspect" : "Edit";
      edit.setAttribute("aria-label", edit.textContent + " role " + role.name); edit.addEventListener("click", function () { editor(role); document.getElementById("role-name").scrollIntoView({ block: "center" }); }); actions.appendChild(edit);
      if (!Number(role.is_builtin) && access.can("roles", "delete")) {
        const remove = document.createElement("button"); remove.type = "button"; remove.className = "link"; remove.textContent = "Delete";
        remove.setAttribute("aria-label", "Delete role " + role.name);
        remove.disabled = Number(role.assigned_users) > 0;
        remove.addEventListener("click", async function () {
          if (!window.confirm('Delete role "' + role.name + '"?')) return;
          try { remove.disabled = true; await api("roles", "role-delete", { method: "POST", body: { id: Number(role.id) } }); await load(); editor(); message("Role deleted."); }
          catch (error) { message(error.message); remove.disabled = false; }
        }); actions.appendChild(remove);
      }
      body.appendChild(row);
    });
  }
  async function load() {
    if (!access.can("roles") || busy) return;
    busy = true; document.getElementById("roles-refresh").disabled = true;
    try { const data = await api("roles", "", {}); roles = data.roles; catalog = data.catalog; render(); if (!initialized) { editor(); initialized = true; } message(""); }
    catch (error) { message(error.message); }
    finally { busy = false; document.getElementById("roles-refresh").disabled = false; }
  }
  document.getElementById("roles-refresh").addEventListener("click", load);
  document.getElementById("role-new").addEventListener("click", function () { editor(); message(""); document.getElementById("role-name").focus(); });
  form.addEventListener("change", function (event) {
    const input = event.target;
    if (!input.dataset.page) return;
    const row = input.closest("tr");
    if (input.dataset.action === "view" && !input.checked) row.querySelectorAll("input").forEach(node => { node.checked = false; });
    else if (input.checked) row.querySelector('[data-action="view"]').checked = true;
  });
  form.addEventListener("submit", async function (event) {
    event.preventDefault();
    const button = document.getElementById("role-save"); if (button.disabled) return;
    const id = Number(document.getElementById("role-id").value) || null;
    const permissions = {};
    form.querySelectorAll("input[data-page]").forEach(function (input) {
      if (input.dataset.page === "roles") return;
      (permissions[input.dataset.page] ||= {})[input.dataset.action] = input.checked;
    });
    try {
      button.disabled = true;
      await api("roles", "role-save", { method: "POST", body: { id, name: document.getElementById("role-name").value, permissions } });
      await load(); editor(); message("Role saved. Permission changes apply on the next API request. Assign the role under Users.");
    } catch (error) { message(error.message); button.disabled = false; }
  });
  window.addEventListener("portal-view-changed", function (event) { if (event.detail.route === "roles") load(); });
  editor();
})();
