<?php

namespace Tests;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Feature tests run against PostgreSQL with the (scaled-down) demo tenant.
 * Seeding happens once per run, committed, so the read-only analytical
 * connection can see it; each test then runs inside a rolled-back transaction.
 */
abstract class SeededTestCase extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = DatabaseSeeder::class;
}
