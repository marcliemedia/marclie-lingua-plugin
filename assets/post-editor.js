/* Marclie Lingua admin, whole-post translations.
   - Translation screen (window.mlgPostEditor): "Start from original" copies the original title, excerpt and
     content into the empty fields; live preview of the translated URL and "Use title" for the slug; character
     counts of the SEO fields; unsaved-changes warning; Ctrl/Cmd+S saves (draft, or update when published);
     confirmation before deleting, and before "Mark as reviewed" drops unsaved edits; one submission at a time.
     Links in the original preview open in a new tab and stay out of the Tab order (the preview scrolls as one
     focusable region).
   - Block editor (window.mlgMetabox): after each save of the post, refresh the "Translations" box and show a
     notice when published translations may need review. */
(function () {
  "use strict";

  const fill = (s, ...v) => v.reduce((out, x, i) => out.replace("%" + (i + 1) + "$s", x).replace("%s", x), s);

  const cfg = window.mlgPostEditor;
  const form = document.getElementById("mlg-post-form");
  if (cfg && form) {
    const t = cfg.i18n;
    const field = name => form.querySelector('[data-mlg-field="' + name + '"]');
    const textarea = document.getElementById(cfg.editorId);

    document.querySelectorAll(".mlg-prose a[href]").forEach(a => {
      a.target = "_blank";
      a.rel = "noopener";
      a.tabIndex = -1;
    });
    const editor = () => (window.tinymce ? window.tinymce.get(cfg.editorId) : null);
    const visual = () => { const ed = editor(); return ed && !ed.isHidden() ? ed : null; };

    let dirty = false;
    let submitting = false;
    const dirtyNote = form.querySelector("[data-mlg-dirty]");
    const markDirty = () => { dirty = true; if (dirtyNote) dirtyNote.hidden = false; };
    const isDirty = () => { const ed = editor(); return dirty || !!(ed && ed.isDirty()); };

    form.addEventListener("input", markDirty);
    form.addEventListener("change", markDirty);

    // the content editor (TinyMCE lives in an iframe: its events and shortcuts are separate)
    const shortcut = form.querySelector("[data-mlg-shortcut]");
    const onEditor = ed => {
      if (!ed || ed.id !== cfg.editorId) return;
      ed.on("input change undo redo", markDirty);
      ed.addShortcut("meta+s", "", () => { if (shortcut) shortcut.click(); });
    };
    if (window.jQuery) window.jQuery(document).on("tinymce-editor-init", (e, ed) => onEditor(ed));

    document.addEventListener("keydown", e => {
      if ((e.ctrlKey || e.metaKey) && !e.altKey && e.key.toLowerCase() === "s" && shortcut) {
        e.preventDefault();
        shortcut.click();
      }
    });

    form.addEventListener("submit", e => {
      const action = e.submitter ? e.submitter.value : "";
      if (submitting) { e.preventDefault(); return; }
      if (action === "delete" && !window.confirm(t.confirmDelete)) { e.preventDefault(); return; }
      if (action === "review" && isDirty() && !window.confirm(t.confirmReview)) { e.preventDefault(); return; }
      submitting = true;
      form.classList.add("is-busy");
    });

    // back/forward cache: the page comes back as it was left, mid-submission
    window.addEventListener("pageshow", e => {
      if (!e.persisted) return;
      submitting = false;
      form.classList.remove("is-busy");
    });

    window.addEventListener("beforeunload", e => {
      if (submitting || !isDirty()) return;
      e.preventDefault();
      e.returnValue = t.unsaved;
    });

    // Start from original: only empty fields are filled
    const copyBtn = form.querySelector("[data-mlg-copy]");
    const copyStatus = form.querySelector("[data-mlg-copy-status]");
    if (copyBtn) {
      copyBtn.addEventListener("click", () => {
        let copied = 0;
        ["title", "excerpt"].forEach(name => {
          const el = field(name);
          if (el && el.value.trim() === "" && cfg.source[name].trim() !== "") {
            el.value = cfg.source[name];
            copied++;
          }
        });
        const ed = visual();
        const current = ed ? ed.getContent() : (textarea ? textarea.value : "");
        if (current.trim() === "" && cfg.source.content.trim() !== "") {
          if (ed) { ed.setContent(cfg.source.content); ed.save(); } else if (textarea) textarea.value = cfg.source.content;
          copied++;
        }
        if (copied) markDirty();
        slugPreview();
        counts();
        if (copyStatus) copyStatus.textContent = copied === 0 ? t.copiedNone : copied === 1 ? t.copiedOne : fill(t.copied, String(copied));
      });
    }

    // URL slug: live preview, "Use title"
    const slug = form.querySelector("[data-mlg-slug]");
    const slugOut = form.querySelector("[data-mlg-url-slug]");
    // close to WordPress' sanitize_title(): Latin accents removed, other scripts kept
    const slugify = s => s
      .toLowerCase()
      .replace(/đ/g, "d").replace(/ß/g, "ss").replace(/æ/g, "ae").replace(/ø/g, "o").replace(/ł/g, "l")
      .normalize("NFD").replace(/([a-z])[\u0300-\u036f]+/g, "$1").normalize("NFC")
      .replace(/[^\p{L}\p{N}]+/gu, "-")
      .replace(/^-+|-+$/g, "");
    function slugPreview() {
      if (!slug || !slugOut) return;
      const value = slugify(slug.value);
      slugOut.textContent = value !== "" ? value : slugOut.dataset.fallback;
    }
    if (slug) {
      slug.addEventListener("input", slugPreview);
      slug.addEventListener("blur", () => { const v = slugify(slug.value); if (v !== slug.value) { slug.value = v; } });
      const fromTitle = form.querySelector("[data-mlg-slug-from-title]");
      const title = field("title");
      if (fromTitle && title) {
        fromTitle.addEventListener("click", () => {
          const v = slugify(title.value);
          if (v === "") { title.focus(); return; }
          slug.value = v;
          markDirty();
          slugPreview();
        });
      }
    }

    // SEO character counts
    const counters = Array.from(form.querySelectorAll("[data-mlg-count-for]"));
    function counts() {
      counters.forEach(c => {
        const input = document.getElementById(c.dataset.mlgCountFor);
        if (!input) return;
        const n = Array.from(input.value).length;
        const max = Number(c.dataset.max);
        const over = n > max;
        c.textContent = fill(t.count, String(n), String(max)) + (over ? " · " + t.tooLong : "");
        c.classList.toggle("is-over", over);
      });
    }
    counters.forEach(c => {
      const input = document.getElementById(c.dataset.mlgCountFor);
      if (input) input.addEventListener("input", counts);
    });
    counts();
  }

  // Block editor: the "Translations" box after a save
  const mb = window.mlgMetabox;
  if (mb && window.wp && window.wp.data) {
    const data = window.wp.data;
    let saving = false;
    const refresh = () => {
      const body = new URLSearchParams({ action: "mlg_post_states", nonce: mb.nonce, post: String(mb.post) });
      fetch(mb.ajaxUrl, { method: "POST", credentials: "same-origin", body: body })
        .then(r => r.json())
        .then(j => {
          if (!j || !j.success) return;
          const box = document.querySelector("[data-mlg-metabox]");
          if (box) box.innerHTML = j.data.html;
          const notices = data.dispatch("core/notices");
          notices.removeNotice("mlg-outdated");
          if (j.data.message) {
            notices.createWarningNotice(j.data.message, { id: "mlg-outdated", actions: [{ label: j.data.action, url: j.data.url }] });
          }
        })
        .catch(() => {});
    };
    data.subscribe(() => {
      const ed = data.select("core/editor");
      if (!ed) return;
      const now = ed.isSavingPost() && !ed.isAutosavingPost();
      if (now) { saving = true; return; }
      if (saving && !ed.isSavingPost()) {
        saving = false;
        if (ed.didPostSaveRequestSucceed()) refresh();
      }
    });
  }
})();
