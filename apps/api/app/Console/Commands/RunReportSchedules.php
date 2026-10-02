<?php

namespace App\Console\Commands;

use App\Domain\Notifications\Notifier;
use App\Domain\Reports\ReportBuilder;
use App\Domain\Reports\ReportExportService;
use App\Domain\Reports\ReportVersioning;
use App\Domain\Reports\ScheduleCalculator;
use App\Models\ReportExport;
use App\Models\ScheduledReport;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantScopeBypass;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class RunReportSchedules extends Command
{
    protected $signature = 'aixbi:reports:run-schedules';

    protected $description = 'Refresh, export and distribute scheduled reports that are due';

    public function handle(ReportBuilder $builder, ReportExportService $exports, ReportVersioning $versions, Notifier $notifier, TenantContext $tenant): int
    {
        $due = TenantScopeBypass::run(fn () => ScheduledReport::with('report.sections')->where('is_active', true)->where('next_run_at', '<=', now())->get());
        foreach ($due as $schedule) {
            $owner = TenantScopeBypass::run(fn () => User::with('roles')->find($schedule->created_by));
            if (! $owner || ! $schedule->report) {
                continue;
            }
            $tenant->set($owner);
            $report = $schedule->report;
            $builder->build($report, $report->sections->map(fn ($s) => $s->content['blueprint'] ?? ['type' => $s->type, 'title' => $s->title])->all(), $owner);
            $versions->snapshot($report, $owner, 'Scheduled refresh');

            $files = [];
            foreach ($schedule->formats as $format) {
                $export = $exports->run(ReportExport::create(['report_id' => $report->id, 'format' => $format, 'requested_by' => $owner->id]));
                if ($export->status === 'ready') {
                    $files[] = $export;
                }
            }
            $recipients = $schedule->recipients ?: [$owner->id];
            $link = '/reports/'.$report->id;
            $notifier->toUsers($recipients, $report->organisation_id, ['type' => 'report', 'title' => "{$report->title} is ready",
                'body' => 'Your '.$schedule->frequency.' report has been refreshed with the latest data.', 'link' => $link,
                'channels' => array_values(array_intersect($schedule->channels, ['in_app', 'push']))]);
            if (in_array('email', $schedule->channels, true)) {
                foreach (TenantScopeBypass::run(fn () => User::whereIn('id', $recipients)->get()) as $u) {
                    Mail::raw("{$report->title} has been refreshed.\n\nSecure link: ".url($link), function ($m) use ($u, $report, $files) {
                        $m->to($u->email)->subject($report->title);
                        foreach ($files as $f) {
                            $m->attach(Storage::disk('local')->path($f->path));
                        }
                    });
                }
            }
            $schedule->update(['last_run_at' => now(), 'next_run_at' => ScheduleCalculator::next($schedule->frequency, $schedule->time_of_day)]);
            $tenant->clear();
            $this->info("Ran {$report->title}");
        }

        return self::SUCCESS;
    }
}
