(function () {
  const overlay = document.getElementById("page-transition");
  const message = document.getElementById("page-transition-message");
  const note = document.getElementById("page-transition-note");

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
