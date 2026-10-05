<?php

namespace App\Support\Projects;

use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Keeps a record inside its project: queries in a request see only the
 * current project (or, with none chosen, every project the person may open),
 * and new records go into the current project, else the organisation's
 * default project. Tenant isolation (BelongsToOrganisation) still applies first.
 */
trait BelongsToProject
{
    public static function bootBelongsToProject(): void
    {
        static::addGlobalScope('project', function (Builder $builder) {
            $context = app(ProjectContext::class);
            if ($context->active()) {
                $builder->whereIn($builder->getModel()->qualifyColumn('project_id'), $context->visible());
            }
        });

        static::creating(function ($model) {
            if (empty($model->project_id)) {
                $model->project_id = app(ProjectContext::class)->current() ?? Projects::defaultFor((string) $model->organisation_id)->id;
            }
        });
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
