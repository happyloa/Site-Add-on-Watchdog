<?php

namespace Watchdog;

use Watchdog\Models\Risk;
use Watchdog\Repository\RiskRepository;
use Watchdog\Repository\SettingsRepository;
use Watchdog\Version;
use WP_Filesystem_Direct;

class AdminPage
{
    private const HISTORY_DOWNLOAD_ACTION = 'wp_watchdog_history_download';

    private ?string $menuHook = null;
    private bool $assetsEnqueued = false;
    /**
     * @var WP_Filesystem_Direct|null
     */
    private $filesystem = null;

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
            __('Plugin Watchdog', 'wp-plugin-watchdog-main'),
            __('Watchdog', 'wp-plugin-watchdog-main'),
            'manage_options',
            'wp-plugin-watchdog',
            [$this, 'render'],
            'dashicons-shield'
        );
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'wp-plugin-watchdog-main'));
        }

        $this->enqueuePageAssets();

        $risks     = $this->riskRepository->all();
        $ignored   = $this->riskRepository->ignored();
        $settings  = $this->settingsRepository->get();
        $scanNonce = wp_create_nonce('wp_watchdog_scan');

        $settingsError = get_transient('wp_watchdog_settings_error');
        if ($settingsError !== false) {
            delete_transient('wp_watchdog_settings_error');
        }

        $historyRetention = (int) ($settings['history']['retention'] ?? RiskRepository::DEFAULT_HISTORY_RETENTION);
        if ($historyRetention < 1) {
            $historyRetention = RiskRepository::DEFAULT_HISTORY_RETENTION;
        }

        $historyDisplay = (int) apply_filters('wp_watchdog_main_admin_history_display', min($historyRetention, 10));
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
        $this->guardAccess();
        check_admin_referer('wp_watchdog_settings');

        $payload = wp_unslash($_POST['settings'] ?? []);
        if (! is_array($payload)) {
            $payload = [];
        }

        $payload = $this->sanitizeSettingsInput($payload);

        $rawRetention = $payload['history']['retention'] ?? null;
        if (is_numeric($rawRetention) && (int) $rawRetention > 15) {
            $payload['history']['retention'] = '15';
            set_transient(
                'wp_watchdog_settings_error',
                __('History retention cannot exceed 15 scans. The value has been limited to 15.', 'wp-plugin-watchdog-main'),
                30
            );
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
        $this->guardAccess();
        check_admin_referer('wp_watchdog_ignore');

        $slug = sanitize_text_field(wp_unslash($_POST['plugin_slug'] ?? ''));
        if ($slug !== '') {
            $this->riskRepository->addIgnore($slug);
        }

        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=wp-plugin-watchdog'));
        exit;
    }

    public function handleUnignore(): void
    {
        $this->guardAccess();
        check_admin_referer('wp_watchdog_unignore');

        $slug = sanitize_text_field(wp_unslash($_POST['plugin_slug'] ?? ''));
        if ($slug !== '') {
            $this->riskRepository->removeIgnore($slug);
        }

        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=wp-plugin-watchdog'));
        exit;
    }

    public function handleManualScan(): void
    {
        $this->guardAccess();
        check_admin_referer('wp_watchdog_scan');

        $this->plugin->runScan(true, 'manual');

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
        $this->guardAccess();
        check_admin_referer(self::HISTORY_DOWNLOAD_ACTION);

        $runAt = isset($_GET['run_at']) ? (int) $_GET['run_at'] : 0;
        if ($runAt <= 0) {
            wp_die(__('Invalid history request.', 'wp-plugin-watchdog-main'));
        }

        $formatParam = $_GET['format'] ?? 'json';
        $formatParam = wp_unslash($formatParam);
        if (is_array($formatParam)) {
            $formatParam = reset($formatParam) ?: 'json';
        }

        $format = sanitize_key((string) $formatParam);
        if ($format === '' || ! in_array($format, ['json', 'csv'], true)) {
            $format = 'json';
        }

        $entry = $this->riskRepository->historyEntry($runAt);
        if ($entry === null) {
            wp_die(__('History entry not found.', 'wp-plugin-watchdog-main'));
        }

        if ($format === 'csv') {
            $this->streamHistoryCsv($entry);

            return;
        }

        $this->streamHistoryJson($entry);
    }

    private function guardAccess(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'wp-plugin-watchdog-main'));
        }
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function sanitizeSettingsInput(array $settings): array
    {
        return array_map(function ($value) {
            if (is_array($value)) {
                return $this->sanitizeSettingsInput($value);
            }

            if (is_scalar($value) || $value === null) {
                return sanitize_text_field((string) $value);
            }

            return '';
        }, $settings);
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
        $assetVersion  = Version::NUMBER;

        wp_enqueue_style('wp-plugin-watchdog-admin', $styleUrl, [], $assetVersion);
        wp_enqueue_script('wp-plugin-watchdog-admin-table', $scriptUrl, [], $assetVersion, true);
        wp_localize_script('wp-plugin-watchdog-admin-table', 'wpWatchdogTable', [
            /* translators: 1: current page number, 2: total number of pages. */
            'pageStatus' => __('Page %1$d of %2$d', 'wp-plugin-watchdog-main'),
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

        $rows = [
            ['run_at', 'plugin_slug', 'plugin_name', 'local_version', 'remote_version', 'reasons'],
        ];

        foreach ($entry['risks'] as $risk) {
            $reasons = '';
            if (isset($risk['reasons']) && is_array($risk['reasons'])) {
                $reasons = implode('; ', array_map(static fn ($reason): string => (string) $reason, $risk['reasons']));
            }

            $remoteVersion = '';
            if (isset($risk['remote_version']) && $risk['remote_version'] !== null) {
                $remoteVersion = (string) $risk['remote_version'];
            }

            $rows[] = [
                (string) $entry['run_at'],
                isset($risk['plugin_slug']) ? (string) $risk['plugin_slug'] : '',
                isset($risk['plugin_name']) ? (string) $risk['plugin_name'] : '',
                isset($risk['local_version']) ? (string) $risk['local_version'] : '',
                $remoteVersion,
                $reasons,
            ];
        }

        $target = 'php://output';
        $delimiter = ',';
        $enclosure = '"';
        $escape    = '\\';

        if ($target === 'php://output') {
            $handle = fopen($target, 'wb');

            if ($handle === false) {
                wp_die(__('Unable to generate history export.', 'wp-plugin-watchdog-main'));
            }

            foreach ($rows as $row) {
                $row = array_map(static fn ($value): string => (string) $value, $row);

                if (fputcsv($handle, $row, $delimiter, $enclosure, $escape) === false) {
                    fclose($handle);
                    wp_die(__('Unable to generate history export.', 'wp-plugin-watchdog-main'));
                }
            }

            fclose($handle);
        } else {
            $filesystem = $this->getFilesystem();
            $csvContent  = $this->buildCsvContent($rows);

            if (! $filesystem->put_contents($target, $csvContent)) {
                wp_die(__('Unable to generate history export.', 'wp-plugin-watchdog-main'));
            }
        }
        exit;
    }

    /**
     * @param array<int, array<int, string>> $rows
     */
    private function buildCsvContent(array $rows): string
    {
        $lines = array_map([$this, 'formatCsvRow'], $rows);

        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * @param array<int, string> $row
     */
    private function formatCsvRow(array $row): string
    {
        $row     = array_map(static fn ($value): string => (string) $value, $row);
        $escaped = array_map(static function (string $field): string {
            $needsQuotes = strpbrk($field, ",\"\r\n") !== false;
            $field       = str_replace('"', '""', $field);

            if ($needsQuotes) {
                $field = '"' . $field . '"';
            }

            return $field;
        }, $row);

        return implode(',', $escaped);
    }

    private function getFilesystem()
    {
        if ($this->filesystem !== null) {
            return $this->filesystem;
        }

        $this->ensureFilesystemDependenciesLoaded();

        global $wp_filesystem;

        if (! $wp_filesystem instanceof WP_Filesystem_Direct) {
            WP_Filesystem();
        }

        if ($wp_filesystem instanceof WP_Filesystem_Direct) {
            $this->filesystem = $wp_filesystem;

            return $this->filesystem;
        }

        $this->filesystem = new WP_Filesystem_Direct(false);

        return $this->filesystem;
    }

    private function ensureFilesystemDependenciesLoaded(): void
    {
        if (! function_exists('request_filesystem_credentials')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if (! class_exists('WP_Filesystem_Base')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        }

        if (! class_exists('WP_Filesystem_Direct')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        }
    }
}
