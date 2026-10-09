<?php

/**
 * Plugin Name: Site Add-on Watchdog
 * Description: Monitors installed plugins for potential security risks and outdated versions.
 * Version:     1.8.4
 * Author:      Aaron
 * Author URI:  https://www.worksbyaaron.com/
 * License:     GPLv2 or later
 * Text Domain: site-add-on-watchdog
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Tested up to: 7.1
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

try {
    Watchdog\Bootstrap::create(__FILE__)->register();
} catch (\Throwable $watchdogError) {
    if (function_exists('do_action')) {
        do_action('site_add_on_watchdog_diagnostic', 'bootstrap_failed', [
            'exception' => get_class($watchdogError),
        ]);
    }

    add_action('admin_notices', static function (): void {

        if (! current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-error"><p>'
            // phpcs:ignore Generic.Files.LineLength.TooLong -- Translation source must remain one literal string.
            . esc_html__('Site Add-on Watchdog could not start. The rest of the site is still available; check the PHP error log and reinstall the plugin files.', 'site-add-on-watchdog')
            . '</p></div>';
    });
}
