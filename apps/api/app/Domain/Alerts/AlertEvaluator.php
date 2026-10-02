<?php

namespace App\Domain\Alerts;

use App\Domain\Analytics\Format;
use App\Domain\Analytics\KpiService;
use App\Domain\Analytics\RootCauseService;
use App\Domain\Audit\AuditLogger;
use App\Domain\Notifications\Notifier;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantScopeBypass;
use Throwable;

/**
 * Evaluates alert rules as their owner (so row-level security applies) and
 * sends intelligent notifications: the value, the threshold and the leading
 * driver — not just "dashboard updated".
 */
class AlertEvaluator
{
    public function __construct(
        private readonly KpiService $kpis,
        private readonly RootCauseService $rootCause,
        private readonly Notifier $notifier,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{state: string, value: ?float, fired: bool} */
    public function evaluate(AlertRule $rule): array
    {
        $owner = TenantScopeBypass::run(fn () => User::with('roles')->find($rule->owner_id));
        if (! $owner) {
            return ['state' => $rule->last_state, 'value' => null, 'fired' => false];
        }
        $this->tenant->set($owner);
        $ref = $rule->semanticModel->key.'.'.$rule->metric_key;
        $card = $this->kpis->cards([$ref], $rule->window, $owner, $rule->filters ?? [])[0];
        $value = $card['value'];

        $breached = $value !== null && match ($rule->operator) {
            'lt' => $value < $rule->threshold,
            'lte' => $value <= $rule->threshold,
            'gt' => $value > $rule->threshold,
            'gte' => $value >= $rule->threshold,
            'change_pct_gt' => ($card['change_pct'] ?? 0) * 100 > $rule->threshold,
            'change_pct_lt' => ($card['change_pct'] ?? 0) * 100 < $rule->threshold,
            default => false,
        };
        $state = $breached ? 'breached' : 'ok';
        $fired = $breached && $rule->last_state !== 'breached';

        if ($fired) {
            $driver = null;
            try {
                $driver = $this->rootCause->explain($ref, $rule->window, $owner, $rule->filters ?? [], ['institution', 'country', 'document_issue', 'course'])['drivers'][0] ?? null;
            } catch (Throwable) {
            }
            $v = Format::value($value, $card['format']);
            $message = "{$card['label']} ".($card['direction'] === 'down' ? 'dropped to' : 'reached')." {$v}"
                .($driver ? ", mainly due to {$driver['dimension_label']} {$driver['member']}." : '.');
            Alert::create(['organisation_id' => $rule->organisation_id, 'alert_rule_id' => $rule->id, 'value' => $value, 'message' => $message,
                'evidence' => ['card' => array_diff_key($card, ['sparkline' => 1]), 'driver' => $driver], 'fired_at' => now()]);
            $recipients = $rule->recipients ?: [$rule->owner_id];
            $this->notifier->toUsers($recipients, $rule->organisation_id, [
                'type' => 'alert', 'severity' => 'critical', 'title' => '⚠ '.$rule->name,
                'body' => $message.' Tap to investigate.', 'link' => '/investigate?metric='.urlencode($ref),
                'data' => ['alert_rule_id' => $rule->id, 'value' => $value], 'channels' => $rule->channels ?: ['in_app'],
            ]);
        } elseif (! $breached && $rule->last_state === 'breached') {
            $this->notifier->toUsers($rule->recipients ?: [$rule->owner_id], $rule->organisation_id, [
                'type' => 'alert', 'severity' => 'positive', 'title' => '✓ '.$rule->name.' recovered',
                'body' => "{$card['label']} is back at ".Format::value($value, $card['format']).'.', 'link' => '/investigate?metric='.urlencode($ref), 'channels' => ['in_app'],
            ]);
        }

        $rule->update(['last_state' => $state, 'last_value' => $value, 'last_evaluated_at' => now()]);
        $this->audit->record('alert.evaluated', ['resource_type' => 'alert_rule', 'resource_id' => $rule->id], ['state' => $state, 'value' => $value], $owner->id, $owner->organisation_id);

        return ['state' => $state, 'value' => $value, 'fired' => $fired];
    }
}
