(function () {
  const definitions = [
    { id: "record-create-form", title: "Create New Record", submit: "Create Record" },
    { id: "record-edit-form", title: "Edit Record" },
    { id: "user-create-form", title: "Add User", submit: "Create User" },
    { id: "user-role-form", title: "Assign Access Role" },
    { id: "role-form", title: "Role Permission Editor", label: "New Role", host: '[data-view="roles"] .data-card .dash-card-head', page: "roles", action: "create", mode: "create" },
    { id: "profile-form", title: "Edit Profile", submit: "Save Profile" },
    { id: "password-form", title: "Change Password", label: "Change Password", host: '[data-view="profile"] .page-heading-actions', page: "profile", action: "update", mode: "create" },
    { id: "downtime-form", title: "Downtime Entry", label: "Add Downtime", host: '[data-view="downtime"] .page-heading-actions', page: "downtime", action: "create", mode: "create" },
    { id: "chemical-item-form", title: "Chemical Item Registration", label: "Add Item", host: '[aria-labelledby="chemical-item-heading"] .dash-card-head', page: "chemical-registration", action: "create", mode: "create" },
    { id: "chemical-rate-form", title: "Chemical Rate Registration", label: "Add Rate", host: '[aria-labelledby="chemical-rate-heading"] .dash-card-head', page: "chemical-registration", action: "create", mode: "create" },
    { id: "breakdown-form", title: "Downtime Reason Registration", label: "Add Reason", host: '[data-view="breakdown-registration"] .page-heading-actions', page: "breakdown-registration", action: "create", mode: "create" },
    { id: "chemical-consumption-form", title: "Chemical Consumption Entry", label: "Add Entry", host: '[data-view="chemical-consumption"] .page-heading-actions', page: "chemical-consumption", action: "create", mode: "create", wide: true },
    { id: "threshold-form", title: "Update Plant Gap Threshold", label: "Update Threshold", host: '.threshold-card .dash-card-head', page: "oee-masters", action: "update" },
    { id: "cycle-form", title: "Cycle-Time Registration", label: "Add Cycle Time", host: '.oee-master-grid .dash-card-head', page: "oee-masters", action: "create", mode: "create" }
  ];

  const editTriggers = [
    ['[data-edit-chemical-item]', "chemical-item-form", "Edit Chemical Item"],
    ['[data-add-chemical-rate]', "chemical-rate-form", "Add Chemical Rate"],
    ['[data-edit-chemical-rate]', "chemical-rate-form", "Edit Chemical Rate"],
    ['[data-edit-breakdown]', "breakdown-form", "Edit Downtime Reason"],
    ['[data-edit-chemical-consumption]', "chemical-consumption-form", "Edit Chemical Consumption Entry"],
    ['[data-edit-threshold]', "threshold-form", "Update Plant Gap Threshold"],
    ['[data-edit-cycle]', "cycle-form", "Edit Cycle Time"]
  ];

  const entries = new Map();

  function capture(form) {
    return Array.from(form.elements).map(function (control) {
      return { control, value: control.value, checked: control.checked };
    });
  }

  function restore(snapshot) {
    (snapshot || []).forEach(function (item) {
      if (!item.control.isConnected) return;
      if (item.control.type === "checkbox" || item.control.type === "radio") item.control.checked = item.checked;
      else item.control.value = item.value;
    });
  }

  function ensureSubmit(form, label) {
    if (form.querySelector('[type="submit"]')) return;
    const actions = document.createElement("div");
    actions.className = "portal-modal-form-actions";
    const submit = document.createElement("button");
    submit.type = "submit";
    submit.className = "btn";
    submit.textContent = label || "Save";
    actions.appendChild(submit);
    form.appendChild(actions);
  }

  function prepareCreate(form) {
    const cancel = form.querySelector('[id$="-cancel"]');
    if (cancel) cancel.click();
    else form.reset();
    form.querySelectorAll('input[type="hidden"][id$="-id"]').forEach(input => { input.value = ""; });
    if (form.id === "role-form") document.getElementById("role-new")?.click();
    window.dispatchEvent(new CustomEvent("portal-modal-create", { detail: { formId: form.id } }));
    window.PortalAccess?.applyControls();
  }

  function closeEntry(entry, reset) {
    if (!entry || !entry.dialog.open) return;
    if (reset) {
      const cancel = entry.form.querySelector('[id$="-cancel"]');
      if (cancel) cancel.click();
      else restore(entry.snapshot);
    }
    if (entry.dialog.open) entry.dialog.close();
  }

  function open(formId, title, mode) {
    const entry = entries.get(formId);
    if (!entry) return;
    if (mode === "create") prepareCreate(entry.form);
    entry.snapshot = capture(entry.form);
    entry.title.textContent = title || entry.definition.title;
    entry.form.hidden = false;
    if (!entry.dialog.open) entry.dialog.showModal();
    window.setTimeout(function () {
      entry.form.querySelector('input:not([type="hidden"]):not([readonly]):not(:disabled), select:not(:disabled), textarea:not(:disabled)')?.focus();
    }, 30);
  }

  function createEntry(definition) {
    const form = document.getElementById(definition.id);
    if (!form) return;
    ensureSubmit(form, definition.submit);

    const dialog = document.createElement("dialog");
    dialog.className = "portal-form-modal" + (definition.wide ? " portal-form-modal-wide" : "");
    dialog.dataset.formModal = definition.id;
    const panel = document.createElement("div");
    panel.className = "portal-form-modal-panel";
    const header = document.createElement("header");
    header.className = "portal-form-modal-head";
    const heading = document.createElement("div");
    heading.className = "portal-form-modal-heading";
    const eyebrow = document.createElement("span");
    eyebrow.textContent = "Secure Data Entry";
    const title = document.createElement("h2");
    title.id = "modal-title-" + definition.id;
    title.textContent = definition.title;
    heading.append(eyebrow, title);
    const close = document.createElement("button");
    close.type = "button";
    close.className = "portal-form-modal-close";
    close.setAttribute("aria-label", "Close " + definition.title);
    close.innerHTML = '<span data-icon="x-mark"></span>';
    header.append(heading, close);
    const body = document.createElement("div");
    body.className = "portal-form-modal-body";
    form.classList.add("portal-modal-managed-form");
    form.hidden = false;
    body.appendChild(form);
    panel.append(header, body);
    dialog.appendChild(panel);
    dialog.setAttribute("aria-labelledby", title.id);
    document.body.appendChild(dialog);
    const entry = { definition, form, dialog, title };
    entries.set(definition.id, entry);

    close.addEventListener("click", function () { closeEntry(entry, true); });
    dialog.addEventListener("click", function (event) { if (event.target === dialog) closeEntry(entry, true); });
    dialog.addEventListener("cancel", function (event) { event.preventDefault(); closeEntry(entry, true); });
    form.querySelectorAll('[id$="-cancel"]').forEach(button => button.addEventListener("click", function () { closeEntry(entry, false); }));

    if (definition.host && definition.label) {
      const host = document.querySelector(definition.host);
      if (host) {
        const button = document.createElement("button");
        button.type = "button";
        button.className = "btn portal-modal-launcher";
        button.textContent = definition.label;
        button.dataset.modalOpen = definition.id;
        if (definition.mode) button.dataset.modalMode = definition.mode;
        if (definition.page) button.dataset.permissionPage = definition.page;
        if (definition.action) button.dataset.permissionAction = definition.action;
        host.appendChild(button);
      }
    }
  }

  definitions.forEach(createEntry);
  window.ThemeIcons?.mount(document);

  document.addEventListener("click", function (event) {
    const launcher = event.target.closest("[data-modal-open]");
    if (launcher) {
      event.preventDefault();
      open(launcher.dataset.modalOpen, launcher.dataset.modalTitle, launcher.dataset.modalMode);
      return;
    }
    for (const [selector, formId, title] of editTriggers) {
      if (event.target.closest(selector)) {
        window.setTimeout(function () { open(formId, title); }, 0);
        return;
      }
    }
  });

  document.querySelector('[data-view="create"]')?.setAttribute("aria-hidden", "true");
  document.querySelector('[data-view="user-create"]')?.setAttribute("aria-hidden", "true");

  window.PortalModalForms = {
    open,
    close: function (formId) { closeEntry(entries.get(formId), false); },
    saved: function (formId) { closeEntry(entries.get(formId), false); },
    has: function (formId) { return entries.has(formId); }
  };

  window.PortalAccess?.applyControls();
})();
