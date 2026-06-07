# Precision Analytics — Specification

A lean WordPress plugin for precise Google Analytics 4: rich custom dimensions,
traffic sampling, Consent Mode v2, and a cached GA4 Data API reporting layer
whose results are reusable across the site. Built to the conventions of Heirloom
SEO (PSR-4, modular, no runtime Composer deps, PHP 8.1+, WP 6.0+).

## Identity
- Namespace: `OrchardGrove\PrecisionAnalytics`
- Text domain / slug: `precision-analytics`
- Single option row: `precision_analytics` (autoloaded), read via `Settings\Options`
- Constants: `PRECISION_ANALYTICS_VERSION|_FILE|_DIR|_URL|_BASENAME`

## Module map
`Plugin` (singleton) boots enabled modules, each implementing `ModuleInterface`:

- **Tracking\Tracking** — on `wp_head` (priority 1) decides whether to emit
  output by combining `Context::isTrackable()`, a configured Measurement ID, and
  `Sampling\Sampler::shouldTrack()`. Delegates to `Gtag` or `DataLayer` by the
  `general.transport` option, and prints `Consent` defaults first.
- **Tracking\Gtag** — prints the `gtag.js` loader, `consent default`, then
  `config <ID>` carrying the `Dimensions` parameter map (plus `debug_mode` when
  enabled).
- **Tracking\DataLayer** — GTM mode: prints the container snippet and a
  `dataLayer.push` of the same parameter map.
- **Tracking\Dimensions** — registry mapping each enabled attribute to a GA4
  event-parameter name and scope. `collect(Context)` returns `name => value` for
  the current request. The registry is the single source of truth shown in the
  Attributes tab's "register in GA4" helper.
- **Tracking\Consent** — builds the Consent Mode v2 `default` command
  (analytics/ad storage, EEA region scoping, `wait_for_update`, url passthrough)
  and ships a tiny inline `window.precisionAnalytics.updateConsent()` helper.
- **Tracking\Events** *(optional, off by default)* — outbound-link and
  file-download events; warns that GA4 Enhanced Measurement may already cover them.
- **Sampling\Sampler** — `shouldTrack(Context)` resolves: hard **exclusions**
  (admin/role/logged-in/user ID) → first matching **per-segment rule** rate →
  **global rate**. The yes/no is deterministic per visitor via a salted hash of
  request signals (IP + user agent + daily rotation, never stored), so a visitor
  is consistently in or out of the sample without needing a cookie set before
  headers. Override the seed with the `precision_analytics/sample_seed` filter.
- **Reporting\Reporting** — registers the sync cron, dashboard widget, shortcode,
  and block. Front-end consumers only ever read the cache, never the API.
- **Reporting\Auth\{AuthInterface,ServiceAccountAuth,OAuthAuth}** —
  `access_token()` abstraction. `ServiceAccountAuth` signs an RS256 JWT
  (`Support\ServiceAccountToken`, dependency-free `openssl_sign`) and exchanges it
  for a token scoped `analytics.readonly`, cached to expiry. `OAuthAuth` is a stub.
- **Reporting\DataApiClient** — `runReport()` against
  `analyticsdata.googleapis.com` for a property.
- **Reporting\Sync** — WP-Cron (`precision_analytics_sync`, default 900s). Runs
  the configured queries and stores them through `Cache`.
- **Reporting\Cache** — transient TTL cache keyed by query signature, with
  last-good fallback when the API errors.
- **Reporting\Reports** — typed reads from cache: `summary()` and
  `popularPosts($window, $count, $post_type)`; maps GA4 rows back to `WP_Post`
  via `customEvent:post_id` (preferred) or `pagePath` + `url_to_postid()`.
- **Reporting\{Widget,Shortcode,Block}** + `template-tags.php` — consumers.
- **Settings\SettingsPage** — tabbed admin UI (General, Attributes, Sampling,
  Consent, Reporting) with a single merge-safe sanitize callback.
- **Cli\Commands** — `wp precision-analytics sync|status`.

## Tracking output contract
For a trackable, sampled-in request with Measurement ID `G-XXX` and attributes
author/post_type/post_id enabled on a single post:

```html
<!-- gtag mode -->
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}
gtag('consent','default',{analytics_storage:'denied',ad_storage:'denied',region:[...EEA],wait_for_update:500});
gtag('js',new Date());
gtag('config','G-XXX',{post_author:'Ted Slater',post_type:'post',post_id:'123'});</script>
<script async src="https://www.googletagmanager.com/gtag/js?id=G-XXX"></script>
```

GA4 only makes a parameter queryable once a matching **custom dimension** exists
in the property; the Attributes tab lists each `param_name` + scope to register.

## Data model (`precision_analytics` option)
`general` (measurement_id, transport, gtm_id, debug_mode) · `attributes` (per-key
bool) · `sampling` (enabled, rate, exclude_logged_in, exclude_roles[],
exclude_user_ids, rules) · `consent` (enabled, analytics_default, ad_default,
eea_only, url_passthrough, wait_for_update) · `reporting` (enabled, property_id,
auth_method, service_account_json, sync_interval, widget_enabled) · `events` ·
`advanced` (delete_data_on_uninstall). Defaults live in `Options::defaults()`.

## Sampling rule grammar
`sampling.rules` is a textarea, one rule per line: `attribute:value:rate`, e.g.
`post_type:product:100` or `author:ted:100`. Supported attributes: `post_type`,
`post_id`, `page_type`, `author`, `category`, `tag`, `logged_in`, `user_role`.
Rate is 0–100. First match wins; no match falls through to `sampling.rate`.

## Reporting freshness & quotas
The standard Data API is near-real-time (today not finalized for ~24–48h); "last
12 hours" sums the `dateHour` dimension. Sync-and-cache means front-end surfaces
never call Google, keeping within per-property API quotas. A true live counter
(Realtime API) is out of scope for v0.1.0.

## Non-goals (v0.1.0)
Universal Analytics; an account/OAuth broker service; server-side Measurement
Protocol; in-editor content analysis; a full MonsterInsights-style reports area.
