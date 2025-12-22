<?php

namespace Watchdog;

use Watchdog\Models\Risk;
use Watchdog\Notifier;
use Watchdog\Repository\RiskRepository;
use Watchdog\Repository\SettingsRepository;
use Watchdog\Version;
use WP_Filesystem_Direct;

class AdminPage
{
    private const PREFIX = Version::PREFIX;
    private const MENU_SLUG = 'site-add-on-watchdog';
    private const HISTORY_DOWNLOAD_ACTION = self::PREFIX . '_history_download';

    private ?string $menuHook = null;
    private bool $assetsEnqueued = false;
    /**
     * @var WP_Filesystem_Direct|null
     */
    private $filesystem = null;

    public function __construct(
        private readonly RiskRepository $riskRepository,
        private readonly SettingsRepository $settingsRepository,
        private readonly Plugin $plugin,
        private readonly Notifier $notifier
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_post_' . self::PREFIX . '_save_settings', [$this, 'handleSettings']);
        add_action('admin_post_' . self::PREFIX . '_ignore', [$this, 'handleIgnore']);
        add_action('admin_post_' . self::PREFIX . '_unignore', [$this, 'handleUnignore']);
        add_action('admin_post_' . self::PREFIX . '_scan', [$this, 'handleManualScan']);
        add_action('admin_post_' . self::PREFIX . '_send_notifications', [$this, 'handleSendNotifications']);
        add_action('admin_post_' . self::PREFIX . '_download_history', [$this, 'handleHistoryDownload']);
        add_action(
            'admin_post_' . self::PREFIX . '_resend_failed_notification',
            [$this, 'handleResendFailedNotification']
        );
        add_action(
            'admin_post_' . self::PREFIX . '_download_failed_notification',
            [$this, 'handleFailedNotificationDownload']
        );
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function addMenu(): void
    {
        $this->menuHook = add_menu_page(
            __('Site Add-on Watchdog', 'site-add-on-watchdog'),
            __('Watchdog', 'site-add-on-watchdog'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render'],
            'dashicons-shield'
        );
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'site-add-on-watchdog'));
        }

        $this->enqueuePageAssets();

        $watchdogRisks     = $this->riskRepository->all();
        $watchdogIgnored   = $this->riskRepository->ignored();
        $watchdogSettings  = $this->settingsRepository->get();
        $watchdogCronStatus = $this->plugin->getCronStatus();
        $watchdogCronEndpoint = $this->plugin->getCronEndpointUrl();
        $watchdogCronSecretPersisted = $this->settingsRepository->hasPersistedCronSecret();

