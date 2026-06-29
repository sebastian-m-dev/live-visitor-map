# SM Live Visitor Map

[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php)](https://php.net)
[![License](https://img.shields.io/badge/License-GPLv2-green)](https://www.gnu.org/licenses/gpl-2.0.html)
[![GitHub release](https://img.shields.io/github/v/release/sebastian-m-dev/live-visitor-map)](https://github.com/sebastian-m-dev/live-visitor-map/releases)

Real-time visitor tracking with an interactive map, session analytics, and a glassmorphism admin dashboard.

![Dashboard](https://github.com/sebastian-m-dev/live-visitor-map/raw/main/assets/banner-1544x500.png)

## Features

- **Live Map** — See visitors in real-time on a Leaflet map. Green animated markers for active visitors, blue for the last 24 hours.
- **Real-Time Dashboard** — Auto-refreshing stats cards with active visitors, today's visits, unique visitors, sessions, and average session duration.
- **Charts** — Hourly traffic breakdown and 30-day daily trend via Chart.js.
- **Top Countries** — Country breakdown with flag icons.
- **Recent Visits** — Detailed table with IP, location, referrer, and page visited.
- **Glassmorphism UI** — Animated mesh background, gradient text reveals, micro-interactions.
- **SPA Tracking** — Works with pushState/replaceState SPAs.
- **Privacy** — IP anonymization, role-based exclusion, configurable data retention.
- **Lightweight** — ~1KB tracking script, pixel + REST API fallback, no external frontend deps.

## Requirements

- WordPress 5.0+
- PHP 7.4+

## Installation

1. Upload `live-visitor-map/` to `/wp-content/plugins/`
2. Activate via **Plugins** screen
3. Go to **Live Visitor Map** in your admin menu

Tracking starts automatically — no setup required.

## Settings

Navigate to **Live Visitor Map → Settings**:

- **Exclude Roles** — Select user roles to exclude from tracking
- **Data Retention** — Auto-delete visits older than N days (default: 90)
- **Anonymize IPs** — Store anonymized IPs (last octet set to 0)

## Changelog

### 1.1.0
- Glassmorphism UI with animated mesh background
- Settings page (role exclusion, data retention, IP anonymization)
- REST API tracking with pixel fallback
- Auto data purging via `wp_scheduled_delete`
- Plugin uninstall cleanup
- Optimized dashboard icon (1.1MB → 306 bytes)

### 1.0.0
- Initial release

## License

GPLv2 or later. See [LICENSE](LICENSE).
