<?php

/**
 * Plugin Name: Site Add-on Watchdog
 * Description: Monitors installed plugins for potential security risks and outdated versions.
 * Version:     1.5.0
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

$autoload = __DIR__ . '/vendor/autoload.php';
if (is_readable($autoload)) {
    require_once $autoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = "Watchdog\\";
        if (! str_starts_with($class, $prefix)) {
            return;
        }

        $relativeClass = substr($class, strlen($prefix));
        $path          = __DIR__ . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';
        if (is_readable($path)) {
            require_once $path;
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

$settingsRepository = new SettingsRepository();
$riskRepository     = new RiskRepository();
$currentSettings    = $settingsRepository->get();
$wpscanClient       = new WPScanClient($currentSettings['notifications']['wpscan_api_key']);
$scanner            = new Scanner($riskRepository, new VersionComparator(), $wpscanClient);
$notificationQueue  = new NotificationQueue();
$notifier           = new Notifier($settingsRepository, $notificationQueue);
$plugin             = new Plugin($scanner, $riskRepository, $settingsRepository, $notifier);
$adminPage          = new AdminPage($riskRepository, $settingsRepository, $plugin, $notifier);

$plugin->register();
$adminPage->register();

if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('watchdog scan', new ScanCommand($plugin));
    \WP_CLI::add_command('watchdog notifications flush', new NotificationQueueCommand($plugin));
}

register_activation_hook(__FILE__, static function () use ($plugin): void {
    $plugin->schedule();
    $plugin->runScan();
});

register_deactivation_hook(__FILE__, static function () use ($plugin): void {
    $plugin->deactivate();
});
