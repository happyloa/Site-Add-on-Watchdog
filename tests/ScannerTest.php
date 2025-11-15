<?php

use Brain\Monkey\Functions;
use Watchdog\Repository\RiskRepository;
use Watchdog\Scanner;
use Watchdog\Services\VersionComparator;
use Watchdog\Services\WPScanClient;

class ScannerTest extends TestCase
{
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
            public function __construct()
            {
            }

            public function fetchVulnerabilities(string $pluginSlug): array
            {
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

            public function fetchVulnerabilities(string $pluginSlug): array
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

            public function fetchVulnerabilities(string $pluginSlug): array
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

            public function fetchVulnerabilities(string $pluginSlug): array
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
}
