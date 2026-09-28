# Site Add-on Watchdog architecture

Version 1.8.3 keeps the WordPress entry file deliberately small and puts each responsibility behind a focused class.

```text
site-add-on-watchdog.php
  -> Bootstrap
       -> Plugin (lifecycle, scheduling, REST and scan orchestration)
       -> AdminPage (admin actions and view model)
            -> Admin/RiskSorter
       -> Scanner
            -> VersionComparator
            -> WPScanClient
            -> RiskRepository
       -> Notifier
            -> Notifications/MessageFormatter
            -> NotificationQueue
            -> SettingsRepository
                 -> NotificationValidator
```

## Design boundaries

- `Bootstrap` is the composition root. Constructors receive dependencies and should not perform network requests.
- `Plugin` owns WordPress hooks and coordinates use cases; scanning and message formatting stay in their own services.
- `RiskRepository` and `SettingsRepository` are the only classes that own the plugin's persisted option shapes and legacy migration.
- `Scanner` contains provider isolation and cached successful WordPress.org lookups. A temporary lookup failure keeps that plugin's previous risk and does not abort the remaining scan.
- `Notifier` creates delivery jobs, while `MessageFormatter` owns platform payload formats and `NotificationQueue` owns retry state.
- `AdminPage` handles permissions, nonces, redirects, and rendering. Sorting remains independently testable through `RiskSorter`.

## Extension points

- Listen to `site_add_on_watchdog_diagnostic` to route structured diagnostic events to a logging or observability system. The event name is the second callback argument and the sanitized context array is the third.
- The cron REST endpoint accepts its generated secret and delegates to the same scan and queue services used by WP-Cron and WP-CLI.
- New notification channels should add settings validation, a formatter, a queue job definition, UI fields, and transport tests as one change. Never log webhook URLs or credentials.

## Compatibility rules

- Minimum PHP is 8.1 and minimum WordPress is 6.0.
- All WordPress hooks that can translate strings run on `init` or later.
- Remote requests use WordPress HTTP APIs; outgoing webhook requests use `wp_safe_remote_post()`.
- Activation only schedules background work. It must not depend on remote services.
- The plugin entry point catches bootstrap failures so incomplete files cannot take down the rest of the site.

## Quality gates

- `composer test` runs the PHPUnit suite.
- `composer lint` runs the repository's PSR-12 PHPCS ruleset; the official Plugin Check action supplies WordPress-specific review checks.
- GitHub Actions tests PHP 8.1 through 8.5 and runs the official WordPress Plugin Check action.
- `scripts/build-release.ps1` creates the installable ZIP from an explicit allowlist, keeping tests, build tooling, repository artwork, and WordPress.org directory assets outside the plugin package.
