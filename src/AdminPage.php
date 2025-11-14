<?php

namespace Watchdog;

use Watchdog\Models\Risk;
use Watchdog\Repository\RiskRepository;
use Watchdog\Repository\SettingsRepository;

class AdminPage
{
    private ?string $menuHook = null;
    private bool $assetsEnqueued = false;

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
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function addMenu(): void
    {
        $this->menuHook = add_menu_page(
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

        $this->enqueuePageAssets();

        $risks     = $this->riskRepository->all();
        $ignored   = $this->riskRepository->ignored();
        $settings  = $this->settingsRepository->get();
        $scanNonce = wp_create_nonce('wp_watchdog_scan');

        require __DIR__ . '/../templates/admin-page.php';
    }

    public function enqueueAssets(string $hook): void
    {
        $matchesHook = $this->menuHook !== null ? ($hook === $this->menuHook) : ($hook === 'toplevel_page_wp-plugin-watchdog');

        if (! $matchesHook) {
            return;
        }

        $this->enqueuePageAssets();
    }

    public function handleSettings(): void
    {
        $this->guardAccess('wp_watchdog_settings');

        $payload = wp_unslash($_POST['settings'] ?? []);
        if (! is_array($payload)) {
            $payload = [];
        }

        if (! isset($payload['notifications']) || ! is_array($payload['notifications'])) {
            $payload['notifications'] = [];
        }

        $this->settingsRepository->save($payload);
        $this->plugin->schedule();

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

    private function enqueuePageAssets(): void
    {
        if ($this->assetsEnqueued) {
            return;
        }

        $pluginFile    = dirname(__DIR__) . '/wp-plugin-watchdog.php';
        $stylePath     = dirname(__DIR__) . '/assets/css/admin.css';
        $scriptPath    = dirname(__DIR__) . '/assets/js/admin-table.js';
        $styleUrl      = plugins_url('assets/css/admin.css', $pluginFile);
        $scriptUrl     = plugins_url('assets/js/admin-table.js', $pluginFile);
        $styleVersion  = file_exists($stylePath) ? (string) filemtime($stylePath) : false;
        $scriptVersion = file_exists($scriptPath) ? (string) filemtime($scriptPath) : false;

        wp_enqueue_style('wp-plugin-watchdog-admin', $styleUrl, [], $styleVersion);
        wp_enqueue_script('wp-plugin-watchdog-admin-table', $scriptUrl, [], $scriptVersion, true);
        wp_localize_script('wp-plugin-watchdog-admin-table', 'wpWatchdogTable', [
            'pageStatus' => __('Page %1$d of %2$d', 'wp-plugin-watchdog'),
        ]);

        $this->assetsEnqueued = true;
    }
}
