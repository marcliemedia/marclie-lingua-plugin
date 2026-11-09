=== Marclie Lingua ===
Contributors: marclieagency
Tags: translation, multilingual, languages, hreflang, bricks
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.4
Stable tag: 0.3.0
License: Proprietary

Translates a WordPress site into other languages from editable string pairs, with language URLs, a language
switcher and SEO tags.

== Description ==

The site is written in one language (the source language). Marclie Lingua shows it in other languages by translating
the rendered page on the server, text by text, from translations stored in the database:

* each translated language has its own URLs: `/en/…`, `/de/…`; the source language keeps the site's URLs;
* one text = one translatable unit: an element's text with its inline tags (`<strong>`, `<a>` …), an attribute
  (`alt`, `placeholder`, `title`, `aria-label`) or a head meta (title, description, Open Graph);
* texts are collected while pages are viewed in a translated language and by "Scan the site";
* translations are edited in Translations (one tab per language) or imported / exported with WP-CLI;
* a text without a translation shows in the source language;
* blog posts (setting "Blog posts"): translated as whole posts (default — see "Translating blog posts"), text by text
  like the other pages, or — free and quick, but not indexed in the translated languages — in the visitor's browser
  (parts marked `data-mlg-skip`, Chrome / Edge Translator API).

== Translating blog posts ==

