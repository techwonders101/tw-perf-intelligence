=== TW Perf Intelligence ===
Contributors: techwonders
Tags: performance, defer, asset optimization, pagespeed, javascript
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0.4
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Intelligent asset optimisation — defer, delay, unload JS/CSS per page with DOM-aware recommendations and PageSpeed Insights integration.

== Description ==

TW Perf Intelligence is an internal TechWonders tool for managing JavaScript and CSS loading across WordPress client sites. Unlike generic performance plugins, it analyses the actual DOM on each page to determine whether an asset is genuinely needed, rather than asking you to manually identify handles.

**How it works**

1. Visit any page on your site while logged in as admin
2. Click TW Perf → Analyse This Page in the admin bar
3. The panel scans all enqueued scripts and styles and checks whether their DOM signatures (CSS selectors) are present on the page
4. Each asset receives an automatic recommendation: Unload, Delay, Defer, Async CSS, or Keep
5. Enable Preview Mode to test changes before they go live — only you see the optimised version
6. Apply individual rules or click "Apply All Safe Recommendations"
7. Run PSI Score to measure the impact

**Actions explained**

* **Unload** — Completely dequeues the asset on this page (or globally). Use for plugins whose elements are not present.
* **Delay** — Asset loads after first user interaction (scroll, click, keydown). Best for below-fold features, sliders, analytics.
* **Defer** — Adds `defer` attribute so script loads after HTML is parsed without blocking render.
* **Async CSS** — Converts a blocking `<link>` to a non-render-blocking preload with noscript fallback.
* **Preload** — Adds `<link rel="preload">` in `<head>` for critical assets and LCP images.
* **Keep** — No change. Asset is confirmed active and needed above fold.

**Intelligence engine**

The plugin ships with DOM signatures for 50+ common plugins and libraries:

* jQuery ecosystem (core, migrate, UI)
* Sliders: Slick, Swiper — detects duplicates automatically
* Animation: AOS, GSAP
* Filtering: Isotope, imagesLoaded
* Forms: CF7, WPForms, Gravity Forms
* WooCommerce: all major handles including cart-fragments
* Page builders: Elementor, Spectra/UAGB
* Theme handles: sticky-header, greenpanel, greensyncpanels, greentooltip
* Analytics, cookie consent, social sharing, video, maps, and more

Unknown handles are flagged for manual review rather than guessed.

**Scope system**

Rules can be set at three levels, which merge together:

* **Global** — applies to every page on the site
* **Post type** — applies to all pages of a given post type (e.g. all `page`, all `post`)
* **Page** — applies to a single URL/post ID only

Page-level rules override post-type rules, which override global rules.

**Test mode**

When test mode is enabled (default), rules only apply to logged-in admins who have activated Preview Mode via the admin bar toggle. All other visitors see the unmodified site. Disable test mode once you've validated the configuration.

**PageSpeed Insights integration**

Run a live PSI analysis from within the panel. Render-blocking resources are matched back to their WP handles, and one-click "Fix" buttons apply the appropriate optimisation automatically. Cached for 1 hour per URL to avoid burning API quota.

**Reflow fixer**

Automatically patches the forced-reflow pattern in sticky-header scripts that read `offsetWidth` after DOM changes. Wraps scroll/resize handlers in `requestAnimationFrame` to batch DOM reads. Fixes 100–200ms reflow costs common in premium themes.

**Export / Import**

Export all rules to JSON and import them on another site. Useful for rolling a tested configuration across multiple client sites. Merge mode adds new rules; Replace mode overwrites everything.

**Cache integration**

After saving rules, the plugin automatically purges the cache on:
W3 Total Cache, WP Rocket, WP Super Cache, LiteSpeed Cache, Nginx Helper, Breeze, Autoptimize, FlyingPress, SG Optimizer, Hummingbird.

== Installation ==

1. Upload the `tw-performance` folder to `/wp-content/plugins/`
2. Activate the plugin in WP Admin → Plugins
3. The plugin creates a `wp_twperf_rules` database table on activation
4. Visit Settings → TW Perf Intelligence to configure
5. Optionally add a Google PageSpeed Insights API key (free, from Google Cloud Console)
6. Visit the front-end of your site and use the admin bar panel

== Frequently Asked Questions ==

= Will this break my site? =

Test mode is enabled by default — rules only apply to you while Preview Mode is active. Other visitors are unaffected until you're satisfied and disable test mode.

= My slider broke after applying rules. What do I do? =

Open the panel, go to the Rules tab for the affected page, find the slider handle and change its action from "unload" to "keep". Or delete the rule from All Rules. Cache is purged automatically.

= jQuery is flagged but I can't unload it. =

Correct — jQuery cannot be safely unloaded until all its dependents are also removed. The dependency tree viewer shows you exactly what depends on it. Remove or replace those scripts first.

= How do I copy my config to another site? =

Panel → Tools → Export Rules → download the JSON. On the other site: Panel → Tools → Import Rules → paste and choose Merge or Replace.

= Does it work with page caching? =

Yes. Optimised assets are applied at the PHP level before caching, so cached pages include the correct defer/delay/unload state. Cache is purged automatically after saving rules.

= What PHP version is required? =

PHP 8.0 minimum. Uses named arguments, match expressions, and arrow functions.

== Changelog ==

= 1.0.4 =
* Plugin renamed to TW Perf Intelligence (slug unchanged: tw-performance)
* Added third-party asset detection — external-origin scripts/styles flagged with "3rd party" badge
* Added "3rd party" filter button in Assets tab to show only external-origin assets
* Added auto-detected third-party origins card in Preloads tab with one-click preconnect buttons
* Added scope promotion in "This Page" rules tab — change a page rule to post type or global via inline dropdown
* Added Remove Google Fonts quick win toggle — strips render-blocking Google Fonts from HTML output
* Added Critical CSS textarea in Tools tab — inject above-the-fold CSS directly into &lt;head&gt;
* Fixed full preload URLs now shown without truncation in Preloads tab

= 1.0.3 =
* Added frontend / admin / both context selector for unload rules
* Added Font Preloads and Preconnect Origins sections to Preloads tab
* Added WP Quick Wins card with live toggles (emoji, clean head, heartbeat, font-display, LCP attrs)
* Added "Hide WP core" filter toggle in Assets tab
* Fixed assets injected after wp_enqueue_scripts not appearing in analysis
* Fixed above-fold detection false positives on hidden gallery elements
* Fixed dynamic handles (uag-css-557, gs-style-123) not matching intelligence signatures
* Security: sanitised crossorigin POST field, input allowlist on DB writes, XSS fix in PSI display

= 1.0.0 =
* Initial release
* DOM-aware asset analysis with 50+ plugin signatures
* Per-page, post-type, and global rule scoping
* Test mode with preview cookie
* PSI API integration with handle matching and one-click fixes
* Dependency tree visualiser, conflict checker, export/import rules
* Cache purger for 9 caching plugins

== Upgrade Notice ==

= 1.0.4 =
Plugin renamed to TW Perf Intelligence. No database changes. Safe to update.
