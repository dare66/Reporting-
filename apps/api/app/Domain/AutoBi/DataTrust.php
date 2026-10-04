<?php

namespace App\Domain\AutoBi;

use App\Models\Dataset;
use App\Models\DatasetSnapshot;
use App\Models\DriftEvent;

/**
 * A 0–100 trust score for a profiled dataset, built from parts a person can
 * check: completeness, uniqueness, validity, freshness and schema stability. A part that cannot
 * be measured yet is reported as such and left out of the score, never guessed.
 *
 * @phpstan-type TrustPart array{key: string, label: string, score: float|null, detail: string}
 */
class DataTrust
{
    private const WEIGHTS = ['completeness' => 0.25, 'uniqueness' => 0.25, 'validity' => 0.15, 'freshness' => 0.2, 'stability' => 0.15];

    /** @return array{score: float, grade: string, parts: list<TrustPart>, issues: list<array<string, mixed>>} */
    public function assess(Dataset $dataset): array
    {
        $dataset->loadMissing('fields');
        $rows = (int) $dataset->row_count;
        $fields = $dataset->fields;
        $profile = $dataset->profile ?? [];

        $nullPct = $fields->isEmpty() ? 0 : $fields->avg(fn ($f) => (float) ($f->profile['null_pct'] ?? 0));
        $duplicates = (int) ($profile['duplicate_rows'] ?? 0);
        $issues = is_array($profile['issues'] ?? null) ? $profile['issues'] : [];
        $keyIssues = count(array_filter($issues, fn ($i) => ($i['severity'] ?? null) === 'critical'));
        $numeric = $fields->filter(fn ($f) => in_array($f->data_type, ['integer', 'decimal'], true));
        $outliers = $numeric->sum(fn ($f) => (int) ($f->profile['outliers_4sd'] ?? 0));

        $parts = [
            ['key' => 'completeness', 'label' => 'Completeness', 'score' => round(100 - $nullPct, 1),
                'detail' => sprintf('%.1f%% of cells are filled in.', 100 - $nullPct)],
            ['key' => 'uniqueness', 'label' => 'Uniqueness', 'score' => $rows ? round(max(0, 100 * (1 - $duplicates / $rows) - 15 * $keyIssues), 1) : null,
                'detail' => $duplicates || $keyIssues
                    ? trim(($duplicates ? "{$duplicates} duplicate rows. " : '').($keyIssues ? "{$keyIssues} identifier column(s) repeat." : ''))
                    : 'No duplicate rows or repeated identifiers.'],
            ['key' => 'validity', 'label' => 'Validity', 'score' => $rows && $numeric->isNotEmpty() ? round(100 - min(100, 100 * $outliers / ($rows * $numeric->count()) * 20), 1) : null,
                'detail' => $numeric->isEmpty() ? 'No numeric columns to check.' : ($outliers ? "{$outliers} values are more than 4 standard deviations from the mean." : 'No extreme values.')],
            $this->freshness($dataset),
            $this->stability($dataset),
        ];

        $scored = array_filter($parts, fn ($p) => $p['score'] !== null);
        // Completeness is always measurable, so the weight is never zero.
        $weight = array_sum(array_map(fn ($p) => self::WEIGHTS[$p['key']], $scored));
        $score = round(array_sum(array_map(fn ($p) => $p['score'] * self::WEIGHTS[$p['key']], $scored)) / $weight, 1);

        return [
            'score' => $score,
            'grade' => match (true) {
                $score >= 90 => 'high', $score >= 75 => 'moderate', default => 'low',
            },
            'parts' => $parts,
            'issues' => $issues,
        ];
    }

    /**
     * Schema stability: how much the latest load changed the shape of the data.
     * Breaking changes to columns in use cost most.
     *
     * @return TrustPart
     */
    private function stability(Dataset $dataset): array
    {
        $snapshots = DatasetSnapshot::where('dataset_id', $dataset->id)->orderByDesc('taken_at')->orderByDesc('id')->limit(2)->get();
        if ($snapshots->count() < 2) {
            return ['key' => 'stability', 'label' => 'Schema stability', 'score' => null, 'detail' => 'Measured from the second load onwards.'];
        }
        $events = DriftEvent::where('snapshot_id', $snapshots->first()->id)->get();
        $critical = $events->where('severity', 'critical')->count();
        $warning = $events->where('severity', 'warning')->count();

        return ['key' => 'stability', 'label' => 'Schema stability', 'score' => (float) max(0, 100 - 30 * $critical - 10 * $warning),
            'detail' => $events->isEmpty() ? 'No changes since the previous load.'
                : trim(($critical ? "{$critical} breaking change(s). " : '').($warning ? "{$warning} warning(s). " : '').($events->count() - $critical - $warning ? ($events->count() - $critical - $warning).' minor change(s).' : ''))];
    }

    /** @return TrustPart */
    private function freshness(Dataset $dataset): array
    {
        if (! $dataset->freshness_at) {
            return ['key' => 'freshness', 'label' => 'Freshness', 'score' => null, 'detail' => 'No date column, so freshness cannot be measured.'];
        }
        $loaded = $dataset->dataSource->last_sync_at ?? $dataset->updated_at ?? now();
        $age = max(0, (int) $dataset->freshness_at->diffInDays($loaded));
        $score = match (true) {
            $age <= 7 => 100.0, $age <= 90 => round(100 - ($age - 7) / 83 * 50, 1), $age <= 365 => round(50 - ($age - 90) / 275 * 30, 1), default => 20.0,
        };

        return ['key' => 'freshness', 'label' => 'Freshness', 'score' => $score,
            'detail' => $age === 0 ? 'The latest record is from the day the data was loaded.' : "The latest record is {$age} days older than the load."];
    }
}
