<?php

namespace src\tests;

use src\One;

/**
 * Class OneTest
 * @package CoverageAutomation\tests
 *
 * @group foo
 */
class OneTest extends \PHPUnit_Framework_TestCase
{
    public function test1()
    {
        $one = new One();
        $this->assertSame(1, $one->coveredBy1());
    }

    public function test2()
    {
        $one = new One();
        $this->assertSame(2, $one->coveredBy2());
    }
}
 