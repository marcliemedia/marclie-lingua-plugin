# Changelog

## 0.3.0 — 2026-11-09

### Added
- Whole-post translations for blog posts (setting "Blog posts" → "Translate whole posts", default on new sites):
  title, content, excerpt, slug, SEO title / description per language (table `mlg_post_translations`), drafts and
  publishing, "Needs review" flag with a diff of the original and "Mark as reviewed".
- Admin: Translations › Posts (status per post and language) and a side-by-side translation editor; a "Translations"
  box on the post edit screen. Refreshed Texts and Settings screens.
- Translated post slugs (`mlg_paths`): incoming URLs, links, switcher, hreflang, canonical and sitemaps; the source
  slug and former slugs redirect (301).
- Lists of posts in a language show only posts translated into it; searches in a language search the translations.
- WP-CLI `wp marclie-lingua post export|import` for translating posts outside WordPress (e.g. AI drafts).

### Changed
- Blog mode `stored` is now called `strings`.

## 0.2.1 — 2026-11-09

### Added
- Pages that still show untranslated texts in a language are `noindex, follow`, without hreflang links, left out of
  the other versions' hreflang and of the language sitemap until fully translated (option `mlg_incomplete`).
  Admin: share of translated texts per language, list of hidden pages, bulk action "Keep original text".
- Setting "x-default": the language version for searchers whose language the site does not have.
- Setting "Blog posts": translated like other pages (stored, indexable — default) or in the visitor's browser.

### Changed
- hreflang uses language-only codes (`hr`, `en`, `de`) instead of region codes (`hr-HR`, `en-GB`, `de-DE`).
- "Scan the site" renders every page in every translated language (records each page's completeness).

## 0.2.0 — 2026-11-09

Generic multi-language plugin (0.1.0 was built for one site, Croatian → English).

### Added
- Settings screen (Translations › Settings): source (default) language, any number of translated languages and
  their order, Marclie Booking integration (automatic / on / off), indexing of translated pages.
- Language URLs: each translated language under its own prefix (`/en/`, `/de/` …), source language at the site's
  own URLs. Internal links, form actions and redirects keep the page's language. `?lang=xx` (0.1 URLs) and
  `/{source}/…` redirect (301) to the right URL.
- Translations stored per language (`{prefix}mlg_translations`); admin tabs per language; WP-CLI `--lang=<code>`
  for `export`, `import`, `seed`; seed files `data/{code}.json`.
- SEO: hreflang alternates with x-default, canonical and `og:url` of the page's own language, `og:locale:alternate`,
  one sitemap per language (Rank Math index or WordPress core sitemaps), or `noindex, follow` when indexing is off.
- Language switcher: shortcode `[marclie_lingua_switcher]` and Bricks element "Language switcher", with its own
  CSS / JS / icons (works on any theme).
- Marclie Booking integration through the filters `marclie_booking/languages` and `marclie_booking/language_driver`.
- Public functions `marclie_lingua_language()`, `marclie_lingua_languages()`, `marclie_lingua_url()`.
- Filters `marclie_lingua/language_presets`, `marclie_lingua/skip_selectors`, `marclie_lingua/url_excluded`,
  `marclie_lingua/browser_fixes`, `marclie_lingua/sitemap_exclude_ids`.

### Changed
- The request language comes from the URL prefix, no longer from Marclie Booking's `?lang` / `mbk_lang` cookie.
- Translated attributes by default: `placeholder`, `title`, `alt`, `aria-label` (site-specific `data-*` attributes
  move to the filter `marclie_lingua/attributes`).
- Marclie Booking widget selectors are skipped only while the Booking integration is on.
- In-browser blog translation follows the site's languages; proper-name fixes come from `marclie_lingua/browser_fixes`.

### Removed
- Site-specific copy and behaviour of 0.1.0.
- Filter `marclie_lingua/language` (the language is the URL's).

### Upgrade from 0.1.0
Database 1 → 2 runs on the first load: English translations move to `mlg_translations` (status kept), settings
become source `hr`, target `en`. Back up the database first. See readme.txt › Upgrade notice.

## 0.1.0 — 2026-11-04
First version (one site, Croatian → English).
