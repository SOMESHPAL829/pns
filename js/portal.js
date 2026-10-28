(function () {
  const shell = document.getElementById("app-shell");
  const views = document.querySelectorAll("[data-view]");
  const navLinks = document.querySelectorAll("[data-nav]");
  const pageTitle = document.getElementById("page-title");
  const titles = {
    dashboard: "Dashboard",
    "oee-range": "Date-Range OEE",
    "oee-report": "Production Report",
    "scada-logs": "SCADA Logs",
    downtime: "Downtime Review",
    "oee-masters": "OEE Master Data",
    "chemical-registration": "Chemical Registration",
    "breakdown-registration": "Downtime Registration",
    "chemical-consumption": "Chemical Consumption",
    "chemical-consumption-report": "Chemical Consumption Report",
    "chemical-cost-report": "Chemical Cost Analytics",
    requests: "Records",
    create: "New Record",
    detail: "Record Detail",
    users: "Users",
    "user-create": "Add User",
    "user-edit": "Edit User",
    roles: "Roles",
    "role-edit": "Edit Role",
    profile: "My Profile",
    "access-loading": "Loading Permissions",
    "access-denied": "Access Denied",
    orgs: "Organizations",
    library: "Library",
  };

  function showToast(message, kind) {
    const stack = document.getElementById("toast-stack");
    if (!stack) return;
    const toast = document.createElement("div");
    toast.className = "app-toast" + (kind ? " " + kind : "");
    toast.textContent = message;
    stack.appendChild(toast);
    setTimeout(function () {
      toast.remove();
    }, 2800);
  }

  function closeHeaderMenus(except) {
    document.querySelectorAll("[data-header-menu]").forEach(function (menu) {
      if (except && menu === except) return;
      const btn = menu.querySelector("[data-header-toggle]");
      const panel = menu.querySelector("[data-header-panel]");
      if (btn) btn.setAttribute("aria-expanded", "false");
      if (panel) panel.hidden = true;
    });
  }

  function setView(name) {
    const routeParts = name.split("/");
    const routeName = routeParts[0].split("?")[0];
    const requestedKey = titles[routeName] ? routeName : "dashboard";
    const key = !window.PortalAccess?.ready
      ? "access-loading"
      : window.PortalAccess.routeAllowed(requestedKey)
        ? requestedKey
        : "access-denied";
    views.forEach(function (view) {
      view.classList.toggle("active", view.getAttribute("data-view") === key);
    });
    navLinks.forEach(function (link) {
      const on = link.getAttribute("data-nav") === key;
      link.classList.toggle("active", on);
      link.classList.toggle("is-active", on);
    });
    if (pageTitle) pageTitle.textContent = titles[key];
    const routeHash =
      requestedKey === "detail" && routeParts[1]
        ? "#/detail/" + encodeURIComponent(routeParts[1])
        : "#/" + requestedKey;
    if (location.hash !== routeHash) history.replaceState(null, "", routeHash);
    if (shell) shell.classList.remove("sidebar-open");
    closeHeaderMenus();
    window.dispatchEvent(
      new CustomEvent("portal-view-changed", { detail: { route: key } }),
    );
  }

  window.addEventListener("hashchange", function () {
    setView((location.hash || "#/dashboard").replace("#/", ""));
  });
  window.addEventListener("portal-access-ready", function () {
    setView((location.hash || "#/dashboard").replace("#/", ""));
  });

  document.querySelectorAll("[data-nav], [data-goto]").forEach(function (el) {
    el.addEventListener("click", function (event) {
      event.preventDefault();
      setView(el.getAttribute("data-nav") || el.getAttribute("data-goto"));
    });
  });

  const toggle = document.getElementById("sidebar-toggle");
  const mobile = document.getElementById("sidebar-mobile-toggle");
  if (toggle && shell) {
    toggle.addEventListener("click", function () {
      shell.classList.toggle("sidebar-collapsed");
      toggle.setAttribute(
        "aria-expanded",
        String(!shell.classList.contains("sidebar-collapsed")),
      );
    });
  }
  if (mobile && shell) {
    mobile.addEventListener("click", function () {
      shell.classList.toggle("sidebar-open");
    });
  }
  const backdrop = document.getElementById("sidebar-backdrop");
  if (backdrop && shell) {
    backdrop.addEventListener("click", function () {
      shell.classList.remove("sidebar-open");
    });
  }

  document.querySelectorAll("[data-header-menu]").forEach(function (menu) {
    const btn = menu.querySelector("[data-header-toggle]");
    const panel = menu.querySelector("[data-header-panel]");
    if (!btn || !panel) return;
    btn.addEventListener("click", function (event) {
      event.stopPropagation();
      const open = btn.getAttribute("aria-expanded") === "true";
      closeHeaderMenus();
      if (!open) {
        btn.setAttribute("aria-expanded", "true");
        panel.hidden = false;
      }
    });
    menu.addEventListener("click", function (event) {
      event.stopPropagation();
    });
  });
  document.addEventListener("click", function () {
    closeHeaderMenus();
  });
  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") closeHeaderMenus();
  });

  document.querySelectorAll("[data-toast]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      showToast(
        btn.getAttribute("data-toast"),
        btn.getAttribute("data-toast-kind") || "",
      );
    });
  });

  const markRead = document.getElementById("mark-all-read");
  if (markRead) {
    markRead.addEventListener("click", function () {
      document
        .querySelectorAll(".app-header-note.is-unread")
        .forEach(function (n) {
          n.classList.remove("is-unread");
        });
      const badge = document.getElementById("notify-badge");
      if (badge) badge.hidden = true;
      showToast("All notifications marked read", "ok");
    });
  }

  document.querySelectorAll("form[data-demo-form]").forEach(function (form) {
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      const next = form.getAttribute("data-next") || "dashboard";
      showToast(form.getAttribute("data-success") || "Saved", "ok");
      setView(next);
    });
  });

  document.querySelectorAll("[data-col-filter]").forEach(function (input) {
    input.addEventListener("input", function () {
      const table = input.closest("table");
      if (!table) return;
      const filters = Array.from(
        table.querySelectorAll("[data-col-filter]"),
      ).map(function (el) {
        return {
          col: el.getAttribute("data-col-filter"),
          q: el.value.toLowerCase(),
        };
      });
      table.querySelectorAll("tbody tr").forEach(function (row) {
        const hide = filters.some(function (f) {
          if (!f.q) return false;
          const cell = row.querySelector('[data-col="' + f.col + '"]');
          return cell && cell.textContent.toLowerCase().indexOf(f.q) === -1;
        });
        row.hidden = hide;
      });
    });
  });

  document.querySelectorAll("[data-filter-clear]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      const root = btn.closest(".view") || document;
      root.querySelectorAll("[data-col-filter]").forEach(function (input) {
        input.value = "";
        input.dispatchEvent(new Event("input"));
      });
    });
  });

  setView((location.hash || "#/dashboard").replace("#/", ""));
  window.AppToast = { show: showToast };
})();
