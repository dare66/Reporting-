<?php

namespace App\Models;

use App\Support\Projects\BelongsToProject;
use App\Support\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dashboard extends Model
{
    use BelongsToOrganisation, BelongsToProject, HasUuids;

    protected $table = 'dashboards';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'sections' => 'array',
            'is_home' => 'boolean',
        ];
    }

    /** @return HasMany<DashboardWidget, $this> */
    public function widgets(): HasMany
    {
        return $this->hasMany(DashboardWidget::class)->orderBy('priority');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
