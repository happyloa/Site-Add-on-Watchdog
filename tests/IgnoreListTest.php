<?php

use Brain\Monkey\Functions;
use Watchdog\Repository\RiskRepository;

class IgnoreListTest extends TestCase
{
    public function testAddAndRemoveIgnore(): void
    {
        $options = [];

        Functions\when('get_option')->alias(static function (string $key, $default = []) use (&$options) {
            return $options[$key] ?? $default;
        });

        Functions\when('sanitize_text_field')->alias(static fn ($value) => $value);

        Functions\expect('update_option')->zeroOrMoreTimes()->andReturnUsing(static function (string $key, $value) use (&$options) {
            $options[$key] = $value;
            return true;
        });

        $repository = new RiskRepository();
        $repository->addIgnore('plugin-one');
        $repository->addIgnore('plugin-two');
        $repository->removeIgnore('plugin-one');

        $this->assertSame(['plugin-two'], $repository->ignored());
    }
}
