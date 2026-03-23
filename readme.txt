=== TW Perf Intelligence ===
Contributors: techwonders
Tags: performance, pagespeed, defer, javascript, core web vitals
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0.5
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

DOM-aware JS/CSS optimisation -- defer, delay, unload scripts per page to reduce render-blocking and improve Core Web Vitals.

== Description ==

TW Perf Intelligence is a WordPress performance plugin built for agencies and developers managing JavaScript and CSS loading across client sites. Unlike generic performance plugins, it analyses the actual DOM on each page to determine whether an asset is genuinely needed -- and records live user interactions to confirm which scripts actually fire.

Deferring and delaying render-blocking scripts is one of the most effective ways to improve LCP (Largest Contentful Paint) and TBT (Total Blocking Time) -- two of Google's Core Web Vitals that directly affect search rankings. This plugin handles that at the WordPress asset level, per page, with safety checks built in.

**How it works**

1. Visit any page on your site while logged in as admin
2. Click TW Perf - Analyse This Page in the admin bar
3. The panel scans all enqueued scripts and styles and checks whether their DOM signatures (CSS selectors, data attributes) are present on the page
4. Each asset receives an automatic recommendation: Unload, Delay, Defer, Async CSS, or Keep
5. Use the Interaction Recorder to confirm which scripts actually fire during scroll, click, and form use
6. Enable Preview Mode to test changes before they go live -- only you see the optimised version
7. Apply individual rules or click "Apply All Safe Recommendations"
8. Run PSI Score to measure the impact on Core Web Vitals

**SEO and Core Web Vitals impact**

Google uses Core Web Vitals as a ranking factor. Render-blocking JS and CSS directly harm LCP and TBT scores. This plugin:

* Removes render-blocking scripts with defer (runs after HTML parse, before DOMContentLoaded)
* Removes render-blocking scripts with delay (runs after first user interaction)
* Removes render-blocking stylesheets with Async CSS (non-blocking preload + noscript fallback)
* Eliminates unused scripts and styles entirely with Unload
* Speeds up LCP element loading with targeted preloads and fetchpriority hints

**Actions explained**

* **Unload** -- Completely dequeues the asset. Use when DOM analysis confirms the asset's elements are absent.
* **Delay** -- Asset loads after first user interaction (scroll, click, touchstart). Best for below-fold features, sliders, analytics, cookie consent.
* **Defer** -- Adds `defer` attribute so script loads after HTML is parsed without blocking render.
* **Async CSS** -- Converts a blocking `<link>` to a non-render-blocking preload with noscript fallback.
* **Preload** -- Adds `<link rel="preload">` in `<head>` for critical assets and LCP images.
* **Keep** -- No change. Asset is confirmed active and needed above the fold.

**Interaction Recorder**

Record a live session on any page. The plugin patches `addEventListener` to detect which scripts actually fire during scroll, click, hover, and form interaction. After recording, scripts are marked "confirmed active" or left unconfirmed. This gives you real evidence to support unload decisions beyond DOM scanning alone.

**Intelligence engine**

The plugin ships with DOM signatures for 100+ common plugins and libraries:

* jQuery ecosystem (core, migrate, UI) with full dependency tree
* Sliders: Slick, Swiper -- detects duplicates and warns on conflicts
* Animation: AOS, GSAP, ScrollMagic, Parallax
* Filtering: Isotope, imagesLoaded
* Galleries: Magnific Popup, FancyBox, LightGallery, Envira, Modula, NextGen
* Forms: CF7, WPForms, Gravity Forms
* WooCommerce: all major handles including cart-fragments, blocks, checkout
* Page builders: Elementor, Spectra/UAGB, Beaver Builder, Divi
* Events: Tribe Events Calendar
* Tables: DataTables
* Maps: Google Maps, ACF Maps
* Cookie consent: CookieYes, Cookiebot, Cookie Notice
* Social: AddToAny, ShareThis
* Video: MediaElement, FitVids
* SEO: Rank Math, Yoast
* Analytics and more

15 dynamic handle patterns for page builder CSS/JS (Elementor post CSS, UAGB, Greenshift, Divi, WooCommerce Blocks, Gutenberg block styles).

Unknown handles are flagged for manual review rather than guessed.

**Scope system**

Rules can be set at three levels, which merge together:

* **Global** -- applies to every page on the site
* **Post type** -- applies to all pages of a given post type (e.g. all `page`, all `product`)
* **Page** -- applies to a single URL/post ID only

Page-level rules override post-type rules, which override global rules. Promote a page rule up to post type or global without re-entering it.

**Dependency Visualiser and Conflict Checker**

The dependency tree shows exactly what depends on jQuery or any other handle -- prevents breaking changes when unloading scripts. The conflict checker detects handles with conflicting actions across scopes and warns when delayed or unloaded scripts have active dependents.

**Test mode**

When test mode is enabled (default), rules only apply to logged-in admins who have activated Preview Mode via the admin bar toggle. All other visitors see the unmodified site. Disable test mode once you have validated the configuration.

