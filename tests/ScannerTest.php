<?php

use Brain\Monkey\Functions;
use Watchdog\Models\Risk;
use Watchdog\Repository\RiskRepository;
use Watchdog\Scanner;
use Watchdog\Services\VersionComparator;
use Watchdog\Services\WPScanClient;

class ScannerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('sanitize_key')->alias(static fn ($value) => strtolower((string) $value));
    }

    public function testDetectsVersionAndChangelogRisks(): void
    {
        Functions\when('get_plugins')->justReturn([
            'sample/sample.php' => [
                'Name'    => 'Sample Plugin',
                'Version' => '1.0.0',
            ],
        ]);

        Functions\when('sanitize_title')->alias(static fn ($value) => $value);
        Functions\when('__')->alias(static fn ($text) => $text);
        Functions\when('get_option')->alias(static fn () => []);

        Functions\when('plugins_api')->alias(static function () {
            return (object) [
                'version'  => '1.4.0',
                'sections' => [
                    'changelog' => '<h4>Version 1.4.0</h4><p>Security update released</p><h4>Version 1.3.0</h4><p>Bug fixes</p>',
                ],
            ];
        });

        $repository = new RiskRepository();
        $wpscanClient = new class extends WPScanClient {
            /** @var array<int, array{0:string, 1:string}> */
            public array $requests = [];

            public function __construct()
            {
            }

            public function fetchVulnerabilities(string $pluginSlug, string $pluginVersion = ''): array
            {
                $this->requests[] = [$pluginSlug, $pluginVersion];

                return [];
            }
        };

        $scanner = new Scanner($repository, new VersionComparator(), $wpscanClient);
        $risks   = $scanner->scan();

        $this->assertCount(1, $risks);
        $this->assertSame('sample', $risks[0]->pluginSlug);
        $this->assertContains('An update is available in the plugin directory.', $risks[0]->reasons);
        $this->assertContains('An update is available in the plugin directory.', $risks[0]->toArray()['reasons']);
        $this->assertContains('Changelog mentions security-related updates.', $risks[0]->reasons);
        $this->assertSame([['sample', '1.0.0']], $wpscanClient->requests);
    }

    public function testIgnoresSecurityMentionsFromOlderChangelogEntries(): void
    {
        Functions\when('get_plugins')->justReturn([
            'sample/sample.php' => [
                'Name'    => 'Sample Plugin',
                'Version' => '1.2.0',
            ],
        ]);

        Functions\when('sanitize_title')->alias(static fn ($value) => $value);
        Functions\when('__')->alias(static fn ($text) => $text);
        Functions\when('get_option')->alias(static fn () => []);

        Functions\when('plugins_api')->alias(static function () {
            return (object) [
                'version'  => '1.4.0',
                'sections' => [
                    'changelog' => '<h4>Version 1.4.0</h4><p>Performance improvements</p><h4>Version 1.3.0</h4><p>Security fix applied</p>',
                ],
            ];
        });

        $repository = new RiskRepository();
        $wpscanClient = new class extends WPScanClient {
            public function __construct()
            {
            }

            public function fetchVulnerabilities(string $pluginSlug, string $pluginVersion = ''): array
            {
                return [];
            }
        };

        $scanner = new Scanner($repository, new VersionComparator(), $wpscanClient);
        $risks   = $scanner->scan();

        $this->assertCount(1, $risks);
        $this->assertSame('sample', $risks[0]->pluginSlug);
        $this->assertContains('An update is available in the plugin directory.', $risks[0]->reasons);
        $this->assertNotContains('Changelog mentions security-related updates.', $risks[0]->reasons);
    }

    /**
     * @dataProvider changelogHeadingProvider
     */
    public function testMatchesOnlyTheExactReleasedChangelogVersion(string $changelog, bool $security): void
    {
        Functions\when('get_plugins')->justReturn([
            'sample/sample.php' => ['Name' => 'Sample Plugin', 'Version' => '1.0.0'],
        ]);
        Functions\when('sanitize_title')->alias(static fn ($value) => $value);
        Functions\when('__')->alias(static fn ($text) => $text);
        Functions\when('get_option')->justReturn([]);
        Functions\when('plugins_api')->justReturn((object) [
            'version' => '1.4.0',
            'sections' => ['changelog' => $changelog],
        ]);

        $scanner = new Scanner(new RiskRepository(), new VersionComparator(), new WPScanClient(null));
        $risks = $scanner->scan();

        self::assertCount(1, $risks);
        self::assertSame($security, in_array('Changelog mentions security-related updates.', $risks[0]->reasons, true));
    }

    public static function changelogHeadingProvider(): array
    {
        return [
            'version prefix' => ['<h4>1.4.01</h4><p>Security fix</p><h4>1.4.0</h4><p>Maintenance</p>', false],
            'version suffix' => ['<h4>11.4.0</h4><p>Security fix</p><h4>1.4.0</h4><p>Maintenance</p>', false],
            'prerelease' => ['<h4>1.4.0-beta</h4><p>Security fix</p><h4>1.4.0</h4><p>Maintenance</p>', false],
            'missing release' => ['<h4>1.3.0</h4><p>Security fix</p>', false],
            'h3 old entry' => ['<h3>1.4.0</h3><p>Maintenance</p><h3>1.3.0</h3><p>Security fix</p>', false],
            'formatted heading' => ['<h3><strong>v1.4.0</strong> (2026-09-11)</h3><p>Security fix</p>', true],
            'h2 heading' => ['<h2>1.4.0</h2><p>Security fix</p>', true],
            'plain changelog' => ['Security update for the latest release.', true],
        ];
    }

    public function testIgnoresSecurityMentionsWhenLocalVersionIsLatest(): void
    {
        Functions\when('get_plugins')->justReturn([
            'sample/sample.php' => [
                'Name'    => 'Sample Plugin',
                'Version' => '1.4.0',
            ],
        ]);

        Functions\when('sanitize_title')->alias(static fn ($value) => $value);
        Functions\when('__')->alias(static fn ($text) => $text);
        Functions\when('get_option')->alias(static fn () => []);

        Functions\when('plugins_api')->alias(static function () {
            return (object) [
                'version'  => '1.4.0',
                'sections' => [
                    'changelog' => '<h4>Version 1.4.0</h4><p>Security update released</p>',
                ],
            ];
        });

        $repository = new RiskRepository();
        $wpscanClient = new class extends WPScanClient {
            public function __construct()
            {
            }

            public function fetchVulnerabilities(string $pluginSlug, string $pluginVersion = ''): array
            {
                return [];
            }
        };

        $scanner = new Scanner($repository, new VersionComparator(), $wpscanClient);
        $risks   = $scanner->scan();

        $this->assertCount(0, $risks);
    }

    public function testAddsSeverityToVulnerabilityDetails(): void
    {
        Functions\when('get_plugins')->justReturn([
            'sample/sample.php' => [
                'Name'    => 'Sample Plugin',
                'Version' => '1.0.0',
            ],
        ]);

        Functions\when('sanitize_title')->alias(static fn ($value) => $value);
        Functions\when('__')->alias(static fn ($text) => $text);
        Functions\when('get_option')->alias(static fn () => []);

        Functions\when('plugins_api')->alias(static function () {
            return (object) [
                'version'  => '1.2.0',
                'sections' => [
                    'changelog' => '<h4>Version 1.2.0</h4><p>Security fixes</p>',
                ],
            ];
        });

        $repository = new RiskRepository();
        $wpscanClient = new class extends WPScanClient {
            public function __construct()
            {
            }

            public function fetchVulnerabilities(string $pluginSlug, string $pluginVersion = ''): array
            {
                return [
                    [
                        'title'      => 'Critical SQL Injection',
                        'cvss_score' => '9.1',
                    ],
                    [
                        'title'      => 'Stored XSS',
                        'cvss_score' => '5.6',
                    ],
                ];
            }
        };

        $scanner = new Scanner($repository, new VersionComparator(), $wpscanClient);
        $risks   = $scanner->scan();

        $this->assertCount(1, $risks);
        $this->assertArrayHasKey('vulnerabilities', $risks[0]->details);
        $this->assertSame('severe', $risks[0]->details['vulnerabilities'][0]['severity']);
        $this->assertSame('Severe', $risks[0]->details['vulnerabilities'][0]['severity_label']);
        $this->assertSame('medium', $risks[0]->details['vulnerabilities'][1]['severity']);
        $this->assertSame('Medium', $risks[0]->details['vulnerabilities'][1]['severity_label']);
    }

    public function testOnePluginFailureDoesNotAbortRemainingScan(): void
    {
        Functions\when('get_plugins')->justReturn([
            'broken/broken.php' => ['Name' => 'Broken Plugin', 'Version' => '1.0.0'],
            'healthy/healthy.php' => ['Name' => 'Healthy Plugin', 'Version' => '1.0.0'],
        ]);
        Functions\when('sanitize_title')->alias(static fn ($value) => $value);
        Functions\when('__')->alias(static fn ($text) => $text);
        Functions\when('get_option')->alias(static fn () => []);
        Functions\when('plugins_api')->justReturn((object) [
            'version' => '1.1.0',
            'sections' => ['changelog' => '<h4>1.1.0</h4><p>Maintenance</p>'],
        ]);

        $repository = new RiskRepository();
        $wpscanClient = new class extends WPScanClient {
            public function __construct()
            {
            }

            public function fetchVulnerabilities(string $pluginSlug, string $pluginVersion = ''): array
            {
                if ($pluginSlug === 'broken') {
                    throw new RuntimeException('Malformed provider response');
                }

                return [];
            }
        };

        $scanner = new Scanner($repository, new VersionComparator(), $wpscanClient);
        $risks = $scanner->scan();

        self::assertCount(1, $risks);
        self::assertSame('healthy', $risks[0]->pluginSlug);
    }

    public function testDirectoryFailureKeepsPriorRiskAndRetriesOnNextScan(): void
    {
        Functions\when('get_plugins')->justReturn([
            'broken/broken.php' => ['Name' => 'Broken Plugin', 'Version' => '1.0.0'],
            'healthy/healthy.php' => ['Name' => 'Healthy Plugin', 'Version' => '1.0.0'],
        ]);
        Functions\when('sanitize_title')->alias(static fn ($value) => $value);
        Functions\when('sanitize_text_field')->alias(static fn ($value) => $value);
        Functions\when('__')->alias(static fn ($text) => $text);
        Functions\when('get_option')->alias(static function (string $option) {
            if ($option === 'siteadwa_risks') {
                return [(new Risk('broken', 'Broken Plugin', '1.0.0', '1.2.0', ['Prior risk']))->toArray()];
            }

            return [];
        });

        $failure = new \stdClass();
        Functions\when('is_wp_error')->alias(static fn ($value): bool => $value === $failure);
        $requests = [];
        Functions\when('plugins_api')->alias(static function ($action, $args) use ($failure, &$requests) {
            $requests[] = $args['slug'];
            if ($args['slug'] === 'broken' && count($requests) < 3) {
                return $failure;
            }

            return (object) ['version' => '1.2.0', 'sections' => []];
        });

        $wpscanClient = new class extends WPScanClient {
            public function __construct()
            {
            }

            public function fetchVulnerabilities(string $pluginSlug, string $pluginVersion = ''): array
            {
                return [];
            }
        };
        $scanner = new Scanner(new RiskRepository(), new VersionComparator(), $wpscanClient);

        $first = $scanner->scan();
        self::assertSame(['broken', 'healthy'], array_map(static fn (Risk $risk) => $risk->pluginSlug, $first));
        self::assertSame(['Prior risk'], $first[0]->reasons);

        $second = $scanner->scan();
        self::assertSame(['broken', 'healthy', 'broken', 'healthy'], $requests);
        self::assertContains('An update is available in the plugin directory.', $second[0]->reasons);
    }
}
