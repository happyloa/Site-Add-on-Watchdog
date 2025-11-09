<?php

use Watchdog\Services\VersionComparator;

class VersionComparatorTest extends TestCase
{
    private VersionComparator $comparator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->comparator = new VersionComparator();
    }

    public function testMajorDifferenceIsRisk(): void
    {
        $this->assertTrue($this->comparator->isTwoMinorVersionsBehind('1.0.0', '2.0.0'));
    }

    public function testMinorDifferenceLessThanTwoIsNotRisk(): void
    {
        $this->assertFalse($this->comparator->isTwoMinorVersionsBehind('1.5.0', '1.6.0'));
    }

    public function testMinorDifferenceOfTwoIsRisk(): void
    {
        $this->assertTrue($this->comparator->isTwoMinorVersionsBehind('1.2.0', '1.4.0'));
    }
}