**PageSpeed Insights integration**

Run a live PSI analysis from within the panel. Mobile and desktop strategy toggle. Lab metrics (LCP, FCP, TBT, CLS, INP, Speed Index) and real-user CrUX field data (LCP, INP, CLS, FCP, TTFB with good/needs improvement/poor thresholds). Render-blocking resources are matched back to their WP handles with one-click Fix buttons. Cached for 1 hour per URL.

**Quick Wins**

* Remove wp-emoji (saves one JS + one CSS request)
* Clean head bloat (removes generator, RSD, WLW, REST discovery, oEmbed links)
* Fix font-display: swap on all @font-face blocks
* Fix LCP image attributes (fetchpriority="high", remove loading="lazy")
* Remove render-blocking Google Fonts entirely
* Heartbeat API control (keep / frontend-only / everywhere)

**Critical CSS**

Inject above-the-fold CSS inline in `<head>` from the Tools tab. Use alongside Async CSS to eliminate render-blocking stylesheets entirely.

**Export / Import**

Export all rules to JSON and import them on another site. Merge mode adds new rules; Replace mode overwrites everything. Useful for rolling a tested configuration across multiple client sites.

**Cache integration**

After saving rules, the plugin automatically purges the cache on:
W3 Total Cache, WP Rocket, WP Super Cache, LiteSpeed Cache, Nginx Helper, Breeze, Autoptimize, FlyingPress, SG Optimizer, Hummingbird, Plesk/Nginx static cache.

== Installation ==

1. Upload the `tw-performance` folder to `/wp-content/plugins/`
2. Activate the plugin in WP Admin - Plugins
3. The plugin creates a `wp_twperf_rules` database table on activation
4. Visit Settings - TW Perf Intelligence to configure
5. Optionally add a Google PageSpeed Insights API key (free, from Google Cloud Console)
6. Visit the front-end of your site and use the admin bar panel

== Frequently Asked Questions ==

= Will this break my site? =

Test mode is enabled by default -- rules only apply to you while Preview Mode is active. Other visitors are unaffected until you are satisfied and disable test mode.

= My slider broke after applying rules. What do I do? =

Open the panel, go to the Rules tab for the affected page, find the slider handle and change its action from "unload" to "keep". Cache is purged automatically.

= jQuery is flagged but I can't unload it. =

Correct -- jQuery cannot be safely unloaded until all its dependents are also removed. The dependency tree viewer shows you exactly what depends on it. Remove or replace those scripts first.

= How do I know if a script is actually used? =

Use the Interaction Recorder. Record a session on the page while scrolling, clicking, and interacting with all features. Scripts that fire during the session are marked "confirmed active". Scripts that do not fire are safe unload candidates.

= How do I copy my config to another site? =

Panel - Tools - Export Rules - download the JSON. On the other site: Panel - Tools - Import Rules - paste and choose Merge or Replace.

= Does it work with page caching? =

Yes. Optimised assets are applied at the PHP level before caching, so cached pages include the correct defer/delay/unload state. Cache is purged automatically after saving rules.

= What PHP version is required? =

PHP 8.0 minimum. Uses named arguments, match expressions, and arrow functions.

== Changelog ==

= 1.0.5 =
* Added GitHub-native auto-updater -- plugin appears in WP Dashboard - Updates
* Added Mobile/Desktop strategy toggle to PSI Score button
* Added CrUX real-user field data to PSI results (LCP, INP, CLS, FCP, TTFB)
* Added INP (Interaction to Next Paint) to lab metrics -- replaced FID as Core Web Vital
* Fixed dashicons/admin-bar auto-protected when admin bar is visible
* Fixed delay default trigger events: scroll + touchstart + click
* Improved Settings and All Rules pages with modern card layout
* Improved delay vs defer explanation in Settings

= 1.0.4 =
* Plugin renamed to TW Perf Intelligence (slug unchanged: tw-performance)
* Added third-party asset detection -- external-origin scripts/styles flagged with "3rd party" badge
* Added "3rd party" filter button in Assets tab to show only external-origin assets
* Added auto-detected third-party origins card in Preloads tab with one-click preconnect buttons
* Added scope promotion in "This Page" rules tab -- change a page rule to post type or global via inline dropdown
* Added Remove Google Fonts quick win toggle -- strips render-blocking Google Fonts from HTML output
* Added Critical CSS textarea in Tools tab -- inject above-the-fold CSS directly into head
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
* DOM-aware asset analysis with 100+ plugin signatures
* Per-page, post-type, and global rule scoping
* Test mode with preview cookie
* PSI API integration with handle matching and one-click fixes
* Interaction Recorder to confirm active scripts during live sessions
* Dependency tree visualiser, conflict checker, export/import rules
* Cache purger for 11 caching plugins

== Upgrade Notice ==

= 1.0.5 =
Adds GitHub auto-updates, CrUX field data, INP metric, mobile/desktop PSI toggle. Safe to update.
