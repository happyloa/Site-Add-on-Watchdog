<?php

use Brain\Monkey\Functions;
use Watchdog\Notifier;
use Watchdog\Plugin;
use Watchdog\Repository\RiskRepository;
use Watchdog\Repository\SettingsRepository;
use Watchdog\Scanner;
use Watchdog\Version;

if (! class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        public function __construct(private array $params = [])
        {
        }

        public function get_param(string $key): mixed
        {
            return $this->params[$key] ?? null;
        }
    }
}

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(private string $code, private string $message = '')
        {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}

class PluginCronIntegrationTest extends TestCase
{
    public function testRegisterAddsCronHooksAndFilters(): void
    {
        $actions = [];
        $filters = [];

        Functions\when('add_action')->alias(static function (
            string $hook,
            $callback,
            int $priority = 10,
            int $acceptedArgs = 1
        ) use (&$actions): void {
            $actions[] = compact('hook', 'callback', 'priority', 'acceptedArgs');
        });

        Functions\when('add_filter')->alias(static function (
            string $hook,
            $callback,
            int $priority = 10,
            int $acceptedArgs = 1
        ) use (&$filters): void {
            $filters[] = compact('hook', 'callback', 'priority', 'acceptedArgs');
        });

        Functions\when('delete_option')->justReturn(true);

        $scanner = $this->createMock(Scanner::class);
        $riskRepository = $this->createMock(RiskRepository::class);
        $settingsRepository = $this->createMock(SettingsRepository::class);
        $notifier = $this->createMock(Notifier::class);

        $plugin = new Plugin($scanner, $riskRepository, $settingsRepository, $notifier);
        $plugin->register();

        $hooks = array_column($actions, 'hook');

        self::assertContains(Version::PREFIX . '_scheduled_scan', $hooks);
        self::assertContains(Version::PREFIX . '_notification_queue', $hooks);
        self::assertContains('plugins_loaded', $hooks);
        self::assertContains('admin_notices', $hooks);
        self::assertContains('rest_api_init', $hooks);

        self::assertSame(['cron_schedules'], array_column($filters, 'hook'));
    }

    public function testRestCronRouteValidationMatchesCronSecret(): void
    {
        $registeredRoutes = [];

        Functions\when('register_rest_route')->alias(static function (
            string $namespace,
            string $route,
            array $args
        ) use (&$registeredRoutes): bool {
            $registeredRoutes[] = compact('namespace', 'route', 'args');

            return true;
        });

        $scanner = $this->createMock(Scanner::class);
        $riskRepository = $this->createMock(RiskRepository::class);
        $settingsRepository = $this->createMock(SettingsRepository::class);
        $settingsRepository->method('get')->willReturn([
            'notifications' => ['cron_secret' => 'secret123'],
        ]);
        $notifier = $this->createMock(Notifier::class);

        $plugin = new Plugin($scanner, $riskRepository, $settingsRepository, $notifier);
        $plugin->registerRestRoutes();

        self::assertCount(1, $registeredRoutes);
        self::assertSame('site-add-on-watchdog/v1', $registeredRoutes[0]['namespace']);
        self::assertSame('/cron', $registeredRoutes[0]['route']);

        $permissionCallback = $registeredRoutes[0]['args']['permission_callback'];

        $validRequest = new WP_REST_Request(['key' => 'secret123']);
        self::assertTrue($plugin->validateCronRequest($validRequest));
        self::assertTrue($permissionCallback($validRequest));

        $invalidRequest = new WP_REST_Request(['key' => 'invalid']);
        self::assertFalse($plugin->validateCronRequest($invalidRequest));

        $error = $permissionCallback($invalidRequest);
        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('watchdog_cron_secret_invalid', $error->get_error_code());
    }

    public function testScheduleStartsQueueProcessorWhenMissing(): void
    {
        Functions\when('get_option')->alias(static function (string $name) {
            return match ($name) {
                'siteadwa_cron_status' => [
                    'overdue_streak' => 0,
                    'cron_disabled'  => false,
                ],
                'timezone_string' => '',
                'gmt_offset'      => 0,
                default           => null,
            };
        });
        Functions\when('update_option')->justReturn(true);
        Functions\when('is_admin')->justReturn(false);
        Functions\when('current_user_can')->justReturn(false);

        Functions\when('wp_get_schedules')->justReturn([
            'daily' => ['interval' => 86_400],
        ]);
        Functions\when('wp_get_schedule')->justReturn('daily');

        Functions\when('wp_next_scheduled')->alias(static function (string $hook) {
            return match ($hook) {
                'siteadwa_scheduled_scan' => false,
                'siteadwa_notification_queue' => false,
                'wp_watchdog_daily_scan' => false,
                'wp_watchdog_notification_queue' => false,
                default => false,
            };
        });

        $scheduledEvents = [];

        Functions\when('wp_schedule_event')->alias(static function (
            int $timestamp,
            string $schedule,
            string $hook
        ) use (&$scheduledEvents): void {
            $scheduledEvents[] = compact('timestamp', 'schedule', 'hook');
        });

        Functions\when('wp_unschedule_event')->justReturn(true);
        Functions\when('_get_cron_array')->justReturn([]);

        $scanner = $this->createMock(Scanner::class);
        $riskRepository = $this->createMock(RiskRepository::class);
        $settingsRepository = $this->createMock(SettingsRepository::class);
        $settingsRepository->method('get')->willReturn([
            'notifications' => ['frequency' => 'daily'],
        ]);
        $notifier = $this->createMock(Notifier::class);

        $plugin = new Plugin($scanner, $riskRepository, $settingsRepository, $notifier);
        $plugin->schedule();

        $queueEvents = array_filter($scheduledEvents, static function (array $event): bool {
            return $event['hook'] === Version::PREFIX . '_notification_queue'
                && $event['schedule'] === Version::PREFIX . '_notification_queue';
        });

        self::assertNotEmpty($queueEvents);
    }
}
