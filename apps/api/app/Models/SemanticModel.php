<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SemanticModel extends Model
{
    use HasUuids, BelongsToOrganisation;

    protected $table = 'semantic_models';

    protected $guarded = ['id'];

    public function dimensions(): HasMany
    {
        return $this->hasMany(Dimension::class);
    }

    public function measures(): HasMany
    {
        return $this->hasMany(Measure::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(Metric::class);
    }

    public function relationships(): HasMany
    {
        return $this->hasMany(Relationship::class);
    }

    public function hierarchies(): HasMany
    {
        return $this->hasMany(Hierarchy::class);
    }

    public function rowLevelPolicies(): HasMany
    {
        return $this->hasMany(RowLevelPolicy::class);
    }

    public function businessRules(): HasMany
    {
        return $this->hasMany(BusinessRule::class);
    }

    public function baseDataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class, 'base_dataset_id');
    }
}
