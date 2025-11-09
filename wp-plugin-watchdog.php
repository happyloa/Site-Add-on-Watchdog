<?php

/**
 * Plugin Name: WP Plugin Watchdog
 * Plugin URI:  https://example.com/wp-plugin-watchdog
 * Description: Monitors installed plugins for potential security risks and outdated versions.
 * Version:     0.1.0
 * Author:      Plugin Watchdog Team
 * Author URI:  https://example.com
 * License:     GPLv2 or later
 * Text Domain: wp-plugin-watchdog
 * Requires PHP: 8.1
 * Tested up to: 6.8
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/vendor/autoload.php';

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
$wpscanClient       = new WPScanClient($settingsRepository->get()['wpscan_api_key']);
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
