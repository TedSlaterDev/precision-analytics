=== Precision Analytics ===
Contributors: orchardgrovemedia
Tags: google analytics, ga4, analytics, custom dimensions, consent mode
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.5.1
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
Google — either **Sign in with Google (OAuth)** using your own Google Cloud OAuth
client, or a service-account key. Credentials can be kept out of the database
entirely via the `PA_GA4_SERVICE_ACCOUNT_JSON`, `PA_GA4_OAUTH_CLIENT_ID`, and
`PA_GA4_OAUTH_CLIENT_SECRET` constants.

== Installation ==

1. Upload the `precision-analytics` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen.
3. Visit **Precision Analytics** in the admin menu, enter your GA4 Measurement
   ID, and (optionally) configure attributes, sampling, consent, and reporting.

== Frequently Asked Questions ==

= Do I need a Google Cloud project? =

Only for the optional **reporting** layer (reading data back into WordPress).
Plain tracking just needs your GA4 Measurement ID. For reporting you choose one of
two methods, both using your own Google project — nothing is brokered through us:
**Sign in with Google (OAuth)**, where you create an OAuth client and click
Connect; or a **service account**, where you grant its email Viewer access on the
GA4 property and paste the JSON key (no interactive login required).

= Is the "12 hours" data real-time? =

It's near-real-time. The GA4 Data API has some processing latency and today's
numbers aren't finalized for a day or two, so very recent minutes can lag. For a
truly live count you'd need GA4's Realtime API, which isn't part of this release.

= Does sampling change GA4's own report sampling? =

No. This sampling controls how many visitors send data at all (to cut volume and
noise). It is unrelated to the sampling GA4 applies inside its own reports.

== Changelog ==

= 0.5.1 =
* Fixed the Reporting tab's Authentication dropdown still labelling OAuth
  "coming soon" — it now reads "Sign in with Google (OAuth)". Cosmetic only;
  the option worked in 0.5.0.

= 0.5.0 =
* **Sign in with Google (OAuth)** is now a real option on the Reporting tab —
  no service-account JSON required. You create an OAuth client in your own
  Google Cloud project (the tab shows the exact redirect URI to paste), click
  **Connect Google Analytics**, and approve access. There is no third-party
  broker: the refresh token is stored on your own site and can be revoked with
  **Disconnect**.
* The client ID and secret can be kept out of the database entirely via the
  `PA_GA4_OAUTH_CLIENT_ID` / `PA_GA4_OAUTH_CLIENT_SECRET` constants, and the
  secret field is write-only (its stored value is never rendered into the page).
* Uninstall now also removes the stored OAuth tokens.

= 0.4.1 =
* Attributes tab: every attribute now has an optional **GA4 parameter name**
  override, so a site can keep an existing dimension flowing under its old name
  — e.g. send the author as MonsterInsights' `author` instead of `post_author`
  — with no reporting gap. Names are validated against GA4's rules (letters,
  digits, underscores; no reserved prefixes; 40 chars max).
* Fixed: user-scoped attributes (logged-in status, user role) were sent as event
  parameters, so a *user-scoped* GA4 dimension registered for them — as the
  plugin's own helper instructs — stayed "(not set)". They are now delivered as
  GA4 user properties.

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
