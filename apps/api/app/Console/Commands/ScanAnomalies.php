<?php

namespace App\Console\Commands;

use App\Domain\Analytics\AnomalyService;
use Illuminate\Console\Command;

class ScanAnomalies extends Command
{
    use TenantRunner;

    protected $signature = 'aixbi:anomalies:scan';

    protected $description = 'Detect anomalies on KPI daily series for every organisation';

    public function handle(AnomalyService $service): int
    {
        $this->forEachOrganisation(function ($org, $admin) use ($service) {
            $this->info($org->name.': '.count($service->scan($admin)).' new anomalies');
        });

        return self::SUCCESS;
    }
}
