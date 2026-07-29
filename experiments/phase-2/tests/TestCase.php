<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $GLOBALS['drove_phase_two_custom'][] = 'before_class';
    }

    public static function tearDownAfterClass(): void
    {
        $GLOBALS['drove_phase_two_custom'][] = 'after_class';

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['drove_phase_two_custom'][] = 'set_up:'.$this->dataName();
    }

    protected function tearDown(): void
    {
        $GLOBALS['drove_phase_two_custom'][] = 'tear_down:'.$this->dataName();

        parent::tearDown();
    }

    public function bindingMarker(): string
    {
        return 'custom-test-case';
    }
}