        $watchdogRiskSortParam = filter_input(INPUT_GET, 'risk_sort', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $watchdogRiskOrderParam = filter_input(INPUT_GET, 'risk_order', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $watchdogRiskSearchParam = filter_input(INPUT_GET, 'risk_search', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $watchdogRiskSort = is_string($watchdogRiskSortParam) ? sanitize_key($watchdogRiskSortParam) : '';
        $watchdogRiskOrder = is_string($watchdogRiskOrderParam) ? sanitize_key($watchdogRiskOrderParam) : '';
        $watchdogRiskSearch = is_string($watchdogRiskSearchParam) ? sanitize_text_field($watchdogRiskSearchParam) : '';
        $watchdogAllowedSorts = ['plugin', 'local', 'remote', 'reasons', 'risk_count', 'version_gap'];
        if (! in_array($watchdogRiskSort, $watchdogAllowedSorts, true)) {
            $watchdogRiskSort = '';
        }
        if (! in_array($watchdogRiskOrder, ['asc', 'desc'], true)) {
            $watchdogRiskOrder = '';
        }
        if ($watchdogRiskSort !== '') {
            $watchdogRisks = $this->sortRisks(
                $watchdogRisks,
                $watchdogRiskSort,
                $watchdogRiskOrder !== '' ? $watchdogRiskOrder : 'asc'
            );
        }
        $watchdogRiskSortSelection = $watchdogRiskSort !== '' ? $watchdogRiskSort : 'plugin';
        $watchdogRiskOrderSelection = $watchdogRiskOrder !== '' ? $watchdogRiskOrder : 'asc';

        $watchdogSettingsError = get_transient(self::PREFIX . '_settings_error');
        if ($watchdogSettingsError !== false) {
            delete_transient(self::PREFIX . '_settings_error');
        }

        $watchdogWpScanError = get_transient(self::PREFIX . '_wpscan_error');

        $watchdogHistoryRetention = (int) (
            $watchdogSettings['history']['retention'] ?? RiskRepository::DEFAULT_HISTORY_RETENTION
        );
        if ($watchdogHistoryRetention < 1) {
            $watchdogHistoryRetention = RiskRepository::DEFAULT_HISTORY_RETENTION;
        }

        $watchdogHistoryDisplay = (int) apply_filters(
            'site_add_on_watchdog_admin_history_display',
            min($watchdogHistoryRetention, 10)
        );
        if ($watchdogHistoryDisplay < 1) {
            $watchdogHistoryDisplay = min($watchdogHistoryRetention, RiskRepository::DEFAULT_HISTORY_RETENTION);
        }

        $watchdogHistoryRecords   = $this->riskRepository->history($watchdogHistoryDisplay);
        $watchdogHistoryDownloads = [];
        foreach ($watchdogHistoryRecords as $watchdogRecord) {
            $watchdogHistoryDownloads[$watchdogRecord['run_at']] = [
                'json' => $this->buildHistoryDownloadUrl($watchdogRecord['run_at'], 'json'),
                'csv'  => $this->buildHistoryDownloadUrl($watchdogRecord['run_at'], 'csv'),
            ];
        }

        $watchdogLastFailedNotification = $this->notifier->getLastFailedNotification();
        $watchdogQueueStatus = $this->notifier->getQueueStatus();

        $watchdogActionPrefix = self::PREFIX;
        $watchdogNoticeNonceValid = $this->isNoticeNonceValid();
        $watchdogUpdatedNotice = filter_input(INPUT_GET, 'updated', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $watchdogScanNotice = filter_input(INPUT_GET, 'scan', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $watchdogNoticeFlags = [
            'updated' => $watchdogNoticeNonceValid && $watchdogUpdatedNotice !== null,
            'scan' => $watchdogNoticeNonceValid && $watchdogScanNotice !== null,
        ];
        $watchdogNotificationResult = '';
        $watchdogNotificationsParam = filter_input(INPUT_GET, 'notifications', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        if ($watchdogNoticeNonceValid && is_string($watchdogNotificationsParam)) {
            $watchdogNotificationResult = sanitize_key($watchdogNotificationsParam);
        }
        $watchdogFailedNotificationStatus = '';
        $watchdogFailedNotificationParam = filter_input(
            INPUT_GET,
            'failed_notification',
            FILTER_SANITIZE_FULL_SPECIAL_CHARS
        );
        if ($watchdogNoticeNonceValid && is_string($watchdogFailedNotificationParam)) {
            $watchdogFailedNotificationStatus = sanitize_key($watchdogFailedNotificationParam);
        }

        require __DIR__ . '/../templates/admin-page.php';
    }

    public function enqueueAssets(string $hook): void
    {
        $matchesHook = $this->menuHook !== null
            ? ($hook === $this->menuHook)
            : ($hook === 'toplevel_page_' . self::MENU_SLUG);

        if (! $matchesHook) {
            return;
        }

        $this->enqueuePageAssets();
    }

    public function handleSettings(): void
    {
        $this->guardAccess();
        check_admin_referer(self::PREFIX . '_settings');

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $payload = wp_unslash($_POST['settings'] ?? []);
        if (! is_array($payload)) {
            $payload = [];
        }

        $payload = $this->sanitizeSettingsInput($payload);

        $rawRetention = $payload['history']['retention'] ?? null;
        if (is_numeric($rawRetention) && (int) $rawRetention > 15) {
            $payload['history']['retention'] = '15';
            $message = __(
                'History retention cannot exceed 15 scans. The value has been limited to 15.',
                'site-add-on-watchdog'
            );
            set_transient(
                self::PREFIX . '_settings_error',
                $message,
                30
            );
        }

        if (! isset($payload['notifications']) || ! is_array($payload['notifications'])) {
            $payload['notifications'] = [];
        }

        $this->settingsRepository->save($payload);
        $this->plugin->schedule();

        $this->redirectWithNotice(['updated' => 'true']);
        exit;
    }

    public function handleIgnore(): void
    {
        $this->guardAccess();
        check_admin_referer(self::PREFIX . '_ignore');

        $slug = sanitize_text_field(wp_unslash($_POST['plugin_slug'] ?? ''));
        if ($slug !== '') {
            $this->riskRepository->addIgnore($slug);
        }

        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=' . self::MENU_SLUG));
        exit;
    }

    public function handleUnignore(): void
    {
        $this->guardAccess();
        check_admin_referer(self::PREFIX . '_unignore');

        $slug = sanitize_text_field(wp_unslash($_POST['plugin_slug'] ?? ''));
        if ($slug !== '') {
            $this->riskRepository->removeIgnore($slug);
        }

        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=' . self::MENU_SLUG));
        exit;
    }

    public function handleManualScan(): void
    {
        $this->guardAccess();
        check_admin_referer(self::PREFIX . '_scan');

        $this->plugin->runScan(true, 'manual');

        $this->redirectWithNotice(['scan' => 'done']);
        exit;
    }

    public function handleSendNotifications(): void
    {
        $this->guardAccess();
        check_admin_referer(self::PREFIX . '_send_notifications');

        $force           = ! empty($_POST['force']);
        $respectThrottle = empty($_POST['ignore_throttle']);
        $result          = $this->plugin->sendNotifications($force, $respectThrottle);

        $this->redirectWithNotice(['notifications' => $result]);
        exit;
    }

    public function handleResendFailedNotification(): void
    {
        $this->guardAccess();
        check_admin_referer(self::PREFIX . '_resend_failed_notification');

        $resent = $this->notifier->requeueLastFailedNotification();

        $status = $resent ? 'resent' : 'missing';

        $this->redirectWithNotice(['failed_notification' => $status]);
        exit;
    }

    public function handleFailedNotificationDownload(): void
    {
        $this->guardAccess();
        check_admin_referer(self::PREFIX . '_download_failed_notification');

        $failed = $this->notifier->getLastFailedNotification();
        if ($failed === null) {
            wp_die(esc_html__('No failed notification payload is available.', 'site-add-on-watchdog'));
        }

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="watchdog-failed-notification.json"');

        echo wp_json_encode($failed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function handleHistoryDownload(): void
    {
        $this->guardAccess();
        check_admin_referer(self::HISTORY_DOWNLOAD_ACTION);

        $runAtParam = filter_input(INPUT_GET, 'run_at', FILTER_SANITIZE_NUMBER_INT);
        $runAt = $runAtParam !== null ? absint($runAtParam) : 0;
        if ($runAt <= 0) {
            wp_die(esc_html__('Invalid history request.', 'site-add-on-watchdog'));
        }

        $formatParam = filter_input(INPUT_GET, 'format', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $format = is_string($formatParam) ? sanitize_key($formatParam) : 'json';
        if ($format === '' || ! in_array($format, ['json', 'csv'], true)) {
            $format = 'json';
        }

        $entry = $this->riskRepository->historyEntry($runAt);
        if ($entry === null) {
            wp_die(esc_html__('History entry not found.', 'site-add-on-watchdog'));
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
            wp_die(esc_html__('You do not have permission to perform this action.', 'site-add-on-watchdog'));
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

        $pluginFile    = dirname(__DIR__) . '/site-add-on-watchdog.php';
        $stylePath     = dirname(__DIR__) . '/assets/css/admin.css';
        $scriptPath    = dirname(__DIR__) . '/assets/js/admin-table.js';
        $styleUrl      = plugins_url('assets/css/admin.css', $pluginFile);
        $scriptUrl     = plugins_url('assets/js/admin-table.js', $pluginFile);
        $assetVersion  = Version::NUMBER;

        wp_enqueue_style(self::PREFIX . '-admin', $styleUrl, [], $assetVersion);
        wp_enqueue_script(self::PREFIX . '-admin-table', $scriptUrl, [], $assetVersion, true);
        wp_localize_script(self::PREFIX . '-admin-table', 'siteAddOnWatchdogTable', [
            /* translators: 1: current page number, 2: total number of pages. */
            'pageStatus' => __('Page %1$d of %2$d', 'site-add-on-watchdog'),
            'noResults' => __('No risks match your search.', 'site-add-on-watchdog'),
        ]);

        $this->assetsEnqueued = true;
    }

    /**
     * @param Risk[] $risks
     * @return Risk[]
     */
    private function sortRisks(array $risks, string $sortKey, string $sortOrder): array
    {
        $direction = $sortOrder === 'desc' ? -1 : 1;

        usort($risks, function (Risk $left, Risk $right) use ($sortKey, $direction): int {
            $comparison = 0;

            switch ($sortKey) {
                case 'plugin':
                    $comparison = $this->compareText($left->pluginName, $right->pluginName);
                    break;
                case 'local':
                    $comparison = $this->compareVersions($left->localVersion, $right->localVersion);
                    break;
                case 'remote':
                    $comparison = $this->compareVersions($left->remoteVersion, $right->remoteVersion);
                    break;
                case 'reasons':
                    $comparison = $this->compareText(
                        $this->buildReasonSortValue($left),
                        $this->buildReasonSortValue($right)
                    );
                    break;
                case 'risk_count':
                    $comparison = $this->countRiskSignals($left) <=> $this->countRiskSignals($right);
                    break;
                case 'version_gap':
                    $comparison = $this->versionGapScore($left) <=> $this->versionGapScore($right);
                    break;
            }

            return $comparison * $direction;
        });

        return $risks;
    }

    private function compareText(string $left, string $right): int
    {
        $leftNormalized = $this->normalizeSortValue($left);
        $rightNormalized = $this->normalizeSortValue($right);

        if ($leftNormalized === $rightNormalized) {
            return 0;
        }

        return $leftNormalized > $rightNormalized ? 1 : -1;
    }

    private function normalizeSortValue(string $value): string
    {
        $normalized = function_exists('remove_accents') ? remove_accents($value) : $value;

        return strtolower($normalized);
    }

    private function buildReasonSortValue(Risk $risk): string
    {
        $parts = $risk->reasons;
        foreach ($this->getRiskVulnerabilities($risk) as $vulnerability) {
            if (! empty($vulnerability['severity_label'])) {
                $parts[] = $vulnerability['severity_label'];
            }
            if (! empty($vulnerability['title'])) {
                $parts[] = $vulnerability['title'];
            }
            if (! empty($vulnerability['cve'])) {
                $parts[] = $vulnerability['cve'];
            }
        }

        return $this->normalizeSortValue(implode(' ', $parts));
    }

    private function countRiskSignals(Risk $risk): int
    {
        $count = count($risk->reasons);
        $vulnerabilities = $this->getRiskVulnerabilities($risk);
        if ($vulnerabilities !== []) {
            $count += count($vulnerabilities);
        }

        return $count;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getRiskVulnerabilities(Risk $risk): array
    {
        $vulnerabilities = $risk->details['vulnerabilities'] ?? [];
        if (! is_array($vulnerabilities)) {
            return [];
        }

        return array_values(array_filter($vulnerabilities, static fn ($item): bool => is_array($item)));
    }

    private function compareVersions(?string $left, ?string $right): int
    {
        $leftTokens = $this->parseVersionTokens($left);
        $rightTokens = $this->parseVersionTokens($right);

        if ($leftTokens['missing'] && $rightTokens['missing']) {
            return 0;
        }

        if ($leftTokens['missing']) {
            return 1;
        }

        if ($rightTokens['missing']) {
            return -1;
        }

        $maxLength = max(count($leftTokens['tokens']), count($rightTokens['tokens']));
        for ($index = 0; $index < $maxLength; $index++) {
            $leftToken = $leftTokens['tokens'][$index] ?? null;
            $rightToken = $rightTokens['tokens'][$index] ?? null;

            if ($leftToken === null && $rightToken === null) {
                return 0;
            }

            if ($leftToken === null) {
                return $this->isNumericToken($rightToken) ? -1 : 1;
            }

            if ($rightToken === null) {
                return $this->isNumericToken($leftToken) ? 1 : -1;
            }

            $leftIsNumeric = $this->isNumericToken($leftToken);
            $rightIsNumeric = $this->isNumericToken($rightToken);

            if ($leftIsNumeric && $rightIsNumeric) {
                $diff = (int) $leftToken - (int) $rightToken;
                if ($diff !== 0) {
                    return $diff;
                }
                continue;
            }

            if ($leftIsNumeric && ! $rightIsNumeric) {
                return 1;
            }

            if (! $leftIsNumeric && $rightIsNumeric) {
                return -1;
            }

            $leftLower = strtolower((string) $leftToken);
            $rightLower = strtolower((string) $rightToken);
            if ($leftLower === $rightLower) {
                continue;
            }

            return $leftLower > $rightLower ? 1 : -1;
        }

        return 0;
    }

    /**
     * @return array{tokens:string[], missing:bool}
     */
    private function parseVersionTokens(?string $version): array
    {
        if ($version === null) {
            return ['tokens' => [], 'missing' => true];
        }

        $trimmed = trim($version);
        if ($trimmed === '' || strtolower($trimmed) === 'n/a') {
            return ['tokens' => [], 'missing' => true];
        }

        preg_match_all('/[0-9]+|[a-zA-Z]+/', $trimmed, $matches);
        $tokens = $matches[0] ?? [];

        return ['tokens' => $tokens, 'missing' => $tokens === []];
    }

    private function isNumericToken(?string $token): bool
    {
        if ($token === null) {
            return false;
        }

        return preg_match('/^\d+$/', $token) === 1;
    }

    private function versionGapScore(Risk $risk): int
    {
        $localScore = $this->versionToScore($risk->localVersion);
        $remoteScore = $this->versionToScore($risk->remoteVersion ?? '');

        return abs($remoteScore - $localScore);
    }

    private function versionToScore(string $version): int
    {
        preg_match_all('/\d+/', $version, $matches);
        $numbers = $matches[0] ?? [];
        if ($numbers === []) {
            return 0;
        }

        $weights = [100000000, 100000, 100, 1];
        $score = 0;
        foreach ($weights as $index => $weight) {
            if (! isset($numbers[$index])) {
                continue;
            }
            $score += ((int) $numbers[$index]) * $weight;
        }

        return $score;
    }

    private function buildHistoryDownloadUrl(int $runAt, string $format): string
    {
        $url = add_query_arg(
            [
                'action' => self::PREFIX . '_download_history',
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
        $filename = sprintf('%s-history-%s.json', self::PREFIX, gmdate('Ymd-His', $entry['run_at']));

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
        $filename = sprintf('%s-history-%s.csv', self::PREFIX, gmdate('Ymd-His', $entry['run_at']));

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

        if ($target === 'php://output') {
            $csvContent = $this->buildCsvContent($rows);
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download response.
            echo $csvContent;
        } else {
            $filesystem = $this->getFilesystem();
            $csvContent  = $this->buildCsvContent($rows);

            if (! $filesystem->put_contents($target, $csvContent)) {
                wp_die(esc_html__('Unable to generate history export.', 'site-add-on-watchdog'));
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

    /**
     * @param array<string, string> $args
     */
    private function redirectWithNotice(array $args): void
    {
        $args['_wpnonce'] = wp_create_nonce(self::PREFIX . '_admin_notice');

        wp_safe_redirect(
            add_query_arg(
                $args,
                wp_get_referer() ?: admin_url('admin.php?page=' . self::MENU_SLUG)
            )
        );
    }

    private function isNoticeNonceValid(): bool
    {
        $nonce = filter_input(INPUT_GET, '_wpnonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        if (! is_string($nonce) || $nonce === '') {
            return false;
        }

        $nonce = sanitize_text_field($nonce);

        return wp_verify_nonce($nonce, self::PREFIX . '_admin_notice') === 1;
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
