<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Brain\Monkey;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/.config/');
}

abstract class TestCase extends PHPUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }
}
