<?php

use function Brain\Monkey\Functions\expect;
use function Brain\Monkey\Functions\when;
use Watchdog\Services\WPScanClient;

if (! defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

class WPScanClientTest extends TestCase
{
    public function testCachesVulnerabilitiesOnSuccess(): void
    {
        when('__')->alias(static fn (string $text): string => $text);
        when('sanitize_key')->alias(static fn (string $text): string => $text);
        when('is_wp_error')->alias(static fn (): bool => false);
        when('wp_remote_retrieve_response_code')->alias(static fn (array $response): int => $response['response']['code']);
        when('wp_remote_retrieve_body')->alias(static fn (array $response): string => $response['body']);

        expect('get_transient')
            ->once()
            ->with('siteadwa_wpscan_contact-form-7')
            ->andReturn(false);

        expect('wp_remote_get')
            ->once()
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => json_encode([
                    'vulnerabilities' => [
                        [
                            'title' => 'Sample Vuln',
                        ],
                    ],
                ], JSON_THROW_ON_ERROR),
            ]);

        expect('delete_transient')
            ->once()
            ->with('siteadwa_wpscan_error');

        expect('set_transient')
            ->once()
            ->withArgs(function (string $key, array $value, int $ttl): bool {
                self::assertSame('siteadwa_wpscan_contact-form-7', $key);
                self::assertSame([
                    [
                        'title'      => 'Sample Vuln',
                        'references' => [],
                        'fixed_in'   => null,
                        'cve'        => null,
                        'cvss_score' => null,
                        'discovered' => null,
                    ],
                ], $value);
                self::assertSame(12 * HOUR_IN_SECONDS, $ttl);

                return true;
            });

        $client = new WPScanClient('api-key');
        $result = $client->fetchVulnerabilities('contact-form-7');

        $this->assertSame([
            [
                'title'      => 'Sample Vuln',
                'references' => [],
                'fixed_in'   => null,
                'cve'        => null,
                'cvss_score' => null,
                'discovered' => null,
            ],
        ], $result);
    }

    public function testStoresRateLimitErrors(): void
    {
        when('__')->alias(static fn (string $text): string => $text);
        when('sanitize_key')->alias(static fn (string $text): string => $text);
        when('is_wp_error')->alias(static fn (): bool => false);
        when('wp_remote_retrieve_response_code')->alias(static fn (array $response): int => $response['response']['code']);
        when('wp_remote_retrieve_body')->alias(static fn (array $response): string => $response['body']);

        expect('get_transient')
            ->once()
            ->with('siteadwa_wpscan_akismet')
            ->andReturn(false);

        expect('wp_remote_get')
            ->once()
            ->andReturn([
                'response' => ['code' => 429],
                'body'     => '',
            ]);

        expect('delete_transient')->never();

        expect('set_transient')
            ->once()
            ->withArgs(function (string $key, array $value, int $ttl): bool {
                self::assertSame('siteadwa_wpscan_error', $key);
                self::assertSame(429, $value['code']);
                self::assertSame('WPScan API rate limited; queries are paused temporarily.', $value['message']);
                self::assertSame(6 * HOUR_IN_SECONDS, $ttl);

                return true;
            });

        $client = new WPScanClient('api-key');

        $this->assertSame([], $client->fetchVulnerabilities('akismet'));
    }

    public function testStoresServerErrors(): void
    {
        when('__')->alias(static fn (string $text): string => $text);
        when('sanitize_key')->alias(static fn (string $text): string => $text);
        when('is_wp_error')->alias(static fn (): bool => false);
        when('wp_remote_retrieve_response_code')->alias(static fn (array $response): int => $response['response']['code']);
        when('wp_remote_retrieve_body')->alias(static fn (array $response): string => $response['body']);

        expect('get_transient')
            ->once()
            ->with('siteadwa_wpscan_jetpack')
            ->andReturn(false);

        expect('wp_remote_get')
            ->once()
            ->andReturn([
                'response' => ['code' => 500],
                'body'     => '',
            ]);

        expect('delete_transient')->never();

        expect('set_transient')
            ->once()
            ->withArgs(function (string $key, array $value, int $ttl): bool {
                self::assertSame('siteadwa_wpscan_error', $key);
                self::assertSame(500, $value['code']);
                self::assertSame('WPScan API is temporarily unavailable; queries are paused.', $value['message']);
                self::assertSame(6 * HOUR_IN_SECONDS, $ttl);

                return true;
            });

        $client = new WPScanClient('api-key');

        $this->assertSame([], $client->fetchVulnerabilities('jetpack'));
    }
}
