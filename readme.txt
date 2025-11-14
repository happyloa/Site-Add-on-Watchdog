=== WP Plugin Watchdog ===
Contributors: pluginwatchdog
Tags: security, plugins, monitoring, notifications
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Monitor installed plugins for security notices, outdated releases, and WPScan disclosures without leaking your site's plugin inventory.

== Description ==

WP Plugin Watchdog keeps an eye on your site's plugins and warns you when:

* Your installed version is two or more minor releases behind the directory build.
* The official changelog mentions security or vulnerability fixes.
* (Optional) WPScan lists open CVEs for the plugin when you provide your own API key.

The plugin runs on a schedule you control—choose daily, weekly, or rely on manual scans—and stores results locally. Nothing leaves your site unless you explicitly configure outgoing notifications.

=== Privacy first ===

* No plugin inventory or telemetry is ever sent off-site by default.
* Optional webhooks are opt-in and only post the detected risks.
* WPScan lookups only run when you add your personal API token.

=== Admin tools ===

* Dashboard page with the current risk list and manual scan button.
* Ignore list to suppress noisy plugins.
* Notification settings for email, Discord, or a generic webhook.

=== Notifications ===

* Email: send to one or more recipients (comma separated).
* Discord: post to a channel via webhook.
* Generic webhook: post JSON payload to any endpoint you control, with optional HMAC signatures. Failed deliveries are logged and highlighted on the Watchdog admin screen so you can reconfigure or resend manually.

_Future enhancement:_ automatic retries for failed webhook deliveries are on the roadmap. Track progress in [issue #42](https://github.com/pluginwatchdog/wp-plugin-watchdog/issues/42).

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install via the admin dashboard.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Visit Tools → Watchdog to review the risk table and adjust notifications.
4. (Optional) Add your WPScan API key in the settings to fetch vulnerability intelligence.

== FAQ ==

= Does this plugin share my list of installed plugins? =

No. All scanning happens locally. Data only leaves your site if you enable a webhook or Discord notification yourself.

= How do I get a WPScan API key? =

Register for a free account at [wpscan.com](https://wpscan.com/) and copy the API token from your profile. Paste the token into the Watchdog settings page to enable vulnerability lookups.

= Can I trigger scans manually? =

Yes. Use the "Run manual scan" button on the Watchdog admin page.

== Changelog ==

= 0.3.0 =
* Add schedule frequency options for daily, weekly, or manual-only scans.
* Improve webhook delivery with signature headers and better error handling.
* Refresh the admin results table with sortable columns and clearer status badges.

= 0.2.0 =
* Prefill email notification recipients with site administrators.
* Refresh email layout with HTML formatting including version details.

= 0.1.0 =
* Initial release.
