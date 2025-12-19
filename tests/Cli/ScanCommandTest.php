<?php

use function Brain\Monkey\Functions\when;
use Watchdog\Cli\ScanCommand;
use Watchdog\Models\Risk;
use Watchdog\Notifier;
use Watchdog\Plugin;
use Watchdog\Repository\RiskRepository;
use Watchdog\Repository\SettingsRepository;
use Watchdog\Scanner;

if (! class_exists('WP_CLI')) {
    class WP_CLI
    {
        public static array $successes = [];
        public static array $errors = [];

        public static function success(string $message): void
        {
            self::$successes[] = $message;
        }

        public static function error(string $message): void
        {
            self::$errors[] = $message;
        }

        public static function reset(): void
        {
            self::$successes = [];
            self::$errors = [];
        }
    }
}

class ScanCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        WP_CLI::reset();
    }

    public function testCommandRunsScanAndNotifiesByDefault(): void
    {
        when('wp_json_encode')->alias(static fn ($data) => json_encode($data, JSON_THROW_ON_ERROR));

        $risk = new Risk('plugin-slug', 'Plugin Name', '1.0.0', null, ['Example reason']);
        $risks = [$risk];

        $scanner = $this->createMock(Scanner::class);
        $scanner->expects($this->once())
            ->method('scan')
            ->willReturn($risks);

        $riskRepository = $this->createMock(RiskRepository::class);
        $riskRepository->expects($this->once())
            ->method('save')
            ->with($risks);

        $settingsRepository = $this->createMock(SettingsRepository::class);
        $settingsRepository->expects($this->once())
            ->method('get')
            ->willReturn([
                'last_notification' => '',
                'notifications'     => [
                    'frequency'           => 'daily',
                    'testing_expires_at'  => 0,
                ],
                'history'           => [
                    'retention' => RiskRepository::DEFAULT_HISTORY_RETENTION,
                ],
            ]);
        $expectedHash = md5(json_encode([
            [
                'plugin_slug'    => 'plugin-slug',
                'plugin_name'    => 'Plugin Name',
                'local_version'  => '1.0.0',
                'remote_version' => null,
                'reasons'        => ['Example reason'],
                'details'        => [],
            ],
        ], JSON_THROW_ON_ERROR));
        $settingsRepository->expects($this->once())
            ->method('saveNotificationHash')
            ->with($expectedHash);

        $notifier = $this->createMock(Notifier::class);
        $notifier->expects($this->once())
            ->method('notify')
            ->with($risks);

        $plugin = new Plugin($scanner, $riskRepository, $settingsRepository, $notifier);
        $command = new ScanCommand($plugin);

        $command([], []);
    }

    public function testCommandSkipsNotificationsWhenDisabled(): void
    {
        when('wp_json_encode')->alias(static fn ($data) => json_encode($data, JSON_THROW_ON_ERROR));

        $risk = new Risk('plugin-slug', 'Plugin Name', '1.0.0', null, ['Example reason']);
        $risks = [$risk];

        $scanner = $this->createMock(Scanner::class);
        $scanner->expects($this->once())
            ->method('scan')
            ->willReturn($risks);

        $riskRepository = $this->createMock(RiskRepository::class);
        $riskRepository->expects($this->once())
            ->method('save')
            ->with($risks);

        $settingsRepository = $this->createMock(SettingsRepository::class);
        $settingsRepository->expects($this->once())
            ->method('get')
            ->willReturn([
                'last_notification' => '',
                'notifications'     => [
                    'frequency'           => 'daily',
                    'testing_expires_at'  => 0,
                ],
                'history'           => [
                    'retention' => RiskRepository::DEFAULT_HISTORY_RETENTION,
                ],
            ]);
        $settingsRepository->expects($this->never())
            ->method('saveNotificationHash');

        $notifier = $this->createMock(Notifier::class);
        $notifier->expects($this->never())
            ->method('notify');

        $plugin = new Plugin($scanner, $riskRepository, $settingsRepository, $notifier);
        $command = new ScanCommand($plugin);

        $command([], ['notify' => 'false']);
    }

    public function testCommandReportsNotificationStatus(): void
    {
        $plugin = $this->getMockBuilder(Plugin::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['runScan'])
            ->getMock();

        $plugin->expects($this->once())
            ->method('runScan')
            ->with(true)
            ->willReturn(true);

        $command = new ScanCommand($plugin);

        $command([], []);

        $this->assertSame(['Scan completed. Notified: yes.'], WP_CLI::$successes);
        $this->assertSame([], WP_CLI::$errors);
    }

    public function testCommandReportsErrorsFromScan(): void
    {
        $plugin = $this->getMockBuilder(Plugin::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['runScan'])
            ->getMock();

        $plugin->expects($this->once())
            ->method('runScan')
            ->with(false)
            ->willThrowException(new RuntimeException('Scan failed'));

        $command = new ScanCommand($plugin);

        $command([], ['notify' => 'false']);

        $this->assertSame([], WP_CLI::$successes);
        $this->assertSame(['Scan failed'], WP_CLI::$errors);
    }
}
