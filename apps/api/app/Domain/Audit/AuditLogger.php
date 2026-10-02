<?php

namespace App\Domain\Audit;

use App\Models\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Throwable;

class AuditLogger
{
    public function __construct(private readonly TenantContext $tenant, private readonly ?Request $request = null) {}

    /**
     * @param  array<string, mixed>  $attributes  resource_type, resource_id, decision, result, query_*, duration_ms, row_count
     * @param  array<string, mixed>  $meta
     */
    public function record(string $action, array $attributes = [], array $meta = [], ?string $userId = null, ?string $organisationId = null): void
    {
        try {
            AuditLog::create(array_merge([
                'organisation_id' => $organisationId ?? $this->tenant->organisationId(),
                'user_id' => $userId ?? $this->tenant->user()?->id,
                'action' => $action,
                'ip_address' => $this->request?->ip(),
                'user_agent' => $this->request ? substr((string) $this->request->userAgent(), 0, 250) : null,
                'meta' => $meta,
            ], $attributes));
        } catch (Throwable $e) {
            // Auditing must never take the request down, but failure is itself logged.
            logger()->error('audit.write_failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    /** @param  array<string, mixed>  $attributes  extra audit columns (resource_type, resource_id, …) */
    public function deny(string $action, string $reason, array $attributes = []): void
    {
        $this->record($action, array_merge($attributes, ['decision' => 'deny', 'result' => 'failure']), ['reason' => $reason]);
    }
}
