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

            if ($remote && isset($remote->sections['changelog'])) {
                $changelog = strtolower(strip_tags((string) $remote->sections['changelog']));
                if (str_contains($changelog, 'security') || str_contains($changelog, 'vulnerability')) {
                    $reasons[] = __(
                        'Changelog mentions security-related updates.',
                        'wp-plugin-watchdog'
                    );
                }
            }

            $vulnerabilities = $this->wpscanClient->fetchVulnerabilities($slug);
            if (! empty($vulnerabilities)) {
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
                'versions' => false,
            ],
        ]);

        if (is_wp_error($result)) {
            return false;
        }

        return $result;
    }
}
