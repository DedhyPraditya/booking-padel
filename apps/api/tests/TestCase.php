<?php

namespace Tests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Anchor baseline clock so schedule/booking assertions remain deterministic across calendar dates
        $this->travelTo(CarbonImmutable::parse('2026-11-23T10:00:00+08:00'));
    }
}
