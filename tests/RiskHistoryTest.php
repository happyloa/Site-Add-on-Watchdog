<?php

use Brain\Monkey\Functions;
use Watchdog\Models\Risk;
use Watchdog\Repository\RiskRepository;

class RiskHistoryTest extends TestCase
{
    public function testSavePersistsHistoryAndTrims(): void
    {
        $options = [];

        Functions\when('get_option')->alias(static function (string $key, $default = []) use (&$options) {
            return $options[$key] ?? $default;
        });

        Functions\when('update_option')->alias(static function (string $key, $value) use (&$options) {
            $options[$key] = $value;

            return true;
        });

        $repository = new RiskRepository();
        $risk       = new Risk('plugin-one', 'Plugin One', '1.0.0', '1.2.0', ['Reason'], []);

        $repository->save([$risk], 100, 2);
        $repository->save([$risk], 200, 2);
        $repository->save([$risk], 300, 2);

        $history = $repository->history(5);

        self::assertCount(2, $history);
        self::assertSame(300, $history[0]['run_at']);
        self::assertSame(1, $history[0]['risk_count']);
        self::assertSame(200, $history[1]['run_at']);
    }

    public function testHistoryTemplateOutputsDownloadLinks(): void
    {
        Functions\when('esc_html__')->alias(static fn ($text, ...$args) => $text);
        Functions\when('esc_html_e')->alias(static function ($text, ...$args): void {
            echo esc_html($text);
        });
        Functions\when('esc_html')->alias(static fn ($value) => (string) $value);
        Functions\when('esc_url')->alias(static fn ($value) => (string) $value);
        Functions\when('number_format_i18n')->alias(static fn ($value) => (string) $value);
        Functions\when('wp_date')->alias(static fn ($format, $timestamp) => gmdate($format, (int) $timestamp));
        Functions\when('get_option')->alias(static fn ($option, $default = null) => $default ?? '');

        $historyRecords = [
            [
                'run_at'    => 200,
                'risks'     => [
                    [
                        'plugin_slug'   => 'plugin-one',
                        'plugin_name'   => 'Plugin One',
                        'local_version' => '1.0.0',
                        'remote_version' => '1.2.0',
                        'reasons'       => ['Reason'],
                        'details'       => [],
                    ],
                ],
                'risk_count' => 1,
            ],
        ];
        $historyDownloads = [
            200 => [
                'json' => 'json-url',
                'csv'  => 'csv-url',
            ],
        ];
        $historyRetention = 5;
        $historyDisplay   = 1;

        ob_start();
        require __DIR__ . '/../templates/history.php';
        $output = ob_get_clean();

        self::assertStringContainsString('json-url', $output);
        self::assertStringContainsString('csv-url', $output);
        self::assertStringContainsString(gmdate('Y-m-d H:i', 200), $output);
    }
}
