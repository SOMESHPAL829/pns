(function () {
  const REQUIRED_SELECTOR = "form input[required]:not([type='hidden']), form select[required], form textarea[required]";

  function controlLabel(control) {
    return control.labels?.[0] || control.closest("label");
  }

  function labelRequiresMarker(label) {
    if (label.hasAttribute("data-no-required-marker")) return false;
    const associated = label.htmlFor ? document.getElementById(label.htmlFor) : null;
    if (associated?.matches?.(REQUIRED_SELECTOR)) return true;
    return Boolean(label.querySelector(REQUIRED_SELECTOR));
  }

  function labelControlAnchor(label) {
    const control = label.querySelector(REQUIRED_SELECTOR);
    if (!control) return null;
    let anchor = control;
    while (anchor.parentElement && anchor.parentElement !== label) anchor = anchor.parentElement;
    return anchor;
  }

  function labelCaption(label, anchor) {
    const existing = Array.from(label.children).find(child => child.classList.contains("required-label-text"));
    if (existing) return existing;
    const caption = document.createElement("span");
    caption.className = "required-label-text";
    let node = label.firstChild;
    while (node && node !== anchor) {
      const next = node.nextSibling;
      caption.appendChild(node);
      node = next;
    }
    label.insertBefore(caption, anchor || label.firstChild);
    return caption;
  }

  function insertMarker(label) {
    if (label.querySelector(".req, .required-marker")) return;
    const marker = document.createElement("span");
    marker.className = "req required-marker";
    marker.textContent = "*";
    marker.setAttribute("aria-hidden", "true");
    marker.title = "Required";

    const anchor = labelControlAnchor(label);
    const caption = labelCaption(label, anchor);
    caption.append(document.createTextNode(" "), marker);
  }

  function syncLabel(label) {
    if (!(label instanceof HTMLLabelElement)) return;
    const automatic = label.querySelector(".required-marker");
    if (labelRequiresMarker(label)) insertMarker(label);
    else automatic?.remove();
  }

  function scan(root) {
    if (root instanceof Element && root.matches(REQUIRED_SELECTOR)) syncLabel(controlLabel(root));
    root.querySelectorAll?.(REQUIRED_SELECTOR).forEach(control => syncLabel(controlLabel(control)));
  }

  scan(document);
  new MutationObserver(function (mutations) {
    const labels = new Set();
    mutations.forEach(function (mutation) {
      if (mutation.type === "attributes") {
        const label = controlLabel(mutation.target);
        if (label) labels.add(label);
      }
      mutation.addedNodes.forEach(function (node) {
        if (node instanceof Element) scan(node);
      });
    });
    labels.forEach(syncLabel);
  }).observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ["required", "for"] });
})();
