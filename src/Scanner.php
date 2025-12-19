<?php

namespace Watchdog;

use Watchdog\Models\Risk;
use Watchdog\Repository\RiskRepository;
use Watchdog\Services\VersionComparator;
use Watchdog\Services\WPScanClient;

class Scanner
{
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

            $remote = $this->fetchRemoteData($slug);
            $reasons = [];
            $details = [];

            $localVersion  = $pluginData['Version'] ?? '';
            $remoteVersion = is_object($remote) && isset($remote->version) ? (string) $remote->version : null;
            $hasComparableVersions = $remoteVersion
                && $localVersion
                && $this->versionComparator->isStandardVersion($localVersion)
                && $this->versionComparator->isStandardVersion($remoteVersion);

            if (
                $hasComparableVersions &&
                version_compare($remoteVersion, $localVersion, '>')
            ) {
                $reasons[] = $this->formatVersionComparisonReason(
                    $localVersion,
                    $remoteVersion,
                    __('update available', 'site-add-on-watchdog')
                );
            }

            if (
                $hasComparableVersions &&
                $this->versionComparator->isTwoMinorVersionsBehind($localVersion, $remoteVersion)
            ) {
                $minorGap = $this->versionComparator->minorVersionsBehind($localVersion, $remoteVersion);
                if ($minorGap !== null && $minorGap >= 2) {
                    $minorLabel = sprintf(
                        /* translators: %d is the number of minor versions behind. */
                        _n('%d minor behind', '%d minors behind', $minorGap, 'site-add-on-watchdog'),
                        $minorGap
                    );
                    $reasons[] = $this->formatVersionComparisonReason(
                        $localVersion,
                        $remoteVersion,
                        $minorLabel
                    );
                }
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

    private function fetchRemoteData(string $slug): object|false
    {
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

        return $result;
    }

    private function changelogHighlightsSecurity(
        string $changelogHtml,
        string $localVersion,
        ?string $remoteVersion
    ): bool {
        if ($remoteVersion === null || $localVersion === '') {
            return false;
        }

        if (
            ! $this->versionComparator->isStandardVersion($localVersion)
            || ! $this->versionComparator->isStandardVersion($remoteVersion)
        ) {
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

    private function formatVersionComparisonReason(
        string $localVersion,
        string $remoteVersion,
        string $descriptor
    ): string {
        return sprintf(
            /* translators: 1: local version, 2: directory version, 3: comparison descriptor. */
            __('Local %1$s vs Directory %2$s (%3$s)', 'site-add-on-watchdog'),
            $localVersion,
            $remoteVersion,
            $descriptor
        );
    }
}
