<?php

namespace App\Jobs;

use App\Domain\Data\SourceLoader;
use App\Models\DataSource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Loads a database source's chosen tables in the background (see SourceLoader). */
class LoadSourceTablesJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public readonly string $sourceId) {}

    public function handle(SourceLoader $loader, TenantContext $tenant): void
    {
        $source = DataSource::withoutGlobalScopes()->findOrFail($this->sourceId);
        $tenant->setOrganisation($source->organisation_id);
        $loader->run($source);
    }
}
