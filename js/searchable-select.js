(function () {
  const instances = new WeakMap();
  let openInstance = null;
  let sequence = 0;

  function normalize(value) {
    return String(value || "").toLocaleLowerCase().replace(/\s+/g, " ").trim();
  }

  function enhance(select) {
    if (!select || instances.has(select)) return instances.get(select);

    const required = select.required;
    const allowCustom = select.hasAttribute("data-allow-custom");
    const customMaxlength = Math.max(1, Number(select.dataset.customMaxlength) || 500);
    select.required = false;
    select.tabIndex = -1;
    select.classList.add("searchable-select-native");

    const root = document.createElement("div");
    root.className = "searchable-select";
    const input = document.createElement("input");
    input.type = "search";
    input.className = "searchable-select-input";
    input.id = select.id ? select.id + "-search" : "searchable-select-input-" + (sequence + 1);
    input.autocomplete = "off";
    input.spellcheck = false;
    input.required = required;
    input.placeholder = select.dataset.searchPlaceholder || "Search options";
    input.setAttribute("role", "combobox");
    input.setAttribute("aria-autocomplete", "list");
    input.setAttribute("aria-expanded", "false");
    input.setAttribute("aria-label", select.getAttribute("aria-label") || select.closest("label")?.childNodes[0]?.textContent?.trim() || "Select an option");

    const toggle = document.createElement("button");
    toggle.type = "button";
    toggle.className = "searchable-select-toggle";
    toggle.tabIndex = -1;
    toggle.setAttribute("aria-label", "Show options");
    toggle.innerHTML = '<span aria-hidden="true"></span>';

    const list = document.createElement("div");
    list.className = "searchable-select-list";
    list.id = "searchable-select-list-" + (++sequence);
    list.setAttribute("role", "listbox");
    list.hidden = true;
    input.setAttribute("aria-controls", list.id);

    const label = select.closest("label");
    if (label?.htmlFor === select.id) label.htmlFor = input.id;
    select.parentNode.insertBefore(root, select);
    root.append(input, toggle, list, select);

    const state = { select, root, input, toggle, list, options: [], active: -1, open: false, keyboardNavigated: false, observer: null };
    instances.set(select, state);

    function selectedOption() {
      return Array.from(select.options).find(option => option.value === select.value) || null;
    }

    function validate() {
      const valid = !required || Boolean(select.value);
      input.setCustomValidity(valid ? "" : "Choose an option from the list.");
      input.setAttribute("aria-invalid", valid ? "false" : "true");
      return valid;
    }

    function sync() {
      const selected = selectedOption();
      if (!state.open) input.value = selected && selected.value ? selected.textContent.trim() : "";
      input.disabled = select.disabled;
      toggle.disabled = select.disabled;
      root.classList.toggle("is-disabled", select.disabled);
      validate();
    }

    function positionList() {
      if (!state.open) return;
      const bounds = root.getBoundingClientRect();
      const available = Math.max(120, window.innerHeight - bounds.bottom - 12);
      const dialog = root.closest("dialog");
      if (dialog) {
        if (list.parentNode !== dialog) dialog.appendChild(list);
        const dialogBounds = dialog.getBoundingClientRect();
        list.style.position = "absolute";
        list.style.left = (bounds.left - dialogBounds.left) + "px";
        list.style.top = (bounds.bottom - dialogBounds.top + 5) + "px";
      } else {
        if (list.parentNode !== root) root.appendChild(list);
        list.style.position = "absolute";
        list.style.left = "0";
        list.style.top = "calc(100% + 5px)";
      }
      list.style.width = bounds.width + "px";
      list.style.maxHeight = Math.min(260, available) + "px";
    }

    function setActive(index, scroll) {
      if (!state.options.length) return;
      state.active = Math.max(0, Math.min(index, state.options.length - 1));
      state.options.forEach((option, optionIndex) => option.classList.toggle("is-active", optionIndex === state.active));
      const active = state.options[state.active];
      input.setAttribute("aria-activedescendant", active.id);
      if (scroll) active.scrollIntoView({ block: "nearest" });
    }

    function render(query) {
      const rawQuery = String(query || "").trim().slice(0, customMaxlength);
      const term = normalize(rawQuery);
      list.replaceChildren();
      state.options = [];
      Array.from(select.options).forEach(function (option) {
        if (!option.value || option.disabled || (term && !normalize(option.textContent).includes(term))) return;
        const item = document.createElement("button");
        item.type = "button";
        item.className = "searchable-select-option";
        item.id = list.id + "-option-" + state.options.length;
        item.dataset.value = option.value;
        item.textContent = option.textContent.trim();
        item.setAttribute("role", "option");
        item.setAttribute("aria-selected", String(option.value === select.value));
        if (option.value === select.value) item.classList.add("is-selected");
        list.appendChild(item);
        state.options.push(item);
      });
      const exactMatch = Array.from(select.options).some(option => option.value && normalize(option.textContent) === term);
      if (allowCustom && rawQuery && !exactMatch) {
        const custom = document.createElement("button");
        custom.type = "button";
        custom.className = "searchable-select-option searchable-select-custom-option";
        custom.id = list.id + "-option-" + state.options.length;
        custom.dataset.customValue = rawQuery;
        custom.textContent = 'Use "' + rawQuery + '" as a new description';
        custom.setAttribute("role", "option");
        list.appendChild(custom);
        state.options.push(custom);
      }
      if (!state.options.length) {
        const empty = document.createElement("p");
        empty.className = "searchable-select-empty";
        empty.textContent = "No matching options";
        list.appendChild(empty);
        state.active = -1;
        input.removeAttribute("aria-activedescendant");
      } else {
        const selectedIndex = state.options.findIndex(option => option.dataset.value === select.value);
        setActive(selectedIndex >= 0 ? selectedIndex : 0, false);
      }
      positionList();
    }

    function open(query) {
      if (select.disabled) return;
      if (openInstance && openInstance !== state) openInstance.close();
      state.open = true;
      openInstance = state;
      root.classList.add("is-open");
      list.hidden = false;
      input.setAttribute("aria-expanded", "true");
      state.keyboardNavigated = false;
      render(query == null ? "" : query);
      positionList();
    }

    function close(restoreLabel) {
      state.open = false;
      if (openInstance === state) openInstance = null;
      root.classList.remove("is-open");
      list.hidden = true;
      input.setAttribute("aria-expanded", "false");
      input.removeAttribute("aria-activedescendant");
      if (restoreLabel !== false) {
        const selected = selectedOption();
        input.value = selected && selected.value ? selected.textContent.trim() : "";
      }
      validate();
    }
    state.close = close;

    function choose(value, customValue) {
      if (allowCustom && customValue) {
        value = String(customValue).trim().slice(0, customMaxlength);
        if (!Array.from(select.options).some(option => option.value === value)) {
          const option = new Option(value, value);
          option.dataset.customOption = "true";
          select.add(option);
        }
      }
      if (select.value !== value) {
        select.value = value;
        select.dispatchEvent(new Event("change", { bubbles: true }));
      }
      close(true);
      input.focus();
    }

    input.addEventListener("focus", function () { open(""); });
    input.addEventListener("click", function () { open(input.value === selectedOption()?.textContent.trim() ? "" : input.value); });
    input.addEventListener("input", function () {
      if (input.value.length > customMaxlength) input.value = input.value.slice(0, customMaxlength);
      if (input.value !== selectedOption()?.textContent.trim()) select.value = "";
      state.keyboardNavigated = false;
      open(input.value);
      validate();
    });
    input.addEventListener("keydown", function (event) {
      if (event.key === "ArrowDown" || event.key === "ArrowUp") {
        event.preventDefault();
        if (!state.open) open("");
        state.keyboardNavigated = true;
        setActive(state.active + (event.key === "ArrowDown" ? 1 : -1), true);
      } else if (event.key === "Enter" && state.open && state.active >= 0) {
        event.preventDefault();
        const typed = String(input.value || "").trim();
        const exact = Array.from(select.options).find(option => option.value && normalize(option.textContent) === normalize(typed));
        if (allowCustom && typed && !state.keyboardNavigated) choose(exact?.value || typed, exact ? "" : typed);
        else choose(state.options[state.active].dataset.value, state.options[state.active].dataset.customValue);
      } else if (event.key === "Escape") {
        event.preventDefault();
        close(true);
      }
    });
    input.addEventListener("blur", function () { window.setTimeout(function () { if (!root.contains(document.activeElement)) close(true); }, 0); });
    toggle.addEventListener("click", function () { if (state.open) close(true); else { input.focus(); open(""); } });
    list.addEventListener("mousedown", function (event) { event.preventDefault(); });
    list.addEventListener("click", function (event) {
      const option = event.target.closest(".searchable-select-option");
      if (option) choose(option.dataset.value, option.dataset.customValue);
    });
    select.addEventListener("change", sync);

    state.observer = new MutationObserver(function () {
      sync();
      if (state.open) render(input.value);
    });
    state.observer.observe(select, { childList: true, subtree: true, attributes: true, attributeFilter: ["disabled"] });
    state.refresh = sync;
    sync();
    return state;
  }

  document.addEventListener("pointerdown", function (event) {
    if (openInstance && !openInstance.root.contains(event.target) && !openInstance.list.contains(event.target)) openInstance.close(true);
  });
  document.addEventListener("scroll", function (event) {
    if (openInstance && event.target !== openInstance.list) openInstance.close(true);
  }, true);
  window.addEventListener("resize", function () { if (openInstance) openInstance.close(true); });

  function initialize(root) {
    (root || document).querySelectorAll("select[data-searchable-select]").forEach(enhance);
  }

  window.PortalSearchableSelect = {
    initialize,
    refresh: function (select) { const instance = enhance(select); instance?.refresh(); },
    open: function (select) { const instance = enhance(select); instance?.input.focus(); }
  };
  initialize(document);
})();
