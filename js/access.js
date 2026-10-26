(function () {
  const catalog = JSON.parse(document.getElementById("permission-catalog").textContent);
  let access = null;
  const routes = { requests: ["records", "view"], create: ["records", "create"], detail: ["records", "view"], "user-create": ["users", "create"] };
  function can(page, action = "view") {
    return access?.permissions?.[page]?.view === true && access?.permissions?.[page]?.[action] === true;
  }
  function routeAllowed(route) {
    const key = String(route).split("/")[0].split("?")[0];
    const needed = routes[key] || [key, "view"];
    return can(...needed);
  }
  function applyControls() {
    document.querySelectorAll("[data-nav], [data-goto]").forEach(function (node) {
      node.hidden = !routeAllowed(node.dataset.nav || node.dataset.goto);
    });
    document.querySelectorAll("[data-permission-page]").forEach(function (node) {
      node.hidden = !can(node.dataset.permissionPage, node.dataset.permissionAction || "view");
    });
    const profileEditable = can("profile", "update");
    document.querySelectorAll("#profile-form input:not([readonly]), #password-form input, #password-form button, [form='profile-form']").forEach(function (node) { node.disabled = !profileEditable; });
    document.querySelectorAll("#reason-form, #cycle-form").forEach(function (form) {
      const editing = !!form.querySelector("input[type=hidden]")?.value;
      const allowed = can("oee-masters", editing ? "update" : "create");
      form.hidden = !can("oee-masters", "create") && !can("oee-masters", "update");
      form.querySelectorAll("input,select,button").forEach(function (node) { node.disabled = !allowed; });
    });
    const downtime = document.getElementById("downtime-form");
    if (downtime) downtime.hidden = !can("downtime", "create") && !can("downtime", "update");
    const newStop = document.querySelector("#downtime-form button[type=submit]");
    if (newStop) newStop.disabled = !can("downtime", newStop.dataset.editing === "true" ? "update" : "create");
  }
  window.PortalAccess = {
    catalog, can, routeAllowed, applyControls,
    get ready() { return access !== null; },
    get current() { return access; },
    set(value) { access = value || { permissions: {} }; applyControls(); window.dispatchEvent(new Event("portal-access-ready")); },
  };
  applyControls();
})();
