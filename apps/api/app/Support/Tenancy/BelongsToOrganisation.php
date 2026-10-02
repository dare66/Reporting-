<?php

namespace App\Support\Tenancy;

use App\Models\Organisation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant isolation for every organisation-owned model.
 *
 * Fails closed: inside an HTTP request without a resolved tenant the scope
 * matches no rows, so a missing middleware can never leak cross-tenant data.
 */
trait BelongsToOrganisation
{
    public static function bootBelongsToOrganisation(): void
    {
        static::addGlobalScope('organisation', function (Builder $builder) {
            $context = app(TenantContext::class);
            $column = $builder->getModel()->qualifyColumn('organisation_id');

            if ($context->hasTenant()) {
                $builder->where($column, $context->organisationId());
            } elseif (! app()->runningInConsole() || app()->runningUnitTests()) {
                if (! TenantScopeBypass::active()) {
                    $builder->whereRaw('1 = 0');
                }
            }
        });

        static::creating(function ($model) {
            $context = app(TenantContext::class);
            if (empty($model->organisation_id) && $context->hasTenant()) {
                $model->organisation_id = $context->organisationId();
            }
        });
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }
}