With "Translate whole posts" each post has, per language, its own title, URL slug, excerpt, content and SEO title /
description (Translations › Posts, or the "Translations" box on the post's edit screen):

* the editor shows the original next to the translation; "Start from original" copies it in;
* a draft is not shown; a published translation replaces the post's title, content, excerpt and SEO title /
  description on that language's pages; the theme around it (menus, buttons…) still comes from Translations › Texts;
* a translated slug moves the URL (`/en/vodic/novalja-beaches/`); the source slug and former slugs redirect (301);
  links, the switcher, hreflang, canonical and sitemaps use it;
* when the original is edited afterwards the translation stays online and is marked "Needs review" with what changed;
  "Mark as reviewed" clears it;
* lists of posts (blog page, categories, query loops) in a language show only the posts translated into it; a post
  opened in a language it is not translated into shows the original and is `noindex`;
* the site search in a language searches the translations;
* a translation split with `<!--nextpage-->` should have as many pages as the original (WordPress counts the
  original's pages).

Translating with AI (or a translator) outside WordPress:

    wp marclie-lingua post export --lang=de --untranslated --file=de.json
    # fill "translation" (title, slug, excerpt, content as HTML with the same tags, seo_title, seo_description)
    wp marclie-lingua post import de.json            # drafts; add --publish to publish
    wp marclie-lingua post export --lang=de --outdated --file=check.json   # posts edited since translated

== Installation ==

1. Upload the plugin to `wp-content/plugins/marclie-lingua` and activate it. Pretty permalinks must be on.
2. Translations › Settings: check the source language, tick the translated languages (the number sets the order in
   the switcher), choose the Marclie Booking mode and whether search engines may index translated pages.
3. Translations › Texts › "Scan the site" (or `wp marclie-lingua scan`), then translate the texts per language.
4. Add the language switcher: shortcode `[marclie_lingua_switcher]` or the Bricks element "Language switcher".

== Language switcher ==

`[marclie_lingua_switcher display="code" class=""]`

* `display` — `code` (button shows the short label, e.g. "EN") or `name` (the language's own name);
* `class` — extra classes on the wrapper.

The Bricks element has the same options plus icon visibility and menu alignment. The switcher prints its own small
CSS and JS. Its look follows these custom properties (with fallbacks): `--mlg-switch-radius`, `--mlg-switch-bg`,
`--mlg-switch-hover`, `--mlg-switch-shadow`, `--mlg-switch-active` … (see assets/switcher.css).

== SEO ==

With "Let search engines index the translated pages" on:

* hreflang alternates with language-only codes (`hr`, `en`, `de`) and `x-default` = the language chosen in the
  settings (the version for searchers whose language the site does not have);
* each page's canonical and `og:url` in its own language, `og:locale:alternate`;
* one sitemap per language (`/mlg-sitemap-{code}.xml`, listed in the Rank Math sitemap index or the WordPress core
  index);
* a translated page that still shows untranslated texts is `noindex, follow`, has no hreflang links, is left out of
  the other versions' links and of the sitemap — until every text on it is translated. Search engines detect the
  language from the visible text, so a page in two languages is a poor result. The state is recorded when the page
  is rendered; "Scan the site" renders every page in every language. Translations › Texts lists the hidden pages and
  the share of translated texts per language; names and codes that stay the same: bulk action "Keep original text".

Blog posts in browser mode are `noindex, follow` in translated languages with the source URL as canonical. With the
option off every translated page is `noindex, follow`.

== Marclie Booking ==

Mode "Automatic" turns the integration on when Marclie Booking supports it (`LanguageAdapter::languages()`):

* Booking receives the site languages (`marclie_booking/languages`) and Lingua's language driver
  (`marclie_booking/language_driver`: request language from the URL, page URLs with the prefix, `withLanguage()`
  for e-mails / cron);
* booking widgets are not string-translated (Booking prints them in the page language);
* booking funnel pages stay out of the language sitemaps.

Mode "Off": booking widgets are translated like other texts (parts rendered by script stay in the source language).

== WP-CLI ==

* `wp marclie-lingua scan` — request every public page in every translated language;
* `wp marclie-lingua export [<file>] --lang=<code> [--status=auto|edited] [--untranslated] [--seed]`;
* `wp marclie-lingua import <file> --lang=<code> [--status=auto|edited] [--overwrite]`;
* `wp marclie-lingua seed --lang=<code>` — import `data/{code}.json`.

`--lang` can be left out when the site has one translated language.

== For developers ==

Functions: `marclie_lingua_language()`, `marclie_lingua_languages()`, `marclie_lingua_url( $url, $lang = null )`.

Filters:

* `marclie_lingua_text` / `marclie_lingua_texts` — translate strings printed outside the HTML (e.g. JSON for a script);
* `marclie_lingua/attributes` — attributes holding copy (default `placeholder`, `title`, `alt`, `aria-label`);
* `marclie_lingua/skip_selectors` — CSS selectors never translated;
* `marclie_lingua/url_excluded` — `( bool $excluded, string $url )` keep a URL without prefix;
* `marclie_lingua/language_presets` — add or change selectable languages (add it in a must-use plugin: the
  language URLs are resolved before themes load);
* `marclie_lingua/post_types`, `marclie_lingua/blog_post_page` — what counts as a blog post;
* `marclie_lingua/browser_fixes` — `[ regex, flags, replacement ]` fixes of the in-browser translation;
* `marclie_lingua/scan_urls`, `marclie_lingua/sitemap_exclude_ids`, `marclie_lingua/capability`.

Markup: `data-mlg-skip` (blog part, browser translation), `data-mlg-no-rewrite` (keep links as they are),
`translate="no"` / `.notranslate` (never translated). Translated pages carry `<html data-mlg-lang data-mlg-prefix>`
for scripts that compare paths.

== Upgrade Notice ==

= 0.3.0 =
Blog posts can be translated as whole posts (new default for new sites; existing sites keep their setting: choose
"Translate whole posts" in Translations › Settings). Database 2 → 3 adds the table `mlg_post_translations`.

= 0.2.1 =
Better search results: language-only hreflang codes, an x-default language setting, incomplete translated pages kept
out of search engines, blog posts translatable like other pages. Run "Scan the site" after updating.

= 0.2.0 =
Back up the database first. Database 1 → 2 moves the 0.1 English translations into the new per-language table and
sets source `hr`, target `en`, blog posts in browser mode (switch to "Translate like the other pages" to
get them indexed). Language URLs change from `?lang=en` to `/en/…` (old URLs redirect).
Sites using Marclie Booking's `?lang` language must update Marclie Booking to a version with the language contract
at the same time. Site-specific attributes (`data-*` copy) and proper-name fixes now go through the filters
`marclie_lingua/attributes` and `marclie_lingua/browser_fixes`; scripts that match paths can use
`<html data-mlg-prefix>`.

== Changelog ==

See CHANGELOG.md.
