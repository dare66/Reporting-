<?php

namespace App\Domain\Metrics;

use App\Domain\Audit\AuditLogger;
use App\Domain\Query\Expression\ExpressionParser;
use App\Domain\Query\QueryValidationException;
use App\Models\Measure;
use App\Models\Metric;
use App\Models\MetricVersion;
use App\Models\SemanticModel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The governed lifecycle of metrics.
 *
 *   proposed ──approve──▶ approved ──certify──▶ certified
 *      ▲                     ▲                    │ revoke
 *      └──── definition change (lapses approval) ─┘
 *   any ──deprecate──▶ deprecated ──reinstate──▶ proposed
 *
 * Every change is a new version; a change to how a metric is computed sends
 * it back to "proposed", so a certified number can never silently change.
 * Certification needs a second person: whoever approved the current
 * definition cannot also certify it.
 *
 * @phpstan-import-type MeasureDef from MetricDefinition
 */
class MetricStore
{
    public const ACTIONS = ['approve', 'certify', 'revoke', 'deprecate', 'reinstate'];

    public function __construct(private readonly MetricDefinition $definitions, private readonly AuditLogger $audit) {}

    /** @return array<string, MeasureDef> */
    public function measuresOf(SemanticModel $model): array
    {
        return $model->measures()->get()->mapWithKeys(fn (Measure $m) => [$m->key => [
            'key' => $m->key, 'aggregation' => $m->aggregation, 'field' => $m->field, 'filters' => array_values($m->filters ?? []),
        ]])->all();
    }

    /** @return array<string, mixed> */
    public function snapshot(Metric $metric): array
    {
        $model = $metric->semanticModel()->with('baseDataset')->firstOrFail();

        return $this->definitions->snapshot(['synonyms' => $metric->synonyms] + $metric->getAttributes(), $this->measuresOf($model), (string) $model->baseDataset?->name);
    }

    public function hashOf(Metric $metric): string
    {
        return $this->definitions->hash($this->snapshot($metric));
    }

    /**
     * Records the metric's current state as a new version and applies the
     * lifecycle rule for definition changes. Call after the row is saved.
     */
    public function recordVersion(Metric $metric, ?User $by, string $summary, bool $isNew = false): MetricVersion
    {
        $snapshot = $this->snapshot($metric);
        $hash = $this->definitions->hash($snapshot);
        $changed = ! $isNew && $metric->definition_hash !== null && $metric->definition_hash !== $hash;
        $updates = ['definition_hash' => $hash];
        if ($changed && in_array($metric->status, ['approved', 'certified'], true)) {
            $updates += ['status' => 'proposed', 'approved_by' => null, 'approved_at' => null, 'certified_by' => null, 'certified_at' => null,
                'status_note' => "How it is computed changed in version {$metric->version}; it needs approval again."];
            $summary .= ' Approval lapsed because the calculation changed.';
        }
        $metric->forceFill($updates)->save();

        return MetricVersion::create([
            'organisation_id' => $metric->semanticModel->organisation_id, 'semantic_model_id' => $metric->semantic_model_id, 'metric_key' => $metric->key,
            'version' => $metric->version, 'definition' => $snapshot, 'definition_hash' => $hash, 'summary' => $summary, 'changed_by' => $by?->id,
        ]);
    }

    /**
     * Edits a metric. Any change creates a version; a change to the expression is re-validated.
     *
     * @param  array<string, mixed>  $changes
     */
    public function update(Metric $metric, array $changes, User $by): Metric
    {
        if (array_key_exists('expression', $changes)) {
            $keys = $metric->semanticModel->measures()->pluck('key')->all();
            try {
                (new ExpressionParser($keys))->parse((string) $changes['expression']);
            } catch (QueryValidationException|InvalidArgumentException $e) {
                throw new InvalidArgumentException('The expression is not valid: '.$e->getMessage());
            }
        }
        foreach (['business_owner_id', 'data_owner_id'] as $owner) {
            if (! empty($changes[$owner]) && ! User::whereKey($changes[$owner])->exists()) {
                throw new InvalidArgumentException('Owners must be people in this organisation.');
            }
        }
        $metric->fill($changes);
        $dirty = array_keys($metric->getDirty());
        if ($dirty === []) {
            return $metric;
        }

        return DB::transaction(function () use ($metric, $dirty, $by) {
            $metric->version++;
            $metric->save();
            $this->recordVersion($metric, $by, 'Changed '.implode(', ', array_map(fn ($f) => str_replace(['_id', '_'], ['', ' '], $f), $dirty)).'.');
            $this->audit->record('metric.updated', ['resource_type' => 'metric', 'resource_id' => $this->ref($metric)], ['fields' => $dirty, 'version' => $metric->version]);
            $metric->semanticModel->increment('version');

            return $metric->fresh();
        });
    }

