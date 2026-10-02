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

    /**
     * Section-level diff with KPI value changes called out.
     *
     * @return array{from: int, to: int, title_changed: bool, changes: list<array<string, mixed>>}
     */
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
                $entry['kpis'] = $this->kpiChanges((array) ($x['content']['cards'] ?? []), (array) ($y['content']['cards'] ?? []));
            }
            if ($x['type'] === 'summary') {
                $entry['text'] = ['from' => $x['content']['paragraphs'] ?? [], 'to' => $y['content']['paragraphs'] ?? []];
            }
            $changes[] = $entry;
        }

        return ['from' => $a, 'to' => $b, 'title_changed' => $va['title'] !== $vb['title'], 'changes' => $changes];
    }

    /**
     * KPI cards whose value moved between two versions of a KPI section.
     *
     * @param  array<mixed>  $before  cards: {ref, label, format, value}
     * @param  array<mixed>  $after
     * @return list<array{label: string, format: string, from: float|null, to: float|null}>
     */
    private function kpiChanges(array $before, array $after): array
    {
        $previous = [];
        foreach ($before as $card) {
            $previous[$card['ref']] = $card['value'] ?? null;
        }
        $changes = [];
        foreach ($after as $card) {
            $from = $previous[$card['ref']] ?? null;
            if ($from !== $card['value']) {
                $changes[] = ['label' => $card['label'], 'format' => $card['format'], 'from' => $from, 'to' => $card['value']];
            }
        }

        return $changes;
    }
}
