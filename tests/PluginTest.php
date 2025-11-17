<?php

use function Brain\Monkey\Functions\expect;
use function Brain\Monkey\Functions\when;
use Watchdog\Notifier;
use Watchdog\Plugin;
use Watchdog\Repository\RiskRepository;
use Watchdog\Repository\SettingsRepository;
use Watchdog\Scanner;

class PluginTest extends TestCase
{
    public function testScheduleTriggersOverdueCatchUpForTesting(): void
    {
        when('site_url')->justReturn('https://example.test');
        when('wp_remote_post')->justReturn(null);
        when('get_option')->justReturn([
            'overdue_streak' => 0,
            'cron_disabled'  => false,
        ]);
        when('is_admin')->justReturn(false);
        when('current_user_can')->justReturn(false);
        when('update_option')->justReturn(true);

        expect('wp_next_scheduled')
            ->once()
            ->with('wp_watchdog_scheduled_scan')
            ->andReturn(time() - 2_000);
        expect('wp_get_schedule')->once()->andReturn('testing');
        expect('wp_get_schedules')->once()->andReturn([
            'testing' => ['interval' => 600],
        ]);
        expect('spawn_cron')->once();
        expect('wp_schedule_single_event')->once();

        $scanner = $this->createMock(Scanner::class);
        $riskRepository = $this->createMock(RiskRepository::class);
        $settingsRepository = $this->createMock(SettingsRepository::class);
        $settingsRepository->method('get')->willReturn([
            'notifications' => ['frequency' => 'testing'],
        ]);
        $notifier = $this->createMock(Notifier::class);

        $plugin = new Plugin($scanner, $riskRepository, $settingsRepository, $notifier);
        $plugin->schedule();
    }

    public function testScheduleWarnsWhenCronIsDisabled(): void
    {
        if (! defined('DISABLE_WP_CRON')) {
            define('DISABLE_WP_CRON', true);
        }

        when('site_url')->justReturn('https://example.test');
        when('wp_remote_post')->justReturn(null);
        when('get_option')->justReturn([
            'overdue_streak' => 1,
            'cron_disabled'  => true,
        ]);
        when('is_admin')->justReturn(false);
        when('current_user_can')->justReturn(false);
        when('update_option')->justReturn(true);

        expect('wp_next_scheduled')
            ->once()
            ->with('wp_watchdog_scheduled_scan')
            ->andReturn(time() - 3_000);
        expect('wp_get_schedule')->once()->andReturn('testing');
        expect('wp_get_schedules')->once()->andReturn([
            'testing' => ['interval' => 600],
        ]);
        expect('spawn_cron')->never();
        expect('wp_schedule_single_event')->once();

        $scanner = $this->createMock(Scanner::class);
        $riskRepository = $this->createMock(RiskRepository::class);
        $settingsRepository = $this->createMock(SettingsRepository::class);
        $settingsRepository->method('get')->willReturn([
            'notifications' => ['frequency' => 'testing'],
        ]);
        $notifier = $this->createMock(Notifier::class);

        $plugin = new Plugin($scanner, $riskRepository, $settingsRepository, $notifier);
        $plugin->schedule();
    }

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
