<?php

namespace Database\Seeders;

use App\Support\Tenancy\TenantScopeBypass;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        TenantScopeBypass::run(function () {
            $this->call(PlatformSeeder::class);
            if (! app()->environment('production') || config('aixbi.demo.seed')) {
                $this->call(DemoTenantSeeder::class);
            }
        });
    }
}
