<?php

namespace App\Jobs;

use App\Domain\Notifications\Notifier;
use App\Domain\Reports\ReportExportService;
use App\Models\ReportExport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExportReportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function __construct(public readonly string $exportId, public readonly bool $notify = false) {}

    public function handle(ReportExportService $service, TenantContext $tenant, Notifier $notifier): void
    {
        $export = ReportExport::withoutGlobalScopes()->findOrFail($this->exportId);
        $tenant->setOrganisation($export->organisation_id);
        $service->run($export);

        if ($this->notify && $export->requested_by) {
            $ok = $export->status === 'ready';
            $notifier->toUsers([$export->requested_by], $export->organisation_id, [
                'type' => 'report', 'severity' => $ok ? 'info' : 'warning',
                'title' => $ok ? 'Your '.strtoupper($export->format).' is ready' : 'Report export failed',
                'body' => $ok ? 'The export finished and is ready to download.' : 'The export could not be generated: '.$export->error,
                'link' => '/reports/'.$export->report_id, 'data' => ['export_id' => $export->id],
            ]);
        }
    }
}
