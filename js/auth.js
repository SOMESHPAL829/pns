(function () {
  const overlay = document.getElementById("page-transition");
  const message = document.getElementById("page-transition-message");
  const note = document.getElementById("page-transition-note");

  function setPasswordVisibility(button, visible) {
    const input = document.getElementById(button.dataset.passwordToggle || "");
    if (!input) return;
    const subject = button.dataset.passwordSubject || "password";
    input.type = visible ? "text" : "password";
    button.setAttribute("aria-pressed", String(visible));
    button.setAttribute("aria-label", (visible ? "Hide " : "Show ") + subject);
    button.title = (visible ? "Hide " : "Show ") + subject;
    const icon = button.querySelector("[data-icon]");
    if (icon) icon.dataset.icon = visible ? "eye-slash" : "eye";
    window.ThemeIcons?.mount(button);
  }

  document.querySelectorAll("[data-password-toggle]").forEach(function (button) {
    const initialLabel = button.getAttribute("aria-label") || "Show password";
    button.dataset.passwordSubject = initialLabel.replace(/^Show\s+/i, "");
    button.addEventListener("click", function () {
      setPasswordVisibility(button, button.getAttribute("aria-pressed") !== "true");
    });
  });

  document.querySelectorAll("form").forEach(function (form) {
    form.addEventListener("reset", function () {
      window.setTimeout(function () {
        form.querySelectorAll("[data-password-toggle]").forEach(function (button) {
          setPasswordVisibility(button, false);
        });
      }, 0);
    });
  });

  function showLoading(text, detail) {
    if (!overlay) return;
    if (message) message.textContent = text;
    if (note) note.textContent = detail;
    overlay.hidden = false;
    document.body.classList.add("is-transitioning");
  }

  document.querySelectorAll("form[data-loading-form]").forEach(function (form) {
    form.addEventListener("submit", function () {
      if (!form.checkValidity()) return;
      const button = form.querySelector('button[type="submit"]');
      if (button) {
        button.disabled = true;
        button.textContent = "Signing In…";
      }
      showLoading("Signing In…", "Verifying your account securely.");
    });
  });

  document.querySelectorAll("form[data-logout-form]").forEach(function (form) {
    form.addEventListener("submit", function () {
      const button = form.querySelector('button[type="submit"]');
      if (button) button.disabled = true;
      showLoading("Signing Out…", "Closing your session securely.");
    });
  });

  const welcome = document.getElementById("welcome-message");
  if (welcome) {
    try {
      const text = JSON.parse(welcome.textContent || '""');
      if (text) window.AppToast?.show(text, "welcome");
    } catch (error) {
      // A malformed optional welcome message must never block the portal.
    }
  }

  const transient = document.querySelector(".auth-message-transient");
  if (transient) window.setTimeout(function () { transient.classList.add("is-fading"); }, 3200);
})();
