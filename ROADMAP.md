# Precision Analytics — Roadmap

Status legend: ✅ done · 🔜 planned · 💭 idea

## v0.1.0 (current)
- ✅ GA4 tracking via `gtag.js`
- ✅ GTM dataLayer transport mode
- ✅ Custom dimensions (author, post type, category, tags, post ID, page type,
  logged-in, user role, published year) → GA4 event parameters
- ✅ "Register these in GA4" helper listing param names + scope
- ✅ Sampling: global send-rate, per-segment rule overrides, exclusions
  (admins/roles/logged-in/user IDs), sticky per-session decision
- ✅ Consent Mode v2 defaults + `window.precisionAnalytics.updateConsent()` hook
- ✅ Reporting data layer: service-account auth, scheduled sync, cache
- ✅ Consumers: dashboard widget, `[pa_popular_posts]` shortcode + block,
  `pa_popular_posts()` / `pa_get_report()` template tags
- ✅ Optional Events module (outbound links, file downloads)
- ✅ WP-CLI: `wp precision-analytics sync|status`

## Next
- 🔜 **GA4 Admin API auto-create** of custom dimensions (one click instead of
  registering each parameter by hand in GA4)
- 🔜 **Interactive OAuth** auth method (Connect-with-Google) behind the existing
  `AuthInterface`, for users who don't want to make a service account
- 🔜 **Read-only REST endpoint** exposing the cached rankings for headless/JS use
- 🔜 More report shapes (top authors, top categories, traffic sources) reusing
  the same sync + cache pipeline

## Later / ideas
- 💭 **Server-side Measurement Protocol** transport (ad-blocker resistant,
  server-known attribution) behind the same dimension model
- 💭 Realtime API surface for a true "live now" counter
- 💭 Settings export/import (excluding secrets)
- 💭 Per-post-type sampling presets and a visual rule builder
- 💭 Anomaly / spike notifications from the sync job
