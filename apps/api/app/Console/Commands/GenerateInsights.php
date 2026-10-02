<?php

namespace App\Console\Commands;

use App\Domain\Analytics\InsightEngine;
use Illuminate\Console\Command;

class GenerateInsights extends Command
{
    use TenantRunner;

    protected $signature = 'aixbi:insights:generate';

    protected $description = 'Refresh evidence-backed insights for every organisation';

    public function handle(InsightEngine $engine): int
    {
        $this->forEachOrganisation(fn ($org, $admin) => $this->info($org->name.': '.count($engine->generate($admin)).' insights'));

        return self::SUCCESS;
    }
}
