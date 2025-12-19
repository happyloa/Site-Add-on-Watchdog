<?php

namespace Watchdog\Services;

use Watchdog\Version;

class WPScanClient
{
    private const CACHE_TTL_HOURS = 12;
    private const ERROR_TTL_HOURS = 6;

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

        $cacheKey = $this->getCacheKey($pluginSlug);
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
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
            $this->recordErrorResponse($code);
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (! is_array($body) || empty($body['vulnerabilities'])) {
            delete_transient($this->getErrorKey());
            set_transient($cacheKey, [], $this->getCacheTtl());
            return [];
        }

        $vulnerabilities = array_map(
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

        delete_transient($this->getErrorKey());
        set_transient($cacheKey, $vulnerabilities, $this->getCacheTtl());

        return $vulnerabilities;
    }

    private function getCacheKey(string $pluginSlug): string
    {
        return sprintf('%s_wpscan_%s', Version::PREFIX, sanitize_key($pluginSlug));
    }

    private function getErrorKey(): string
    {
        return Version::PREFIX . '_wpscan_error';
    }

    private function getCacheTtl(): int
    {
        $hour = defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600;

        return self::CACHE_TTL_HOURS * $hour;
    }

    private function getErrorTtl(): int
    {
        $hour = defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600;

        return self::ERROR_TTL_HOURS * $hour;
    }

    private function recordErrorResponse(int $code): void
    {
        if ($code !== 429 && $code < 500) {
            return;
        }

        $message = $code === 429
            ? __('WPScan API rate limited; queries are paused temporarily.', 'site-add-on-watchdog')
            : __('WPScan API is temporarily unavailable; queries are paused.', 'site-add-on-watchdog');

        set_transient(
            $this->getErrorKey(),
            [
                'code'    => $code,
                'message' => $message,
            ],
            $this->getErrorTtl()
        );
    }
}
