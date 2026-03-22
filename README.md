# TW Perf Intelligence

**WordPress performance plugin — DOM-aware JS/CSS optimisation with per-page rules, PageSpeed Insights integration, and GitHub auto-updates.**

[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue?logo=wordpress)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php)](https://php.net)
[![License](https://img.shields.io/badge/license-GPL--2.0%2B-green)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version](https://img.shields.io/badge/version-1.0.5-blue)](https://github.com/techwonders101/tw-perf-intelligence/releases)

---

## What it does

TW Perf Intelligence scans your WordPress site's enqueued scripts and styles on each page and tells you exactly which ones are safe to unload, defer, or delay — based on what's actually in the DOM, not guesswork.

Unlike generic performance plugins that apply blanket rules, this plugin detects whether each asset's elements (CSS selectors, data attributes) are present on the current page before suggesting any action.

---

## Key Features

### DOM-Aware Intelligence Engine
- Analyses 50+ common plugin and library signatures (jQuery, WooCommerce, Elementor, Slick, Swiper, CF7, GSAP, AOS, and more)
- Detects duplicate slider libraries automatically
- Flags third-party (external-origin) assets with a "3rd party" badge
- Unknown handles marked for manual review — never blindly guessed

### Per-Page Rule Scoping
- **Global** rules apply site-wide
- **Post type** rules apply to all pages of a given type (e.g. all `page`, all `product`)
- **Page** rules apply to a single URL or post ID
- Rules merge with page-level taking precedence

### Asset Actions
| Action | What it does |
|--------|-------------|
| **Unload** | Completely dequeues the asset on the matched scope |
| **Defer** | Adds `defer` attribute — script loads after HTML parse, doesn't block render |
| **Delay** | Script loads only after first user interaction (scroll, click, touchstart) |
| **Async CSS** | Converts blocking `<link>` to non-render-blocking preload with noscript fallback |
| **Preload** | Adds `<link rel="preload">` in `<head>` for LCP images and critical assets |

### Safe Preview Mode
- Test rules before they go live — only you see the optimised version
- All other visitors see the unmodified site until you're ready
- Per-rule **Go Live** button promotes individual rules from preview to live
- Panel shows "Preview Only" state clearly

### PageSpeed Insights Integration
- Run a live PSI analysis from the admin bar panel
- Mobile and desktop strategy toggle
- Lab metrics: LCP, FCP, TBT, CLS, Speed Index
- **Real-user (CrUX) field data**: LCP, INP, CLS, FCP, TTFB with good/needs improvement/poor thresholds
- Render-blocking resources matched back to WP handles with one-click Fix buttons
- Results cached 1 hour per URL to avoid burning API quota

### Quick Wins (one-click toggles)
- Disable emojis (saves one JS + one CSS request)
- Clean `<head>` (removes RSD, WLW, shortlink, REST meta)
- Reduce heartbeat frequency (saves ~1 AJAX request/15s for non-editors)
- Add `font-display: swap` to Google Fonts
- Add `fetchpriority="high"` + `loading="eager"` to LCP image
- Remove render-blocking Google Fonts entirely

### Preloads & Preconnects
- Font preloads (`<link rel="preload" as="font">`) managed from the panel
- Auto-detected third-party origins with one-click `preconnect` buttons

### Dependency Visualiser
- See exactly which scripts depend on jQuery or any other handle
- Prevents breaking changes by showing the full dependency tree

### Export / Import
- Export all rules to JSON — import on another site
- Merge mode (add new rules) or Replace mode (full overwrite)
- Useful for rolling a tested config across multiple client sites

### Cache Integration
Automatically purges cache after saving rules on:
W3 Total Cache · WP Rocket · WP Super Cache · LiteSpeed Cache · Nginx Helper · Breeze · Autoptimize · FlyingPress · SG Optimizer · Hummingbird

### Reflow Fixer
Patches the forced-reflow pattern in sticky-header scripts (reads `offsetWidth` after DOM changes). Wraps scroll/resize handlers in `requestAnimationFrame` — fixes 100–200ms reflow costs common in premium themes.

---

## Installation

1. Download `tw-performance.zip` from the [latest release](https://github.com/techwonders101/tw-perf-intelligence/releases/latest)
2. Go to **WP Admin → Plugins → Add New → Upload Plugin**
3. Activate the plugin
4. Go to **Settings → TW Perf Intelligence** to configure
5. Optionally add a [Google PageSpeed Insights API key](https://developers.google.com/speed/docs/insights/v5/get-started) (free)
6. Visit the front end of your site — use the **TW Perf** panel in the admin bar

### Auto-updates
Once installed, the plugin checks GitHub releases automatically and appears in **Dashboard → Updates** like any other WordPress plugin. No manual downloads needed for future versions.

---

## Recommended Workflow

1. **Quick Wins first** — enable the one-click toggles in Settings (safe for all sites)
2. **Preload LCP image** — find your hero image, add a preload in the Preloads tab
3. **Preload local fonts** — add `<link rel="preload" as="font">` for any locally hosted fonts
4. **Analyse each key page** — open the panel, click Analyse, review recommendations
5. **Record a session** — scroll the full page, interact with menus, forms, and widgets so the DOM tracker can detect which scripts actually fire
6. **Apply recommendations** — use Preview Mode to test, then Go Live on what works

> **Rule of thumb**: For JS you're unsure about — use **Defer**. For CSS you're unsure about — use **Async CSS**. Only **Unload** when the DOM analysis confirms the asset's elements are absent.

---

## Requirements

- WordPress 6.0+
- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.3+

---

## FAQ

**Will this break my site?**
Test mode is on by default. Rules only apply to you while Preview Mode is active — other visitors see the unmodified site until you're confident and disable test mode.

**My slider broke after applying rules. What do I do?**
Open the panel → Rules tab → find the slider handle → change action from "unload" to "keep". Cache is purged automatically.

**jQuery is flagged but I can't unload it.**
Correct. jQuery can't be safely removed until all dependents are also removed. The dependency tree shows exactly what needs to go first.

**Does it work with page caching?**
Yes. Rules apply at the PHP level before caching, so cached pages include the correct optimised state. Cache is purged automatically after saving rules.

**Can I copy my config to another site?**
Panel → Tools → Export Rules → download the JSON. On the other site: Import Rules → paste → choose Merge or Replace.

**Is it safe to have a public repo?**
Yes. The plugin contains no API keys, secrets, or credentials. Users supply their own PSI API key in WP Admin, stored in `wp_options` on their own server.

---

## Changelog

### 1.0.5
* Added GitHub-native auto-updater — plugin appears in WP Dashboard → Updates
* Added Mobile/Desktop strategy toggle to PSI Score button
* Added CrUX real-user field data to PSI results (LCP, INP, CLS, FCP, TTFB)
* Added INP (Interaction to Next Paint) to lab metrics — replaced FID as Core Web Vital
* Fixed dashicons/admin-bar auto-protected when admin bar is visible (prevents toolbar breaking)
* Fixed ghost button hover text contrast
* Fixed delay default trigger events: scroll + touchstart + click
* Improved Settings and All Rules pages with modern card layout
* Improved delay vs defer explanation in Settings

### 1.0.4
* Plugin renamed to TW Perf Intelligence (slug unchanged: tw-performance)
* Added third-party asset detection with "3rd party" badge and filter
* Added auto-detected third-party origins card in Preloads tab
* Added scope promotion for page rules (promote to post type or global)
* Added Remove Google Fonts quick win toggle
* Added Critical CSS textarea in Tools tab

### 1.0.3
* Added frontend / admin / both context selector for unload rules
* Added Font Preloads and Preconnect Origins sections
* Added WP Quick Wins card
* Added "Hide WP core" filter toggle
* Multiple bug fixes and security improvements

### 1.0.0
* Initial release

---

## License

GPL-2.0+ — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html)

Built by [TechWonders / PC Buddy 247](https://techwonders.co.uk)
