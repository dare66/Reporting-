<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A separate space inside an organisation with its own sources, datasets,
 * models, dashboards, reports and alerts. Open to the whole organisation or
 * only to its members; administrators can open every project.
 */
class Project extends Model
{
    use BelongsToOrganisation, HasUuids;

    protected $table = 'projects';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')->withPivot('role')->withTimestamps();
    }
}
