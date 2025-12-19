<?php

namespace Watchdog;

use Watchdog\Models\Risk;
use Watchdog\Repository\RiskRepository;
use Watchdog\Services\VersionComparator;
use Watchdog\Services\WPScanClient;

class Scanner
{
    private const PLUGIN_INFO_CACHE_TTL = 12 * 3600;

    public function __construct(
        private readonly RiskRepository $riskRepository,
        private readonly VersionComparator $versionComparator,
        private readonly WPScanClient $wpscanClient
    ) {
    }

    /**
     * @return Risk[]
     */
    public function scan(): array
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();
        $ignored = $this->riskRepository->ignored();

        $risks = [];
        foreach ($plugins as $pluginFile => $pluginData) {
            $slug = $this->determineSlug($pluginFile);
            if (in_array($slug, $ignored, true)) {
                continue;
            }

            $localVersion = $pluginData['Version'] ?? '';
            $remote = $this->fetchRemoteData($slug, $pluginFile, $localVersion);
            $reasons = [];
            $details = [];

            $remoteVersion = is_object($remote) && isset($remote->version) ? (string) $remote->version : null;

            if (
                $remoteVersion &&
                $localVersion &&
                version_compare($remoteVersion, $localVersion, '>')
            ) {
                $reasons[] = __(
                    'An update is available in the plugin directory.',
                    'site-add-on-watchdog'
                );
            }

            if (
                $remoteVersion &&
                $localVersion &&
                $this->versionComparator->isTwoMinorVersionsBehind($localVersion, $remoteVersion)
            ) {
                $reasons[] = __(
                    'Local version is more than two minor releases behind the directory version.',
                    'site-add-on-watchdog'
                );
            }

            if (
                $remote &&
                isset($remote->sections['changelog']) &&
                $this->changelogHighlightsSecurity(
                    (string) $remote->sections['changelog'],
                    $localVersion,
                    $remoteVersion
                )
            ) {
                $reasons[] = __(
                    'Changelog mentions security-related updates.',
                    'site-add-on-watchdog'
                );
            }

            $vulnerabilities = $this->wpscanClient->fetchVulnerabilities($slug);
            if (! empty($vulnerabilities)) {
                $vulnerabilities = array_map(
                    fn (array $vulnerability): array => $this->enrichVulnerability($vulnerability),
                    $vulnerabilities
                );

                $reasons[] = __(
                    'Active vulnerabilities reported by WPScan.',
                    'site-add-on-watchdog'
                );
                $details['vulnerabilities'] = $vulnerabilities;
            }

            if (! empty($reasons)) {
                $risks[] = new Risk(
                    $slug,
                    $pluginData['Name'] ?? $slug,
                    $localVersion,
                    $remoteVersion,
                    $reasons,
                    $details
                );
            }
        }

