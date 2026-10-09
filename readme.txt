=== Site Add-on Watchdog ===
Contributors: aaronhsieh
Tags: security, plugins, monitoring, notifications
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.8.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Monitor installed plugins for security notices, outdated releases, and optional version-aware WPScan disclosures.

== Description ==

Site Add-on Watchdog keeps an eye on your site's plugins and warns you when:

* A newer release is available for an installed plugin, with an additional warning when it is two or more minor releases behind.
* The latest release's official changelog mentions security or vulnerability fixes.
* (Optional) WPScan reports vulnerabilities affecting the installed version when you provide your own API key.

The plugin runs on a schedule you control—choose daily, weekly, a twenty-minute testing cadence, or rely on manual scans—and stores results locally. To compare public versions and changelogs, Watchdog looks up plugin slugs individually on WordPress.org and caches successful responses for six hours. WPScan lookups are optional. Email risk alerts to site administrators are enabled by default; webhook channels require setup and activation.

=== Privacy first ===

* Risk processing and storage stay on your site; Watchdog does not send telemetry, site content, or user data.
* WordPress.org receives a request for each installed plugin slug when its cached directory data expires so Watchdog can retrieve public version and changelog data.
* WPScan receives one plugin-slug lookup at a time only when you add your personal API token.
* Email risk alerts to site administrators are enabled by default and can be disabled in settings. Webhook channels send detected plugin risks only after you configure and enable them.

=== External services ===

Watchdog uses the following external services under the stated conditions:

