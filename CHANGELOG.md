# Changelog

All notable changes to TW Perf Intelligence are documented here.

## [1.1.0] - 2026-10-22

### Fixed
- Preserve stylesheet-relative font URLs when copying font-face rules into HTML, invalidate old cached overrides and insert missing CSS declaration separators.
- Preserve delayed script attributes and document order; protect registered inline initializers and immediate dependent scripts.
- Enqueue the delay loader through WordPress, use inert template placeholders and protect the loader from optimisation rules.
- Remove incorrect Google Fonts CSS-as-font preloads and correct manual font MIME types. Preloads remain global.
- Invalidate supported page caches after rendering-setting changes, covering panel and settings-form saves.
- Keep dashboard assets untouched and remove dashboard unload controls. Administrator frontend preview remains supported.
- Report the verified WordPress 7.1.3 compatibility consistently in update responses, plugin details and readme metadata; newer untested versions retain the normal warning.
- Build release ZIPs from runtime assets and public documentation only.
- Document the offloading false positive for removal of resource hints. The private GitHub updater remains supported.

### Validation
- PHP regressions, isolated browser loader tests, real staging WordPress integration, mobile/desktop font checks, and targeted Plugin Check code sniffs passed.

## [1.0.9] — 2026-06-29

### Added
- Excimer-powered PHP profiler — profile any wp-admin screen or frontend page from the TW Perf admin-bar menu when `ExcimerProfiler` is available
- New TW Perf → Profiler page with recent reports, inline top sampled functions, and downloads for Speedscope JSON, collapsed stacks, and summary JSON
- PHP Profiler settings for enable/disable, sampling period, stack depth, and report retention
- Profile reports stored in a private server-side directory with random report IDs and automatic retention cleanup
- Local Google Fonts scanner in Settings — scans representative homepage/archive/post/page/product templates, downloads Google CSS/font files into a public uploads cache, and rewrites matching frontend Google Fonts URLs to local cached stylesheets
- Local Google Fonts cache status now lists the font family names detected and cached

### Changed
- TW Perf now has its own top-level admin menu with Settings, Rules, and Profiler subpages

### Security
- Profiler capture, downloads, and deletion are restricted to `manage_options` users and nonce-protected admin requests; report files are served only through the admin download handler

---

## [1.0.8] — 2026-05-07

### Added
- Distinct post-type scope targets: single posts (`post`), post archives/categories/tags (`post_archive`), single products (`product`), WooCommerce archives (`wc_archive`), single pages (`page`), custom post types and their archives — rules now apply precisely to the page type they were saved on, not to all pages sharing the same post type
- `TW_Perf_Rules::get_current_post_type_target()` — derives the correct scope target from the current WP query context; used by both the frontend rule engine and the panel's localised config
- Per-scope rule indicators on every asset row — coloured pills show which scopes (Global, Post type, This page) already have a rule saved for that asset and what action it applies
- Tooltip on each scope indicator clarifies that `keep` suppresses a broader inherited rule
- Collapsible plugin groups in the Assets tab — click any group header to collapse or expand its asset rows; state preserved across filter changes and re-analyses
- Recommendations bar (summary pills + pending list) auto-hides when scrolling into the asset list and reappears when scrolling back to the top

### Changed
- Scope dropdown labels are now context-aware: category/tag archives show "All post archives", WooCommerce shop/product-category/product-tag show "All WooCommerce archives", single posts show "All single posts", single products show "All single products"
- Scope dropdown hides the post-type option on cart, checkout, account, front page, search, and 404 where no meaningful post-type scope exists
- Saving a rule from the Assets tab now atomically replaces any existing rule for that asset at a different scope — no more orphan rules when changing scope
- Scope-change in the Rules tab uses the same atomic approach — eliminates orphan rules if a follow-up delete had failed
- Rule precedence enforced: a `keep` action at page or post-type scope suppresses the same asset's action inherited from a broader scope
- Admin All Rules page and panel rules tabs show human-readable post-type scope labels ("All post archives", "All WooCommerce archives", "All single products", etc.) instead of raw DB target strings
- `TW_Perf_Rules::post_type_label()` updated to handle all new target strings including `{cpt}_archive` patterns for custom post types

