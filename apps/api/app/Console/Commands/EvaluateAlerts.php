<?php

namespace App\Console\Commands;

use App\Domain\Alerts\AlertEvaluator;
use App\Models\AlertRule;
use App\Support\Tenancy\TenantScopeBypass;
use Illuminate\Console\Command;

class EvaluateAlerts extends Command
{
    protected $signature = 'aixbi:alerts:evaluate {--all : Ignore frequency and evaluate every active rule}';

    protected $description = 'Evaluate due alert rules and send intelligent notifications';

    public function handle(AlertEvaluator $evaluator): int
    {
        $rules = TenantScopeBypass::run(fn () => AlertRule::with('semanticModel')->where('is_active', true)->get())
            ->filter(fn ($r) => $this->option('all') || ! $r->last_evaluated_at || $r->last_evaluated_at->addMinutes($r->frequency_minutes)->isPast());
        foreach ($rules as $rule) {
            try {
                $r = $evaluator->evaluate($rule);
                $this->line("{$rule->name}: {$r['state']} ({$r['value']})".($r['fired'] ? ' — notified' : ''));
            } catch (\Throwable $e) {
                report($e);
                $this->error("{$rule->name}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
