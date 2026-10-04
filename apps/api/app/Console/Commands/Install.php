<?php

namespace App\Console\Commands;

use App\Models\Organisation;
use App\Support\Tenancy\TenantScopeBypass;
use Illuminate\Console\Command;

/**
 * First-run setup for a fresh environment (used by docker compose): applies
 * migrations every time, and seeds the platform and demo tenant only once.
 */
class Install extends Command
{
    protected $signature = 'aixbi:install';

    protected $description = 'Migrate the database and seed it on first run';

    public function handle(): int
    {
        $this->call('migrate', ['--force' => true]);

        if (TenantScopeBypass::run(fn () => Organisation::query()->exists())) {
            $this->info('Already seeded: nothing else to do.');

            return self::SUCCESS;
        }

        $this->info('Seeding the platform and demo tenant (about a minute)…');
        $this->call('db:seed', ['--force' => true]);
        $this->info('Ready. Sign in at http://localhost:8080 with any demo persona (password Demo@2026!).');

        return self::SUCCESS;
    }
}
