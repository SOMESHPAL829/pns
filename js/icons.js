/**
 * KARAM chrome icons — Heroicons outline (MIT).
 * Source: https://heroicons.com  (Outline, 24×24)
 * Style: viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"
 *
 * Use these SVGs in the sidebar / header. Do not use emoji for chrome.
 * To add more: copy an Outline icon from Heroicons and paste into ICONS.
 */
(function (global) {
  const svg = function (paths) {
    return (
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">' +
      paths +
      "</svg>"
    );
  };

  const ICONS = {
    dashboard: svg(
      '<path stroke-linecap="round" stroke-linejoin="round" d="M3 10.5 12 3l9 7.5"/><path stroke-linecap="round" stroke-linejoin="round" d="M5 9.75V20h5v-5h4v5h5V9.75"/>',
    ),
    requests: svg(
      '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z"/>',
    ),
    approvals: svg(
      '<path stroke-linecap="round" stroke-linejoin="round" d="m9 12 2 2 4-4"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 3a9 9 0 1 0 9 9"/>',
    ),
    report: svg(
      '<path stroke-linecap="round" stroke-linejoin="round" d="M4 19V5"/><path stroke-linecap="round" stroke-linejoin="round" d="M4 19h16"/><path stroke-linecap="round" stroke-linejoin="round" d="M8 15V9"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 17V7"/><path stroke-linecap="round" stroke-linejoin="round" d="M16 13v-2"/>',
    ),
    orgs: svg(
      '<path stroke-linecap="round" stroke-linejoin="round" d="M3 21h18"/><path stroke-linecap="round" stroke-linejoin="round" d="M6 21V7l6-4 6 4v14"/><path stroke-linecap="round" stroke-linejoin="round" d="M10 10h4"/><path stroke-linecap="round" stroke-linejoin="round" d="M10 14h4"/>',
    ),
    master: svg(
      '<ellipse cx="12" cy="6" rx="8" ry="3"/><path stroke-linecap="round" stroke-linejoin="round" d="M4 6v6c0 1.66 3.58 3 8 3s8-1.34 8-3V6"/><path stroke-linecap="round" stroke-linejoin="round" d="M4 12v6c0 1.66 3.58 3 8 3s8-1.34 8-3v-6"/>',
    ),
    workflows: svg(
      '<path stroke-linecap="round" stroke-linejoin="round" d="M7 7h4l2 2h4v10H7z"/><path stroke-linecap="round" stroke-linejoin="round" d="M7 7V5h4l2 2"/>',
    ),
    users: svg(
      '<path stroke-linecap="round" stroke-linejoin="round" d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path stroke-linecap="round" stroke-linejoin="round" d="M22 21v-2a4 4 0 0 0-3-3.87"/><path stroke-linecap="round" stroke-linejoin="round" d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    ),
    roles: svg(
      '<path stroke-linecap="round" stroke-linejoin="round" d="M12 3 4 7v6c0 5 3.4 8.74 8 10 4.6-1.26 8-5 8-10V7l-8-4z"/>',
    ),
    audit: svg(
      '<path stroke-linecap="round" stroke-linejoin="round" d="M8 6h13"/><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h13"/><path stroke-linecap="round" stroke-linejoin="round" d="M8 18h13"/><path stroke-linecap="round" stroke-linejoin="round" d="M3 6h.01"/><path stroke-linecap="round" stroke-linejoin="round" d="M3 12h.01"/><path stroke-linecap="round" stroke-linejoin="round" d="M3 18h.01"/>',
    ),
    apis: svg(
      '<path stroke-linecap="round" stroke-linejoin="round" d="M12 22v-5"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 8V2"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 8V2"/><path stroke-linecap="round" stroke-linejoin="round" d="M18 8v2a6 6 0 0 1-6 6 6 6 0 0 1-6-6V8Z"/>',
    ),
    logout: svg(
      '<path stroke-linecap="round" stroke-linejoin="round" d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path stroke-linecap="round" stroke-linejoin="round" d="m16 17 5-5-5-5"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 12H9"/>',
    ),
    bell: svg(
      '<path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2c0 .5-.2 1-.6 1.4L4 17h5"/><path stroke-linecap="round" stroke-linejoin="round" d="M9.5 17a2.5 2.5 0 0 0 5 0"/>',
    ),
    chevron: svg(
      '<path stroke-linecap="round" stroke-linejoin="round" d="M15 18 9 12l6-6"/>',
    ),
    menu: svg(
      '<path stroke-linecap="round" d="M4 7h16"/><path stroke-linecap="round" d="M4 12h16"/><path stroke-linecap="round" d="M4 17h16"/>',
    ),
    profile: svg(
      '<path stroke-linecap="round" stroke-linejoin="round" d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>',
    ),
    library: svg(
      '<path stroke-linecap="round" stroke-linejoin="round" d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path stroke-linecap="round" stroke-linejoin="round" d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
    ),
  };

  function mount() {
    document.querySelectorAll("[data-icon]").forEach(function (el) {
      const name = el.getAttribute("data-icon");
      if (ICONS[name]) el.innerHTML = ICONS[name];
    });
  }

  global.ThemeIcons = { ICONS: ICONS, mount: mount };
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", mount);
  } else {
    mount();
  }
})(window);
