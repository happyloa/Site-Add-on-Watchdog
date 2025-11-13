<?php

/**
 * Plugin Name: WP Plugin Watchdog
 * Description: Monitors installed plugins for potential security risks and outdated versions.
 * Version:     0.2.0
 * Author:      Aaron
 * Author URI:  https://www.worksbyaaron.com/
 * License:     GPLv2 or later
 * Text Domain: wp-plugin-watchdog
 * Requires PHP: 8.1
 * Tested up to: 6.8
 */

defined('ABSPATH') || exit;

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
use Watchdog\Notifier;
use Watchdog\Plugin;
use Watchdog\Repository\RiskRepository;
use Watchdog\Repository\SettingsRepository;
use Watchdog\Scanner;
use Watchdog\Services\VersionComparator;
use Watchdog\Services\WPScanClient;

$settingsRepository = new SettingsRepository();
$riskRepository     = new RiskRepository();
$currentSettings    = $settingsRepository->get();
$wpscanClient       = new WPScanClient($currentSettings['notifications']['wpscan_api_key']);
$scanner            = new Scanner($riskRepository, new VersionComparator(), $wpscanClient);
$notifier           = new Notifier($settingsRepository);
$plugin             = new Plugin($scanner, $riskRepository, $settingsRepository, $notifier);
$adminPage          = new AdminPage($riskRepository, $settingsRepository, $plugin);

$plugin->register();
$adminPage->register();

register_activation_hook(__FILE__, static function () use ($plugin): void {
    $plugin->schedule();
    $plugin->runScan();
});

register_deactivation_hook(__FILE__, static function () use ($plugin): void {
    $plugin->deactivate();
});
