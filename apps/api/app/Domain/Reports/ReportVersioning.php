<?php

namespace App\Domain\Reports;

use App\Models\Report;
use App\Models\ReportVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Immutable snapshots: publish, restore/rollback and compare. */
class ReportVersioning
{
    public function snapshot(Report $report, User $user, ?string $note = null): ReportVersion
    {
        return DB::transaction(function () use ($report, $user, $note) {
            $report->refresh()->load('sections');
            $version = $report->current_version + 1;
            $report->update(['current_version' => $version]);

            return $report->versions()->create([
                'version' => $version, 'status' => $report->status, 'note' => $note, 'created_by' => $user->id,
                'snapshot' => [
                    'title' => $report->title, 'subtitle' => $report->subtitle, 'theme' => $report->theme, 'parameters' => $report->parameters,
                    'sections' => $report->sections->map(fn ($s) => $s->only(['position', 'type', 'title', 'content']))->all(),
                ],
            ]);
        });
    }

    public function restore(Report $report, int $version, User $user): Report
    {
        $snap = $report->versions()->where('version', $version)->firstOrFail()->snapshot;
        DB::transaction(function () use ($report, $snap) {
            $report->update(['title' => $snap['title'], 'subtitle' => $snap['subtitle'], 'theme' => $snap['theme'], 'parameters' => $snap['parameters'], 'status' => 'draft']);
            $report->sections()->delete();
            foreach ($snap['sections'] as $s) {
                $report->sections()->create($s);
            }
        });
        $this->snapshot($report, $user, "Restored from v{$version}");

        return $report->fresh('sections');
    }

    /** Section-level diff with KPI value changes called out. */
    public function compare(Report $report, int $a, int $b): array
    {
        $va = $report->versions()->where('version', $a)->firstOrFail()->snapshot;
        $vb = $report->versions()->where('version', $b)->firstOrFail()->snapshot;
        $key = fn ($s) => $s['type'].'|'.$s['title'];
        $sa = collect($va['sections'])->keyBy($key);
        $sb = collect($vb['sections'])->keyBy($key);

        $changes = [];
        foreach ($sa->keys()->merge($sb->keys())->unique() as $k) {
            $x = $sa[$k] ?? null;
            $y = $sb[$k] ?? null;
            if (! $x || ! $y) {
                $changes[] = ['section' => ($x ?? $y)['title'], 'change' => $x ? 'removed' : 'added'];

                continue;
            }
            unset($x['content']['blueprint'], $y['content']['blueprint']);
            if ($x['content'] == $y['content']) {
                continue;
            }
            $entry = ['section' => $x['title'], 'change' => 'modified'];
            if ($x['type'] === 'kpis') {
                $before = collect($x['content']['cards'] ?? [])->keyBy('ref');
                $entry['kpis'] = collect($y['content']['cards'] ?? [])->map(fn ($c) => ['label' => $c['label'], 'format' => $c['format'], 'from' => $before[$c['ref']]['value'] ?? null, 'to' => $c['value']])
                    ->filter(fn ($c) => $c['from'] !== $c['to'])->values()->all();
            }
            if ($x['type'] === 'summary') {
                $entry['text'] = ['from' => $x['content']['paragraphs'] ?? [], 'to' => $y['content']['paragraphs'] ?? []];
            }
            $changes[] = $entry;
        }

        return ['from' => $a, 'to' => $b, 'title_changed' => $va['title'] !== $vb['title'], 'changes' => $changes];
    }
}
