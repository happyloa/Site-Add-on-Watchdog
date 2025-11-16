<?php

namespace Watchdog;

use Watchdog\Models\Risk;
use Watchdog\Repository\RiskRepository;
use Watchdog\Repository\SettingsRepository;

class AdminPage
{
    private const HISTORY_DOWNLOAD_ACTION = 'wp_watchdog_history_download';

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
        add_action('admin_post_wp_watchdog_download_history', [$this, 'handleHistoryDownload']);
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

        $historyRetention = (int) ($settings['history']['retention'] ?? RiskRepository::DEFAULT_HISTORY_RETENTION);
        if ($historyRetention < 1) {
            $historyRetention = RiskRepository::DEFAULT_HISTORY_RETENTION;
        }

        $historyDisplay = (int) apply_filters('wp_watchdog_admin_history_display', min($historyRetention, 10));
        if ($historyDisplay < 1) {
            $historyDisplay = min($historyRetention, RiskRepository::DEFAULT_HISTORY_RETENTION);
        }

        $historyRecords   = $this->riskRepository->history($historyDisplay);
        $historyDownloads = [];
        foreach ($historyRecords as $record) {
            $historyDownloads[$record['run_at']] = [
                'json' => $this->buildHistoryDownloadUrl($record['run_at'], 'json'),
                'csv'  => $this->buildHistoryDownloadUrl($record['run_at'], 'csv'),
            ];
        }

        require __DIR__ . '/../templates/admin-page.php';
    }

    public function enqueueAssets(string $hook): void
    {
        $matchesHook = $this->menuHook !== null
            ? ($hook === $this->menuHook)
            : ($hook === 'toplevel_page_wp-plugin-watchdog');

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

    public function handleHistoryDownload(): void
    {
        $this->guardAccess(self::HISTORY_DOWNLOAD_ACTION);

        $runAt = isset($_GET['run_at']) ? (int) $_GET['run_at'] : 0;
        if ($runAt <= 0) {
            wp_die(__('Invalid history request.', 'wp-plugin-watchdog'));
        }

        $formatParam = $_GET['format'] ?? 'json';
        if (is_array($formatParam)) {
            $formatParam = reset($formatParam) ?: 'json';
        }

        $format = sanitize_key(wp_unslash((string) $formatParam));
        if ($format === '') {
            $format = 'json';
        }

        $entry = $this->riskRepository->historyEntry($runAt);
        if ($entry === null) {
            wp_die(__('History entry not found.', 'wp-plugin-watchdog'));
        }

        if ($format === 'csv') {
            $this->streamHistoryCsv($entry);
        }

        $this->streamHistoryJson($entry);
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

    private function buildHistoryDownloadUrl(int $runAt, string $format): string
    {
        $url = add_query_arg(
            [
                'action' => 'wp_watchdog_download_history',
                'run_at' => $runAt,
                'format' => $format,
            ],
            admin_url('admin-post.php')
        );

        return wp_nonce_url($url, self::HISTORY_DOWNLOAD_ACTION);
    }

    /**
     * @param array{run_at:int, risk_count:int, risks:array<int, array<string, mixed>>} $entry
     */
    private function streamHistoryJson(array $entry): void
    {
        $filename = sprintf('wp-watchdog-history-%s.json', gmdate('Ymd-His', $entry['run_at']));

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        echo wp_json_encode([
            'run_at'     => $entry['run_at'],
            'risk_count' => $entry['risk_count'],
            'risks'      => $entry['risks'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * @param array{run_at:int, risk_count:int, risks:array<int, array<string, mixed>>} $entry
     */
    private function streamHistoryCsv(array $entry): void
    {
        $filename = sprintf('wp-watchdog-history-%s.csv', gmdate('Ymd-His', $entry['run_at']));

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $handle = fopen('php://output', 'w');
        if ($handle === false) {
            wp_die(__('Unable to generate history export.', 'wp-plugin-watchdog'));
        }

        fputcsv($handle, ['run_at', 'plugin_slug', 'plugin_name', 'local_version', 'remote_version', 'reasons']);

        foreach ($entry['risks'] as $risk) {
            $reasons = '';
            if (isset($risk['reasons']) && is_array($risk['reasons'])) {
                $reasons = implode('; ', array_map(static fn ($reason): string => (string) $reason, $risk['reasons']));
            }

            $remoteVersion = '';
            if (isset($risk['remote_version']) && $risk['remote_version'] !== null) {
                $remoteVersion = (string) $risk['remote_version'];
            }

            fputcsv($handle, [
                $entry['run_at'],
                isset($risk['plugin_slug']) ? (string) $risk['plugin_slug'] : '',
                isset($risk['plugin_name']) ? (string) $risk['plugin_name'] : '',
                isset($risk['local_version']) ? (string) $risk['local_version'] : '',
                $remoteVersion,
                $reasons,
            ]);
        }

        fclose($handle);
        exit;
    }
}
