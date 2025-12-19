<?php

/**
 * Plugin Name: Site Add-on Watchdog
 * Description: Monitors installed plugins for potential security risks and outdated versions.
 * Version:     1.5.1
 * Author:      Aaron
 * Author URI:  https://www.worksbyaaron.com/
 * License:     GPLv2 or later
 * Text Domain: site-add-on-watchdog
 * Requires PHP: 8.1
 * Tested up to: 6.8
 */

defined('ABSPATH') || exit;

if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action('admin_notices', static function () {
        echo '<div class="notice notice-error"><p>'
            . esc_html__(
                'Site Add-on Watchdog requires PHP 8.1 or higher. The plugin has been disabled.',
                'site-add-on-watchdog'
            )
            . '</p></div>';
    });

    if (is_admin() && current_user_can('activate_plugins')) {
        if (! function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        deactivate_plugins(plugin_basename(__FILE__));
    }

    return;
}

$watchdog_autoload = __DIR__ . '/vendor/autoload.php';
if (is_readable($watchdog_autoload)) {
    require_once $watchdog_autoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $watchdog_prefix = "Watchdog\\";
        if (! str_starts_with($class, $watchdog_prefix)) {
            return;
        }

        $watchdog_relativeClass = substr($class, strlen($watchdog_prefix));
        $watchdog_path          = __DIR__ . '/src/' . str_replace('\\', '/', $watchdog_relativeClass) . '.php';
        if (is_readable($watchdog_path)) {
            require_once $watchdog_path;
        }
    });
}

use Watchdog\AdminPage;
use Watchdog\Cli\NotificationQueueCommand;
use Watchdog\Cli\ScanCommand;
use Watchdog\Notifier;
use Watchdog\Plugin;
use Watchdog\Repository\RiskRepository;
use Watchdog\Repository\SettingsRepository;
use Watchdog\Scanner;
use Watchdog\Services\NotificationQueue;
use Watchdog\Services\VersionComparator;
use Watchdog\Services\WPScanClient;

$watchdog_settingsRepository = new SettingsRepository();
$watchdog_riskRepository     = new RiskRepository();
$watchdog_currentSettings    = $watchdog_settingsRepository->get();
$watchdog_wpscanClient       = new WPScanClient(
    $watchdog_currentSettings['notifications']['wpscan_api_key']
);
$watchdog_scanner            = new Scanner(
    $watchdog_riskRepository,
    new VersionComparator(),
    $watchdog_wpscanClient
);
$watchdog_notificationQueue  = new NotificationQueue();
$watchdog_notifier           = new Notifier($watchdog_settingsRepository, $watchdog_notificationQueue);
$watchdog_plugin             = new Plugin(
    $watchdog_scanner,
    $watchdog_riskRepository,
    $watchdog_settingsRepository,
    $watchdog_notifier
);
$watchdog_adminPage          = new AdminPage(
    $watchdog_riskRepository,
    $watchdog_settingsRepository,
    $watchdog_plugin,
    $watchdog_notifier
);

$watchdog_plugin->register();
$watchdog_adminPage->register();

if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('watchdog scan', new ScanCommand($watchdog_plugin));
    \WP_CLI::add_command('watchdog notifications flush', new NotificationQueueCommand($watchdog_plugin));
}

register_activation_hook(__FILE__, static function () use ($watchdog_plugin): void {
    $watchdog_plugin->schedule();
    $watchdog_plugin->runScan();
});

register_deactivation_hook(__FILE__, static function () use ($watchdog_plugin): void {
    $watchdog_plugin->deactivate();
});