    public function transition(Metric $metric, string $action, User $by, ?string $note = null, ?string $replacedBy = null): Metric
    {
        $status = $metric->status;
        $need = fn (string $permission) => $by->hasPermission($permission) ?: throw new AccessDeniedHttpException("This needs the {$permission} permission.");
        $from = function (array $allowed) use ($status, $action) {
            if (! in_array($status, $allowed, true)) {
                throw new InvalidArgumentException("A {$status} metric cannot be {$this->past($action)}.");
            }
        };

        $updates = match ($action) {
            'approve' => (function () use ($need, $from, $by) {
                $need('semantic.manage');
                $from(['proposed']);

                return ['status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now(), 'status_note' => null];
            })(),
            'certify' => (function () use ($need, $from, $by, $metric) {
                $need('metrics.certify');
                $from(['approved']);
                if ($metric->approved_by === $by->id) {
                    throw new InvalidArgumentException('Certification needs a second person: you approved this definition, so someone else must certify it.');
                }

                return ['status' => 'certified', 'certified_by' => $by->id, 'certified_at' => now(), 'status_note' => null];
            })(),
            'revoke' => (function () use ($need, $from, $note) {
                $need('metrics.certify');
                $from(['certified']);

                return ['status' => 'approved', 'certified_by' => null, 'certified_at' => null, 'status_note' => $this->required($note, 'Say why certification is revoked.')];
            })(),
            'deprecate' => (function () use ($by, $from, $note, $replacedBy, $metric) {
                if (! $by->hasPermission('semantic.manage') && ! $by->hasPermission('metrics.certify')) {
                    throw new AccessDeniedHttpException('Deprecating a metric needs semantic.manage or metrics.certify.');
                }
                $from(['proposed', 'approved', 'certified']);
                if ($replacedBy !== null && ! $metric->semanticModel->metrics()->where('key', $replacedBy)->where('key', '!=', $metric->key)->exists()) {
                    throw new InvalidArgumentException("The replacement metric {$replacedBy} does not exist in this model.");
                }

                return ['status' => 'deprecated', 'status_note' => $this->required($note, 'Say why the metric is deprecated.'), 'replaced_by' => $replacedBy,
                    'certified_by' => null, 'certified_at' => null];
            })(),
            'reinstate' => (function () use ($need, $from) {
                $need('semantic.manage');
                $from(['deprecated']);

                return ['status' => 'proposed', 'status_note' => null, 'replaced_by' => null, 'approved_by' => null, 'approved_at' => null];
            })(),
            default => throw new InvalidArgumentException("Unknown action {$action}."),
        };

        $metric->forceFill($updates)->save();
        $this->audit->record("metric.{$this->past($action)}", ['resource_type' => 'metric', 'resource_id' => $this->ref($metric)],
            array_filter(['from' => $status, 'to' => $metric->status, 'note' => $note, 'replaced_by' => $replacedBy]));

        return $metric->fresh();
    }

    /**
     * Puts back an earlier version's definition as a new version. Measures the
     * old definition used are restored when missing; a measure that now means
     * something else is never overwritten.
     */
    public function restore(Metric $metric, int $version, User $by): Metric
    {
        $old = MetricVersion::where('semantic_model_id', $metric->semantic_model_id)->where('metric_key', $metric->key)->where('version', $version)->firstOrFail();
        $def = $old->definition;
        $current = $this->measuresOf($metric->semanticModel);
        foreach ($def['measures'] as $m) {
            if (isset($current[$m['key']]) && $current[$m['key']] != $m) {
                throw new InvalidArgumentException("Version {$version} uses measure {$m['key']}, which has since been redefined. Restore it in the semantic model first.");
            }
        }

        return DB::transaction(function () use ($metric, $def, $current, $version, $by) {
            foreach ($def['measures'] as $m) {
                if (! isset($current[$m['key']])) {
                    $metric->semanticModel->measures()->create(['key' => $m['key'], 'label' => $m['key'], 'aggregation' => $m['aggregation'], 'field' => $m['field'], 'filters' => $m['filters']]);
                }
            }
            $metric->fill(['expression' => $def['expression']] + array_intersect_key($def, array_flip(MetricDefinition::DESCRIPTIVE)));
            $metric->version++;
            $metric->save();
            $this->recordVersion($metric, $by, "Restored version {$version}.");
            $this->audit->record('metric.restored', ['resource_type' => 'metric', 'resource_id' => $this->ref($metric)], ['version' => $version]);
            $metric->semanticModel->increment('version');

            return $metric->fresh();
        });
    }

    public function ref(Metric $metric): string
    {
        return $metric->semanticModel->key.'.'.$metric->key;
    }

    private function required(?string $note, string $message): string
    {
        $note = trim((string) $note);

        return $note !== '' ? $note : throw new InvalidArgumentException($message);
    }

    private function past(string $action): string
    {
        return ['approve' => 'approved', 'certify' => 'certified', 'revoke' => 'revoked', 'deprecate' => 'deprecated', 'reinstate' => 'reinstated'][$action] ?? $action;
    }
}
