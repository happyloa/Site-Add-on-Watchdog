<?php

namespace Watchdog\Services;

class WPScanClient
{
    public function __construct(private readonly ?string $apiKey)
    {
    }

    public function isEnabled(): bool
    {
        return ! empty($this->apiKey);
    }

    public function fetchVulnerabilities(string $pluginSlug): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        $response = wp_remote_get(
            sprintf('https://wpscan.com/api/v3/plugins/%s', rawurlencode($pluginSlug)),
            [
                'headers' => [
                    'Authorization' => sprintf('Token token=%s', $this->apiKey),
                    'Accept'        => 'application/json',
                ],
                'timeout' => 15,
            ]
        );

        if (is_wp_error($response)) {
            return [];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (! is_array($body) || empty($body['vulnerabilities'])) {
            return [];
        }

        return array_map(
            static fn (array $vulnerability): array => [
                'title'       => $vulnerability['title'] ?? '',
                'references'  => $vulnerability['references'] ?? [],
                'fixed_in'    => $vulnerability['fixed_in'] ?? null,
                'cve'         => $vulnerability['cve'] ?? null,
                'cvss_score'  => $vulnerability['cvss_score'] ?? null,
                'discovered'  => $vulnerability['discovered_date'] ?? null,
            ],
            $body['vulnerabilities']
        );
    }
}
