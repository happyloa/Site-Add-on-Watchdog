<?php

use function Brain\Monkey\Functions\when;
use Watchdog\Cli\ScanCommand;
use Watchdog\Models\Risk;
use Watchdog\Notifier;
use Watchdog\Plugin;
use Watchdog\Repository\RiskRepository;
use Watchdog\Repository\SettingsRepository;
use Watchdog\Scanner;

class ScanCommandTest extends TestCase
{
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
}