        return $risks;
    }

    private function enrichVulnerability(array $vulnerability): array
    {
        if (! array_key_exists('cvss_score', $vulnerability)) {
            return $vulnerability;
        }

        $score = $vulnerability['cvss_score'];
        $numericScore = null;

        if (is_numeric($score)) {
            $numericScore = (float) $score;
        }

        if ($numericScore === null) {
            return $vulnerability;
        }

        $severity = $this->cvssScoreToSeverity($numericScore);

        if ($severity === null) {
            return $vulnerability;
        }

        $vulnerability['severity']       = $severity['key'];
        $vulnerability['severity_label'] = $severity['label'];

        return $vulnerability;
    }

    private function cvssScoreToSeverity(float $score): ?array
    {
        if ($score < 0) {
            return null;
        }

        if ($score >= 9.0) {
            return [
                'key'   => 'severe',
                'label' => __('Severe', 'site-add-on-watchdog'),
            ];
        }

        if ($score >= 7.0) {
            return [
                'key'   => 'high',
                'label' => __('High', 'site-add-on-watchdog'),
            ];
        }

        if ($score >= 4.0) {
            return [
                'key'   => 'medium',
                'label' => __('Medium', 'site-add-on-watchdog'),
            ];
        }

        return [
            'key'   => 'low',
            'label' => __('Low', 'site-add-on-watchdog'),
        ];
    }

    private function determineSlug(string $pluginFile): string
    {
        $basename = dirname($pluginFile);
        if ($basename === '.' || $basename === '') {
            $basename = basename($pluginFile, '.php');
        }

        return sanitize_title($basename);
    }

    private function fetchRemoteData(string $slug, string $pluginFile, string $localVersion): object|false
    {
        $cacheKey = $this->pluginInfoCacheKey($slug);
        $cached = get_transient($cacheKey);
        if ($cached !== false) {
            $this->logCacheEvent(sprintf('[Site Add-on Watchdog] Plugin info cache hit for %s.', $slug));
            return $cached;
        }

        $this->logCacheEvent(sprintf('[Site Add-on Watchdog] Plugin info cache miss for %s.', $slug));

        $updatePlugins = get_site_transient('update_plugins');
        $updateData = $this->extractUpdateData($updatePlugins, $pluginFile);
        if ($updateData !== null) {
            $remoteVersion = $updateData['new_version'] ?: null;
            if (! $updateData['has_update']) {
                $result = (object) [
                    'version'  => $remoteVersion,
                    'sections' => [],
                ];

                set_transient($cacheKey, $result, $this->pluginInfoCacheTtl());

                return $result;
            }

            if ($remoteVersion !== null && ! version_compare($remoteVersion, $localVersion, '>')) {
                $result = (object) [
                    'version'  => $remoteVersion,
                    'sections' => [],
                ];

                set_transient($cacheKey, $result, $this->pluginInfoCacheTtl());

                return $result;
            }
        }

        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

        $result = plugins_api('plugin_information', [
            'slug'   => $slug,
            'fields' => [
                'sections' => true,
                'versions' => true,
            ],
        ]);

        if (is_wp_error($result)) {
            return false;
        }

        set_transient($cacheKey, $result, $this->pluginInfoCacheTtl());

        return $result;
    }

    private function pluginInfoCacheKey(string $slug): string
    {
        $key = sprintf('siteadwa_plugin_info_%s', $slug);

        if (function_exists('sanitize_key')) {
            return sanitize_key($key);
        }

        return $key;
    }

    private function pluginInfoCacheTtl(): int
    {
        if (defined('HOUR_IN_SECONDS')) {
            return 12 * HOUR_IN_SECONDS;
        }

        return self::PLUGIN_INFO_CACHE_TTL;
    }

    private function extractUpdateData($updatePlugins, string $pluginFile): ?array
    {
        if (! is_object($updatePlugins) && ! is_array($updatePlugins)) {
            return null;
        }

        $response = is_object($updatePlugins)
            ? ($updatePlugins->response ?? [])
            : ($updatePlugins['response'] ?? []);
        $noUpdate = is_object($updatePlugins)
            ? ($updatePlugins->no_update ?? [])
            : ($updatePlugins['no_update'] ?? []);

        if (isset($response[$pluginFile])) {
            $entry = $response[$pluginFile];

            return [
                'has_update'  => true,
                'new_version' => is_object($entry) ? ($entry->new_version ?? '') : ($entry['new_version'] ?? ''),
            ];
        }

        if (isset($noUpdate[$pluginFile])) {
            $entry = $noUpdate[$pluginFile];

            return [
                'has_update'  => false,
                'new_version' => is_object($entry) ? ($entry->new_version ?? '') : ($entry['new_version'] ?? ''),
            ];
        }

        return null;
    }

    private function logCacheEvent(string $message): void
    {
        if (function_exists('wp_debug_log')) {
            wp_debug_log($message);
        }
    }

    private function changelogHighlightsSecurity(
        string $changelogHtml,
        string $localVersion,
        ?string $remoteVersion
    ): bool {
        if ($remoteVersion === null || $localVersion === '') {
            return false;
        }

        if (! version_compare($remoteVersion, $localVersion, '>')) {
            return false;
        }

        $entryHtml = $this->extractLatestChangelogEntry($changelogHtml, $remoteVersion);
        if ($entryHtml === '') {
            return false;
        }

        $normalized = strtolower($this->stripAllTags($entryHtml));

        return str_contains($normalized, 'security')
            || str_contains($normalized, 'vulnerability');
    }

    private function stripAllTags(string $text): string
    {
        if (function_exists('wp_strip_all_tags')) {
            return \wp_strip_all_tags($text);
        }

        return preg_replace('/<[^>]*>/', '', $text) ?? '';
    }

    private function extractLatestChangelogEntry(string $changelogHtml, string $remoteVersion): string
    {
        $changelogHtml = trim($changelogHtml);
        if ($changelogHtml === '') {
            return '';
        }

        $patternForVersion = sprintf(
            '/<h4[^>]*>[^<]*%s[^<]*<\/h4>\s*(.*?)(?=<h4|\z)/is',
            preg_quote($remoteVersion, '/')
        );

        if (preg_match($patternForVersion, $changelogHtml, $match)) {
            return $match[0];
        }

        if (preg_match('/<h4[^>]*>.*?<\/h4>\s*(.*?)(?=<h4|\z)/is', $changelogHtml, $match)) {
            return $match[0];
        }

        return $changelogHtml;
    }
}
