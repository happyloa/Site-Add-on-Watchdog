<?php

use function Brain\Monkey\Functions\when;
use Watchdog\Notifier;
use Watchdog\Plugin;
use Watchdog\Repository\RiskRepository;
use Watchdog\Repository\SettingsRepository;
use Watchdog\Scanner;

class PluginTest extends TestCase
{
    public function testTestingFrequencyAlwaysNotifies(): void
    {
        $scanner = $this->createMock(Scanner::class);
        $scanner->method('scan')->willReturn([]);

        $riskRepository = $this->createMock(RiskRepository::class);
        $riskRepository
            ->expects(self::once())
            ->method('save')
            ->with([], self::isType('int'), RiskRepository::DEFAULT_HISTORY_RETENTION);

        $settings = [
            'notifications' => ['frequency' => 'testing'],
            'history'       => ['retention' => RiskRepository::DEFAULT_HISTORY_RETENTION],
            'last_notification' => 'previous-hash',
        ];

        $settingsRepository = $this->createMock(SettingsRepository::class);
        $settingsRepository->method('get')->willReturn($settings);
        $settingsRepository
            ->expects(self::once())
            ->method('saveNotificationHash')
            ->with(self::isType('string'));

        $notifier = $this->createMock(Notifier::class);
        $notifier->expects(self::once())->method('notify')->with([]);

        when('wp_json_encode')->alias(static fn ($data) => json_encode($data, JSON_THROW_ON_ERROR));

        $plugin = new Plugin($scanner, $riskRepository, $settingsRepository, $notifier);
        $plugin->runScan();
    }
}
