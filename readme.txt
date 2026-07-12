=== Precision Analytics ===
Contributors: orchardgrovemedia
Tags: google analytics, ga4, analytics, custom dimensions, consent mode
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.4.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Precise Google Analytics 4 — custom dimensions, traffic sampling, Consent Mode v2, and a cached reporting layer you can reuse anywhere on the site.

== Description ==

Precision Analytics gives you the high-leverage parts of a heavyweight analytics
plugin — rich custom dimensions, sampling, consent — without the bloat, the
account broker, or the upsell nags. You bring your own Google credentials; your
data never passes through anyone else's servers.

**What it does**

* **GA4 tracking** via `gtag.js`, or push everything to the **dataLayer** for
  Google Tag Manager instead.
* **Custom dimensions** — send the author, post type, category, post ID, page
  type, logged-in status, user role, and published year as GA4 event parameters,
  with a built-in helper that lists the exact names to register in GA4.
* **Sampling** — track only a percentage of visitors (a global send-rate), with
  per-segment overrides (e.g. always track `post_type:product`) and exclusions
  for admins, roles, logged-in users, or specific user IDs. The decision is
  sticky per visitor, so a session is never split half-tracked.
* **Consent Mode v2** — emit Google's consent defaults (denied in the EEA by
  default) and expose a one-call JS hook so any consent banner can grant.
* **A reusable reporting layer** — a background sync pulls GA4 Data API reports
  on a schedule and caches them, so a dashboard widget, the `[pa_popular_posts]`
  shortcode/block, and the `pa_popular_posts()` template tag can all show things
  like "most popular post in the last 12 hours" with zero per-visitor API calls.

**Privacy & ownership**

No telemetry, no external account service. Reporting authenticates directly to
Google with your own service account (or, later, OAuth). Credentials can be kept
out of the database entirely via the `PA_GA4_SERVICE_ACCOUNT_JSON` constant.

== Installation ==

1. Upload the `precision-analytics` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen.
3. Visit **Precision Analytics** in the admin menu, enter your GA4 Measurement
   ID, and (optionally) configure attributes, sampling, consent, and reporting.

== Frequently Asked Questions ==

= Do I need a Google Cloud project? =

Only for the optional **reporting** layer (reading data back into WordPress).
Plain tracking just needs your GA4 Measurement ID. For reporting, create a Google
service account, grant its email Viewer access on your GA4 property, and paste the
JSON key — no interactive login required.

= Is the "12 hours" data real-time? =

It's near-real-time. The GA4 Data API has some processing latency and today's
numbers aren't finalized for a day or two, so very recent minutes can lag. For a
truly live count you'd need GA4's Realtime API, which isn't part of this release.

= Does sampling change GA4's own report sampling? =

No. This sampling controls how many visitors send data at all (to cut volume and
noise). It is unrelated to the sampling GA4 applies inside its own reports.

== Changelog ==

= 0.4.0 =
* **Reporting cache hardening.** Popular-posts windows you actually use (e.g.
  `window="48h"`) are now remembered and kept fresh by the scheduled sync —
  previously only the four standard windows were refreshed, so other lists were
  fetched once and then went permanently stale.
* Cache keys are now per window only: `post_type` filtering happens at read
  time, so filtered lists no longer trigger duplicate GA4 API calls or store
  duplicate payloads.
* Window inputs are canonicalized (`48H` → `48h`; unparseable values fall back
  to `7d`), so typo'd shortcode attributes can't mint junk cache entries.
* GA4 API errors are negative-cached (default 60s, filter
  `precision_analytics/error_retry_delay`), and the retry window is armed
  **before** a live fetch — so an outage or a cold cache can't stampede Google
  with one blocking call per page view.
* Stored rankings are capped at 500 rows per window to keep the cache option
  lean, and cached reports are cleared once on upgrade (the sync re-warms them
  within one interval).
* Uninstall now also removes the cached reports and the window registry.

= 0.3.1 =
* **Fixed tracking on pages with no custom dimensions (e.g. the homepage):** the
  tag emitted `gtag('config', id, [])`, which GA4 silently ignores — no pageview
  was sent. Config parameters now always serialise as a JSON object (`{}`).

= 0.3.0 =
* Consent Mode v2 is now **off by default** — built for US sites/audiences, GA4
  collects from everyone out of the box.
* Fixed "EEA only": it now emits a worldwide `granted` default before the
  EEA-scoped `denied` override, so non-EEA visitors (e.g. the US) are tracked
  without a consent banner. Previously a region-scoped denial with no worldwide
  baseline silently dropped non-EEA hits.
* Rewrote the Consent tab with a US-guidance callout and clearer per-option help.

= 0.2.0 =
* Admin restyle to the Orchard Grove Media design system: branded header (icon
  mark + version pill), refreshed cards (12px radius, soft shadow), green accent
  and focus rings, and a refined tab bar. No functional changes.

= 0.1.0 =
* Initial release: GA4 tracking (gtag.js / GTM dataLayer), custom dimensions,
  traffic sampling with per-segment rules and exclusions, Consent Mode v2, and a
  cached GA4 Data API reporting layer feeding a dashboard widget, the
  `[pa_popular_posts]` shortcode/block, and a `pa_popular_posts()` template tag.
