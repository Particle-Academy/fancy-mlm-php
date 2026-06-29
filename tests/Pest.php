<?php

declare(strict_types=1);

/*
 * Core engine tests are pure unit tests — no framework binding. Laravel-bridge
 * tests under tests/Laravel bind Orchestra Testbench + a fresh in-memory DB.
 */
uses(
    FancyMlm\Tests\Laravel\TestCase::class,
    Illuminate\Foundation\Testing\RefreshDatabase::class,
)->in('Laravel');
