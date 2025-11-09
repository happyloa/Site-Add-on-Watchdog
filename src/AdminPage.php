<?php

namespace Watchdog;

use Watchdog\Models\Risk;
use Watchdog\Repository\RiskRepository;
use Watchdog\Repository\SettingsRepository;

class AdminPage
{
    public function __construct(
        private readonly RiskRepository $riskRepository,
        private readonly SettingsRepository $settingsRepository,
        private readonly Plugin $plugin
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_post_wp_watchdog_save_settings', [$this, 'handleSettings']);
        add_action('admin_post_wp_watchdog_ignore', [$this, 'handleIgnore']);
        add_action('admin_post_wp_watchdog_unignore', [$this, 'handleUnignore']);
        add_action('admin_post_wp_watchdog_scan', [$this, 'handleManualScan']);
    }

    public function addMenu(): void
    {
        add_menu_page(
            __('Plugin Watchdog', 'wp-plugin-watchdog'),
            __('Watchdog', 'wp-plugin-watchdog'),
            'manage_options',
            'wp-plugin-watchdog',
            [$this, 'render'],
            'dashicons-shield'
        );
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'wp-plugin-watchdog'));
        }

        $risks     = $this->riskRepository->all();
        $ignored   = $this->riskRepository->ignored();
        $settings  = $this->settingsRepository->get();
        $scanNonce = wp_create_nonce('wp_watchdog_scan');

        require __DIR__ . '/../templates/admin-page.php';
    }

    public function handleSettings(): void
    {
        $this->guardAccess('wp_watchdog_settings');

        $payload = wp_unslash($_POST['settings'] ?? []);
        $this->settingsRepository->save($payload);

        wp_safe_redirect(
            add_query_arg(
                'updated',
                'true',
                wp_get_referer() ?: admin_url('admin.php?page=wp-plugin-watchdog')
            )
        );
        exit;
    }

    public function handleIgnore(): void
    {
        $this->guardAccess('wp_watchdog_ignore');

        $slug = sanitize_text_field(wp_unslash($_POST['plugin_slug'] ?? ''));
        if ($slug !== '') {
            $this->riskRepository->addIgnore($slug);
        }

        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=wp-plugin-watchdog'));
        exit;
    }

    public function handleUnignore(): void
    {
        $this->guardAccess('wp_watchdog_unignore');

        $slug = sanitize_text_field(wp_unslash($_POST['plugin_slug'] ?? ''));
        if ($slug !== '') {
            $this->riskRepository->removeIgnore($slug);
        }

        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=wp-plugin-watchdog'));
        exit;
    }

    public function handleManualScan(): void
    {
        $this->guardAccess('wp_watchdog_scan');

        $this->plugin->runScan();

        wp_safe_redirect(
            add_query_arg(
                'scan',
                'done',
                wp_get_referer() ?: admin_url('admin.php?page=wp-plugin-watchdog')
            )
        );
        exit;
    }

    private function guardAccess(string $action): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'wp-plugin-watchdog'));
        }

        check_admin_referer($action);
    }
}