* **WordPress.org Plugin API (required for directory comparisons):** When cached data is unavailable, Watchdog sends each installed plugin slug separately to retrieve its public version and changelog. No site content or user data is included. See the [WordPress.org service](https://api.wordpress.org/) and [privacy policy](https://wordpress.org/about/privacy/).
* **WPScan API (optional):** When you save a WPScan API token, Watchdog sends that token as authorization and submits one plugin slug at a time to retrieve vulnerability records. See [WPScan](https://wpscan.com/), its [terms](https://wpscan.com/terms/), and the applicable [Automattic privacy policy](https://automattic.com/privacy/).
* **Notification destinations:** Email alerts to site administrators are enabled by default and can be disabled. Discord, Slack, Microsoft Teams, and custom webhooks are optional and send only when configured and enabled. Alerts can include plugin names, installed and available versions, risk or vulnerability details, and links back to your WordPress administration area. Those transmissions are governed by your mail provider or destination service; review the applicable policies for [Discord](https://discord.com/terms) ([privacy](https://discord.com/privacy)), [Slack](https://slack.com/terms-of-service) ([privacy](https://slack.com/trust/privacy/privacy-policy)), or [Microsoft](https://www.microsoft.com/servicesagreement) ([privacy](https://privacy.microsoft.com/privacystatement)).

=== Admin tools ===

* Focused dashboard with risk summaries, searchable history, delivery health, and manual actions.
* Ignore list to suppress noisy plugins.
* Validated notification settings with a save-and-test action for every channel.

=== Notifications ===

* Email: send to one or more recipients separated by commas, semicolons, or spaces; site administrators are always included.
* Discord: post to a channel via webhook.
* Slack: connect via an incoming webhook to post alerts into any workspace channel.
* Microsoft Teams: send notices through Teams Workflows or an existing Incoming Webhook connector.
* Generic webhook: post JSON payload to any endpoint you control, with optional HMAC signatures. Failed deliveries are logged and highlighted on the Watchdog admin screen so you can reconfigure or resend manually.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install via the admin dashboard.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Open the top-level **Watchdog** menu in the WordPress sidebar to review the risk table and adjust notifications.
4. (Optional) Add your WPScan API key in the settings to fetch vulnerability intelligence.

== FAQ ==

= Does this plugin share my list of installed plugins? =

Risk processing and storage happen locally, but version comparison requires Watchdog to query WordPress.org for each installed plugin slug when its cache expires. If you add a WPScan API token, plugin slugs are also queried against WPScan. Watchdog does not send site content, user data, or telemetry to those lookup services. Email risk alerts to site administrators are enabled by default; you can disable them in settings. Webhook channels send detected risks only when you configure and enable them.

= How do I get a WPScan API key? =

Register for a free account at [wpscan.com](https://wpscan.com/) and copy the API token from your profile. Paste the token into the Watchdog settings page to enable vulnerability lookups.

= How do I configure Slack or Microsoft Teams notifications? =

Slack requires an Incoming Webhook URL that you can generate from your workspace's App Directory. For Microsoft Teams, create a workflow that starts when a webhook request is received, or use an existing Incoming Webhook connector. Paste the resulting HTTPS URL into Watchdog, enable the channel, then use its save-and-test button to verify delivery.

= Can I trigger scans manually? =

Yes. Use the "Run manual scan" button on the Watchdog admin page.

= How do I resend a failed notification payload? =

Open the Watchdog admin page and check the **Delivery health** section. If a notification fails, the payload is captured there with buttons to re-queue or download it.

= Where can I find the test suite? =

Tests and the `phpunit.xml.dist` configuration are available in the public repository but are excluded from the published plugin package. Clone the repo from GitHub to run the test suite locally with PHPUnit.

== Troubleshooting ==

=== Scheduled scans are not running ===

Watchdog relies on WP-Cron to trigger scheduled scans and notifications. If you have set `DISABLE_WP_CRON` to `true` or your site receives very little traffic (so WP-Cron rarely runs), configure a system cron job to call either `wp-cron.php` or the plugin's REST endpoint. The admin **Delivery health** panel shows the endpoint and generated secret. Send the secret in an HTTP header so it does not appear in access logs; a typical example looks like this:

`curl -X POST -H "X-Watchdog-Cron-Key: YOUR_GENERATED_SECRET" https://example.com/wp-json/site-add-on-watchdog/v1/cron`

Testing-mode notifications also rely on this trigger, so be sure your cron job is running when validating delivery.

=== Discord works but email does not arrive ===

Email uses WordPress's `wp_mail()` function and your site's mail configuration. Discord and other webhooks use HTTP, so a working webhook does not confirm that the server can send email.

Use "Save and test email", then check Delivery health for a mail or SMTP error. Configure your host's mail service or an SMTP/mail plugin if needed. If WordPress accepts the test but no email arrives, check spam and your mail provider's delivery logs, sender verification, and SPF/DKIM settings. Acceptance for sending does not confirm inbox delivery.

== CLI Usage ==

Watchdog bundles a WP-CLI command so you can run scans outside of the WordPress admin. All examples below assume the command is executed from a shell where `wp` (WP-CLI) is available.

`wp watchdog scan [--notify=<bool>]`

* `--notify` (optional): Accepts `true` or `false` (defaults to `true`). When set to `false`, Watchdog will skip any configured email or webhook notifications and only record the scan locally.

Examples:

* Run a scan and send notifications (default): `wp watchdog scan`
* Run a scan silently (skip notifications): `wp watchdog scan --notify=false`

For automated checks, boot a WordPress/WP-CLI environment and run `wp watchdog scan --notify=false` to verify scanning without sending notifications. Run `wp watchdog scan` when you want the configured channels to receive new risk alerts.

== Development ==

The development repository is available on GitHub: https://github.com/happyloa/site-add-on-watchdog. Clone it locally to review the source or run the test suite.

== Changelog ==

= 1.8.4 =
* Show mail and SMTP failure details in Delivery health while redacting sensitive URLs and common credential fields.
* Keep webhook delivery and queue retries running when a mail plugin throws an exception.
* Record each failed email test once and remove its temporary error listener after every attempt.
* Clarify email test results and mail setup requirements.
* Verify compatibility with WordPress 7.1.3.

= 1.8.3 =
* Confirm compatibility with WordPress 7.1.2; WordPress.org displays the latest 7.1 patch release from the `Tested up to: 7.1` declaration.
* Preserve a plugin's previous risk when its WordPress.org lookup fails temporarily, while continuing to scan other plugins.
* Retry failed WordPress.org lookups on the next scan instead of caching a temporary error for six hours.
* Align the readme, release packaging, and CI smoke check with the current behavior and version.

= 1.8.2 =
* Update WordPress compatibility to 7.1.
* Match exact released versions in changelog headings, including formatted headings and heading levels 2 through 6.
* Avoid false security alerts from similarly numbered versions, prereleases, and unrelated changelog entries.
* Add regression coverage for changelog parsing and a packaged WordPress scan smoke test.

= 1.8.1 =
* Filter WPScan disclosures against the installed plugin version so resolved vulnerabilities are not reported as active.
* Pause additional WPScan requests after rate-limit or temporary server responses.
* Require a genuine POST for the external Cron endpoint, reject method overrides, and support secret delivery through an HTTP header while retaining legacy query-key compatibility.
* Correct external Cron and privacy documentation, and harden the local Cron fallback.
* Add regression coverage for version-aware vulnerability filtering, API cooldowns, and Cron authentication.

= 1.8.0 =
* Refresh the admin dashboard with overview cards, section navigation, responsive settings, and clearer delivery controls.
* Isolate plugin bootstrapping, message formatting, risk sorting, and scan orchestration into focused services.
* Validate and test Email, Discord, Slack, Teams, and generic webhook settings before delivery.
* Update notification payloads and limits for current Slack, Discord, and Microsoft Teams webhook behavior.
* Use safe WordPress HTTP requests, redact secrets from errors, and contain bootstrap or provider failures.
* Cache WordPress.org lookups, preserve prior reports on scan failure, and defer the first remote scan after activation.
* Register recurring schedules after WordPress initializes translations to prevent WordPress 6.7+ debug notices.
* Preserve external Cron secrets across requests and upgrades so configured scheduler URLs remain valid.
* Declare compatibility with WordPress 7.0 and require PHP 8.1 or newer.

For earlier releases, see the full [GitHub changelog](https://github.com/happyloa/site-add-on-watchdog/blob/main/CHANGELOG.md).

== Upgrade Notice ==

= 1.8.4 =
Shows email failure details, keeps other channels running after mail errors, and clarifies how to check email delivery.

= 1.8.3 =
Improves resilience to temporary WordPress.org API failures and confirms compatibility with WordPress 7.1.2.

= 1.8.2 =
Updates WordPress compatibility to 7.1 and prevents false security alerts caused by matching unrelated changelog versions.

= 1.8.1 =
External Cron calls must now use POST. Update existing GET jobs before upgrading; legacy `?key=` authentication remains temporarily available only for POST requests, while new jobs should use the `X-Watchdog-Cron-Key` header.
