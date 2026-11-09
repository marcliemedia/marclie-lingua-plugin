/* Marclie Lingua — blog post parts on translated pages, translated in the visitor's browser (Blog.php).
   The server marks them `[data-mlg-browser][lang="{source}"]` and prints `window.mlgBrowser = { source, target,
   fixes }`. With the built-in Translator API (Chrome / Edge on a computer; the model runs on the device) they are
   translated at once when the model is already there, otherwise after the visitor's first click, tap or key press
   (browsers download the model only after a user action); no prompt is shown. Without the API the browser's own
   "Translate this page" covers single posts. Text nodes and alt / title / aria-label are translated; translations
   are cached in localStorage. A part done is marked with the target language (and the page too when it was a
   single post in the source language). */
(function () {
  "use strict";

  const cfg = window.mlgBrowser || {};
  const parts = Array.prototype.slice.call(document.querySelectorAll("[data-mlg-browser]"));
  if (!cfg.source || !cfg.target || !parts.length || !("Translator" in self)) return;

  const OPTIONS = { sourceLanguage: cfg.source, targetLanguage: cfg.target };
  const CACHE_KEY = "mlg-browser:" + cfg.source + "-" + cfg.target;
  const CACHE_MAX = 3000;
  const ATTRS = ["alt", "title", "aria-label"];
  const SKIP = "script, style, noscript, code, pre, svg, textarea";
  const WORD = /\p{L}/u;
  const FIXES = (cfg.fixes || []).map(f => {
    try { return [new RegExp(f[0], f[1]), f[2]]; } catch (e) { return null; }
  }).filter(Boolean);

  let cache = {};
  try { cache = JSON.parse(localStorage.getItem(CACHE_KEY) || "{}") || {}; } catch (e) { cache = {}; }
  let saveTimer = 0;
  const save = () => {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => {
      try {
        if (Object.keys(cache).length > CACHE_MAX) cache = {};
        localStorage.setItem(CACHE_KEY, JSON.stringify(cache));
      } catch (e) { /* quota or private mode: this page only */ }
    }, 400);
  };

  // the translation keeps the source's initial case (a fragment split off by a link stays lower case);
  // English "I" stays upper case
  const keepCase = (src, out) => {
    if (/^\p{Lu}/u.test(src) && /^\p{Ll}/u.test(out)) return out[0].toUpperCase() + out.slice(1);
    if (/^\p{Ll}/u.test(src) && /^\p{Lu}(?!\p{Lu})/u.test(out) && !(cfg.target === "en" && /^I\b/.test(out))) {
      return out[0].toLowerCase() + out.slice(1);
    }
    return out;
  };

  function translator() {
    return Translator.create(OPTIONS);
  }

  async function translateText(t, raw) {
    const text = raw.replace(/\u00a0/g, " ").trim();
    if (!text || !WORD.test(text)) return raw;
    let out = cache[text];
    if (out === undefined) {
      try { out = await t.translate(text); } catch (e) { return raw; }
      cache[text] = out;
      save();
    }
    out = keepCase(text, FIXES.reduce((s, f) => s.replace(f[0], f[1]), out));
    const lead = raw.match(/^[\s\u00a0]*/)[0], tail = raw.match(/[\s\u00a0]*$/)[0];
    return lead + out + tail;
  }

  async function translatePart(t, root) {
    const jobs = [];
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT, {
      acceptNode: n => n.nodeType === 1 && n.matches(SKIP) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT
    });
    for (let n = walker.currentNode; n; n = walker.nextNode()) {
      if (n.nodeType === 3) {
        const node = n;
        jobs.push(translateText(t, node.data).then(v => { node.data = v; }));
      } else {
        const el = n;
        ATTRS.forEach(a => {
          const v = el.getAttribute(a);
          if (v && WORD.test(v)) jobs.push(translateText(t, v).then(out => el.setAttribute(a, out)));
        });
      }
    }
    await Promise.all(jobs);
    root.lang = cfg.target;
  }

  async function run(t) {
    for (const part of parts) await translatePart(t, part);
    // a single blog post page (source-language head): its title and language follow the translated content
    const root = document.documentElement;
    if (root.lang.split("-")[0] === cfg.source) {
      document.title = await translateText(t, document.title);
      root.lang = cfg.target;
    }
  }

  Translator.availability(OPTIONS).then(state => {
    if (state === "unavailable") return;
    if (state === "available") return translator().then(run);
    // the model has to be downloaded: only after a user action, silently
    const events = ["pointerdown", "keydown", "touchend"];
    const go = () => {
      events.forEach(e => removeEventListener(e, go, true));
      translator().then(run).catch(() => {});
    };
    events.forEach(e => addEventListener(e, go, true));
  }).catch(() => {});
})();
