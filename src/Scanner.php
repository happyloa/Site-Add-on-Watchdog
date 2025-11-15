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

            if (
                $remoteVersion &&
                $localVersion &&
                version_compare($remoteVersion, $localVersion, '>')
            ) {
                $reasons[] = __(
                    'An update is available in the plugin directory.',
                    'wp-plugin-watchdog'
                );
            }

            if (
                $remoteVersion &&
                $localVersion &&
                $this->versionComparator->isTwoMinorVersionsBehind($localVersion, $remoteVersion)
            ) {
                $reasons[] = __(
                    'Local version is more than two minor releases behind the directory version.',
                    'wp-plugin-watchdog'
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
                    'wp-plugin-watchdog'
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
                    'wp-plugin-watchdog'
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
                'label' => __('Severe', 'wp-plugin-watchdog'),
            ];
        }

        if ($score >= 7.0) {
            return [
                'key'   => 'high',
                'label' => __('High', 'wp-plugin-watchdog'),
            ];
        }

        if ($score >= 4.0) {
            return [
                'key'   => 'medium',
                'label' => __('Medium', 'wp-plugin-watchdog'),
            ];
        }

        return [
            'key'   => 'low',
            'label' => __('Low', 'wp-plugin-watchdog'),
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

        if (! version_compare($remoteVersion, $localVersion, '>')) {
            return false;
        }

        $entryHtml = $this->extractLatestChangelogEntry($changelogHtml, $remoteVersion);
        if ($entryHtml === '') {
            return false;
        }

        $normalized = strtolower(strip_tags($entryHtml));

        return str_contains($normalized, 'security')
            || str_contains($normalized, 'vulnerability');
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
