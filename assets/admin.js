/* Marclie Lingua admin: site scan (one page per request, progress in the status line) and an unsaved-changes
   warning for the translation form (note in the sticky save bar, prompt when leaving the page). */
(function () {
  "use strict";

  const cfg = window.mlgAdmin;
  if (!cfg) return;
  const t = cfg.i18n;
  const fill = (s, ...v) => v.reduce((out, x, i) => out.replace("%" + (i + 1) + "$s", x).replace("%s", x), s);

  function post(action, data) {
    const body = new URLSearchParams(Object.assign({ action: action, nonce: cfg.nonce }, data || {}));
    return fetch(cfg.ajaxUrl, { method: "POST", credentials: "same-origin", body: body })
      .then(r => r.json())
      .then(j => { if (!j || !j.success) throw new Error(action); return j.data; });
  }

  const btn = document.querySelector("[data-mlg-scan]");
  const status = document.querySelector("[data-mlg-scan-status]");
  if (btn && status) {
    const busy = on => { btn.disabled = on; btn.classList.toggle("is-busy", on); };
    btn.addEventListener("click", async () => {
      busy(true);
      status.textContent = t.starting;
      try {
        const { urls } = await post("mlg_scan_start");
        const failed = [];
        const scan = url => post("mlg_scan_url", { url: url }).catch(() => ({ ok: false, status: "" }));
        for (let i = 0; i < urls.length; i++) {
          status.textContent = fill(t.progress, String(i + 1), String(urls.length));
          let r = await scan(urls[i]);
          if (!r.ok) r = await scan(urls[i]); // one retry: a busy local server can drop a request
          if (!r.ok) failed.push(urls[i] + (r.status ? " (" + r.status + ")" : ""));
        }
        await post("mlg_scan_finish", { failed: failed.length ? "1" : "" });
        if (failed.length) {
          status.textContent = fill(t.failed, String(failed.length)) + " " + failed.join(", ");
          busy(false);
          return;
        }
        window.location.reload();
      } catch (e) {
        status.textContent = t.error;
        busy(false);
      }
    });
  }

  const form = document.getElementById("mlg-form");
  if (form) {
    let dirty = false;
    const note = form.querySelector("[data-mlg-dirty]");
    form.addEventListener("input", e => {
      if (!e.target.matches(".mlg-translation")) return;
      dirty = true;
      if (note) note.hidden = false;
    });
    form.addEventListener("submit", () => { dirty = false; });
    window.addEventListener("beforeunload", e => {
      if (!dirty) return;
      e.preventDefault();
      e.returnValue = t.unsaved;
    });
  }
})();