### Fixed
- `get_for_current_page()` keep-rule lookup used raw `get_post_type()` — `keep` rules saved for `post_archive` or `wc_archive` were not suppressing inherited actions on archive pages
- `get_preview_only_for_panel()` used raw `get_post_type()` — preview-only rules for archive-specific scopes were not returned correctly
- `fetch_rules_structured()` was missing `preview_only = 0` and context filters — preview-only and admin-context rules were leaking into the frontend current-rules response
- `fetch_keep_handles_ajax()` was not filtering by context — an admin-only `keep` rule could incorrectly suppress a frontend `defer`
- CSS selector injection risk in collapsible group click handler — plugin folder names containing `"` would break the `querySelector` string; replaced with `dataset` equality scan

---

## [1.0.7] — 2026-05-06

### Added
- Assets tab groups scripts and styles by plugin folder slug, with theme assets in a separate group below plugins
- Child theme and parent theme detected and labelled separately within the theme group
- Theme assets hidden by default — toggle via "Hide theme" filter pill, same pattern as "Hide WP core"
- "This Type" rules tab showing all saved rules for the current post type
- "Save Live" button alongside "Test in Preview" for low-confidence recommendations — skips the preview flow when you want to apply directly
- `plugin_slug` column added to `wp_twperf_rules` DB table — plugin folder persisted at rule-save time for reliable future label resolution (DB version bumped to 1.0.3, migrated automatically)
- Known plugin slug → name map expanded to 35+ entries (WPC Smart Quick View, Yoast SEO, Revolution Slider, WPBakery, Rank Math, and more)
- Pre-optimisation src capture — registered scripts/styles snapshotted at `wp_enqueue_scripts` priority 998, before the asset optimizer deregisters them at 999; used as final fallback for plugin label resolution

### Changed
- Per-asset scope selector (This page / Post type / Global) moved inline to each asset row, chosen at review time rather than globally at the top of the panel
- Filter pills simplified — removed Unload / Delay / Defer / Review / Manual action filters; kept: All, JS only, CSS only, 3rd party, Hide WP core, Hide theme
- All panel `<select>` elements have `font-size`, `width`, `min-width`, `max-width`, `box-sizing`, and `flex-shrink` protected with `!important` to prevent WooCommerce/theme CSS overrides
- Admin all-rules page: Handle column fixed at 200 px with text wrapping; group header labels use lighter muted style to distinguish from content
- Scope and context select font sizes increased to 12 px for readability; context select widened from 82 px to 110 px

### Fixed
- Plugin labels missing in All Rules frontend tab — the tab was using its own inline render that only read `plugin_label` from the AJAX response, bypassing all fallbacks; now uses shared `resolveRulePluginLabel()` helper
- Plugin labels missing for assets deregistered by the optimizer — captured before deregistration via priority 998 hook
- `handle_get_rules()` AJAX endpoint not enriching rows with `plugin_label` from the intelligence map (only `handle_get_all_rules()` did so)
- Inline/virtual handles with no file src (e.g. `woocommerce-inline`) no longer appear in the asset list
- `unset($row)` missing after by-reference `foreach` in `handle_get_all_rules()` — latent corruption risk if method is extended
- Removed `zipball_url` fallback in auto-updater — if the named `tw-performance.zip` asset is absent from a release, no update is offered rather than silently installing an unbuilt source archive
- All `confirm()` popups removed from rule-save flows

---

## [1.0.6] — 2026-03-29

### Fixed
- Panel header showing a random product URL instead of the archive URL on WooCommerce category/tag/shop archive pages — `get_permalink()` now only used on singular pages
- Panel header URL overflowing on long paths — truncates with ellipsis; header wraps on narrow panels

---

## [1.0.5] — 2026-03-26

### Added
- GitHub-native auto-updater — plugin appears in WP Dashboard → Updates; one-click install
- Mobile/Desktop strategy toggle on PSI Score button
- CrUX real-user field data in PSI results (LCP, INP, CLS, FCP, TTFB)
- INP (Interaction to Next Paint) metric in lab results — replaces FID as Core Web Vital

### Fixed
- Dashicons and admin-bar assets auto-protected when admin bar is visible
- Delay default trigger events corrected: scroll + touchstart + click

### Improved
- Settings and All Rules pages redesigned with modern card layout
- Delay vs defer explanation clarified in Settings

---

## [1.0.4] — 2026-03-22

### Changed
- Plugin renamed from **TW Performance** to **TW Perf Intelligence** (slug `tw-performance` unchanged — no reinstall needed)

