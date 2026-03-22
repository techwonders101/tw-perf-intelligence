# Changelog

All notable changes to TW Perf Intelligence are documented here.

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
