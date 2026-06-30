=== SM Live Visitor Map ===
Contributors: sebastian-m-dev
Tags: analytics, visitor map, real-time, tracking, live map, geo location, dashboard, glassmorphism
Requires at least: 5.0
Tested up to: 7.0
Stable tag: 1.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Simple and powerfull real-time visitor tracking with a live map showing visitor locations, session analytics, and an interactive dashboard with animated glassmorphism UI.

== Description ==

Simple and powerfull , SM Live Visitor Map gives you a real-time view of your website visitors on an interactive map. Track visitor locations, session durations, page views, and more — all from a beautiful glassmorphism admin dashboard with animated mesh background.

= Key Features =

* **Live Map** — See where your visitors are in real-time on a Leaflet map. Green animated markers for active visitors, blue markers for the last 24 hours.
* **Real-Time Dashboard** — Auto-refreshing stats cards with active visitors, today's visits, unique visitors, sessions, and average session duration.
* **Hourly & Daily Charts** — Visual breakdown of traffic patterns throughout the day and over the last 30 days.
* **Top Countries** — See which countries your visitors come from.
* **Recent Visits** — Detailed table of recent visitors with IP, location, and page visited.
* **Animated UI** — Glassmorphism cards with gradient reveals, animated mesh background, and micro-interactions.
* **Works on Any Theme** — Uses pixel-based tracking (GET requests) with REST API fallback — no dependency on `wp_footer()`.
* **Privacy Friendly** — Uses ip-api.com for geo-location (free tier, no API key). Geo data is cached for 24 hours. Optional IP anonymization.
* **Lightweight** — Minimal footprint. Does not load external resources on the frontend. Tracking script is under 1KB.
* **Settings Page** — Exclude user roles from tracking, configure data retention, enable IP anonymization.

= No Setup Required =

Just activate the plugin and visit the **Live Visitor Map** page in your WordPress admin. Tracking starts automatically.

== Installation ==

1. Upload the `live-visitor-map` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to **Live Visitor Map** in your admin menu to see the dashboard

== Frequently Asked Questions ==

= Does it work with caching plugins? =

Yes. The tracking uses pixel-based GET requests that bypass most caching layers including LiteSpeed Cache, WP Rocket, and W3 Total Cache.

= Does it work if my theme doesn't call wp_footer()? =

Yes. Tracking is injected via `wp_head` using a direct `<script>` tag. No dependency on `wp_footer()`.

= Where is geo-location data from? =

The plugin uses the free ip-api.com service (no API key required). Responses are cached as WordPress transients for 24 hours to minimize API calls.

= Does it slow down my site? =

No. The tracking script is under 1KB and is loaded asynchronously. The tracking request is lightweight with both REST API and pixel fallback.

= Can I exclude certain user roles from tracking? =

Yes. Go to **Live Visitor Map → Settings** in your WordPress admin to select which user roles should not be tracked.

= How long is visit data kept? =

By default, data is kept for 90 days. You can change this in **Live Visitor Map → Settings**.

== Screenshots ==

1. Live Visitor Map dashboard with real-time map, stats, charts, and glassmorphism UI.
2. Visitor location markers with popup details (IP, city, country, page).

== Changelog ==

= 1.1.0 =
* Added glassmorphism UI with animated mesh background
* Added settings page (exclude roles, data retention, IP anonymization)
* Added REST API tracking with pixel fallback
* Added automatic data purging via wp_scheduled_delete
* Added plugin uninstall cleanup
* Optimized dashboard icon (1.1MB → 306 bytes)
* Improved SPA routing detection

= 1.0.0 =
* Initial release
