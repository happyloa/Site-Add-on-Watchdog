<?php

namespace Watchdog;

use Watchdog\Models\Risk;
use Watchdog\Repository\RiskRepository;
use Watchdog\Services\VersionComparator;
use Watchdog\Services\WPScanClient;
use Watchdog\Version;

class Scanner
{
    private const REMOTE_CACHE_HOURS = 6;

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
        $previousRisks = null;

        $risks = [];
        foreach ($plugins as $pluginFile => $pluginData) {
            if (! is_string($pluginFile) || ! is_array($pluginData)) {
                continue;
            }

            $slug = $this->determineSlug($pluginFile);
            if (in_array($slug, $ignored, true)) {
                continue;
            }

            try {
                $risk = $this->scanPlugin($slug, $pluginData);
            } catch (\Throwable $error) {
                $this->recordPluginScanError($slug, $error);
                if ($previousRisks === null) {
                    $previousRisks = [];
                    foreach ($this->riskRepository->all() as $previousRisk) {
                        $previousRisks[$previousRisk->pluginSlug] = $previousRisk;
                    }
                }
                if (
                    isset($previousRisks[$slug])
                    && $previousRisks[$slug]->localVersion === (string) ($pluginData['Version'] ?? '')
                ) {
                    $risks[] = $previousRisks[$slug];
                }
                continue;
            }

            if ($risk !== null) {
                $risks[] = $risk;
            }
        }

        return $risks;
    }

    /**
     * @param array<string, mixed> $pluginData
     */
    private function scanPlugin(string $slug, array $pluginData): ?Risk
    {
        $remote         = $this->fetchRemoteData($slug);
        $reasons        = [];
        $details        = [];
        $localVersion   = isset($pluginData['Version']) ? (string) $pluginData['Version'] : '';
        $remoteVersion  = is_object($remote) && isset($remote->version) ? (string) $remote->version : null;

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

        $vulnerabilities = array_values(array_filter(
            $this->wpscanClient->fetchVulnerabilities($slug, $localVersion),
            'is_array'
        ));
        if ($vulnerabilities !== []) {
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

        if ($reasons === []) {
            return null;
        }

        return new Risk(
            $slug,
            isset($pluginData['Name']) ? (string) $pluginData['Name'] : $slug,
            $localVersion,
            $remoteVersion,
            $reasons,
            $details
        );
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

    private function fetchRemoteData(string $slug): object|false
    {
        $cacheKey = Version::PREFIX . '_plugin_info_' . sanitize_key($slug);
        $cached   = get_transient($cacheKey);
        if (is_object($cached)) {
            return $cached;
        }
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

        $result = plugins_api('plugin_information', [
            'slug'   => $slug,
            'fields' => [
                'sections' => true,
                'versions' => false,
            ],
        ]);

        if (is_wp_error($result)) {
            // A temporary directory/API failure must not erase a saved risk or
            // suppress retries for the full successful-response cache period.
            throw new \RuntimeException('WordPress.org plugin information is unavailable.');
        }

        set_transient($cacheKey, $result, $this->remoteCacheTtl());

        return $result;
    }

    private function remoteCacheTtl(): int
    {
        $hour = defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600;

        return self::REMOTE_CACHE_HOURS * $hour;
    }

    private function recordPluginScanError(string $slug, \Throwable $error): void
    {
        do_action('site_add_on_watchdog_diagnostic', 'plugin_scan_failed', [
            'plugin'    => sanitize_key($slug),
            'exception' => get_class($error),
        ]);
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

        $headingPattern = '/<h([2-6])\b[^>]*>(.*?)<\/h\1>\s*(.*?)(?=<h[2-6]\b|\z)/is';
        if (preg_match_all($headingPattern, $changelogHtml, $entries, PREG_SET_ORDER)) {
            $versionPattern = '/(?<![0-9A-Za-z.])v?' . preg_quote($remoteVersion, '/')
                . '(?![0-9A-Za-z.+-])/i';

            foreach ($entries as $entry) {
                $heading = $this->stripAllTags($entry[2]);
                if (preg_match($versionPattern, $heading)) {
                    return $entry[3];
                }
            }

            return '';
        }

        return $changelogHtml;
    }
}