### Added
- **Third-party asset detection** — scripts and stylesheets loaded from external origins are flagged with an amber "3rd party" badge in the Assets list
- **"3rd party" filter button** in Assets tab — one click to show only external-origin assets
- **Auto-detected third-party origins** card in Preloads tab — after running Analyse, detected external origins appear with one-click "+ Preconnect" buttons
- **Scope promotion** in "This Page" rules tab — the rule scope label is now an inline dropdown; change a saved page rule to _post type_ or _global_ without re-applying it
- **Remove Google Fonts** quick win toggle — strips render-blocking `fonts.googleapis.com` `<link>` tags and `@import` rules from HTML output at buffer level
- **Critical CSS** input in Tools tab — paste above-the-fold CSS to inject as an inline `<style>` at the top of `<head>` (priority 1)
- Full preload URLs now wrap instead of being truncated in the Preloads tab

### Fixed
- `saveQuickWin()` was passing an object as the first argument to `ajax()` instead of a string action name
- PSI metric display value inserted into innerHTML without XSS escaping

---

## [1.0.3] — 2026-03-15

### Added
- **Frontend / admin / both context selector** for unload rules — unload an asset on the public frontend only, wp-admin only, or everywhere
- **Font Preloads** section in Preloads tab — preload self-hosted `.woff2` fonts with optional crossorigin attribute
- **Preconnect Origins** section in Preloads tab — add `<link rel="preconnect">` hints for external domains
- **WP Quick Wins** card in Tools tab with live toggles: Remove Emoji, Clean `<head>`, WP Heartbeat control, Font Display Fix, LCP Attribute Fix
- **"Hide WP core"** filter toggle in Assets tab — hides `wp-includes` scripts (React, i18n, hooks) by default
- **MutationObserver live tracking** — DOM observations accumulate from page load so dynamically injected elements (sliders, AJAX content) are never missed
- `context` column added to `wp_twperf_rules` table (upgrade handled automatically via `maybe_upgrade()`)

### Fixed
- Assets injected after `wp_enqueue_scripts` fires (Spectra/UAG inline CSS, image gallery scripts) not appearing in analysis
- Above-fold detection false positives on `display:none` gallery elements reporting `top=0`
- Dynamic plugin handles (`uag-css-557`, `gs-style-1234`) not matching intelligence signatures — regex pattern matching added
- Font preload AJAX handler returning a PHP error instead of JSON on missing option
- `stable_tag` mismatch in readme.txt

### Security
- `$_POST['crossorigin']` sanitised with `sanitize_key()` in font preload and preconnect handlers
- Input allowlist added for `rule_type`, `action`, and `asset_type` in `handle_save_rule()` and `handle_save_bulk()` — prevents arbitrary values reaching the database
- PSI metric display value now escaped before insertion into innerHTML
- `maybe_upgrade()` DDL statements covered with `esc_sql()` on table name and `phpcs:disable` block

---

## [1.0.2] — 2026-03-10

### Added
- Constant redefinition guard — all plugin constants (`TWPERF_VERSION`, `TWPERF_DIR`, etc.) wrapped in `if (!defined(...))` to prevent fatal errors when two versions are simultaneously active

### Fixed
- Build script (`build.sh`) was zipping plugin files at archive root instead of inside a `tw-performance/` folder — fixed by `cd`-ing to parent directory before zipping

---

## [1.0.0] — 2026-03-01

### Added
- DOM-aware asset analysis — each asset's output is verified against CSS selectors before recommending unload
- Intelligence engine with 50+ DOM signatures for common plugins and libraries (jQuery, Slick, Swiper, AOS, CF7, WPForms, Elementor, Spectra/UAGB, WooCommerce, and more)
- Per-page, post-type, and global rule scoping with merge/override precedence
- Test mode — rules only apply to admins in Preview Mode; visitors see the unmodified site
- Preview Mode cookie toggle in admin bar and panel toolbar
- PageSpeed Insights integration — live PSI analysis, render-blocking resource matching, one-click "Fix" buttons
- Dependency tree visualiser — shows what depends on a script before you unload it
- Conflict checker — detects known problematic handle combinations
- Export / import rules as JSON (merge or replace modes)
- Cache purger — auto-purges after saving rules for: W3TC, WP Rocket, WP Super Cache, LiteSpeed Cache, Nginx Helper, Breeze, Autoptimize, FlyingPress, SG Optimizer, Hummingbird
- Forced reflow fixer for sticky-header scripts (wraps scroll/resize handlers in `requestAnimationFrame`)
- Site-wide All Rules admin page with bulk delete
