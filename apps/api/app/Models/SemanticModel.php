<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SemanticModel extends Model
{
    use BelongsToOrganisation, HasUuids;

    protected $table = 'semantic_models';

    protected $guarded = ['id'];

    /** @return HasMany<Dimension, $this> */
    public function dimensions(): HasMany
    {
        return $this->hasMany(Dimension::class);
    }

    /** @return HasMany<Measure, $this> */
    public function measures(): HasMany
    {
        return $this->hasMany(Measure::class);
    }

    /** @return HasMany<Metric, $this> */
    public function metrics(): HasMany
    {
        return $this->hasMany(Metric::class);
    }

    /** @return HasMany<Relationship, $this> */
    public function relationships(): HasMany
    {
        return $this->hasMany(Relationship::class);
    }

    /** @return HasMany<Hierarchy, $this> */
    public function hierarchies(): HasMany
    {
        return $this->hasMany(Hierarchy::class);
    }

    /** @return HasMany<RowLevelPolicy, $this> */
    public function rowLevelPolicies(): HasMany
    {
        return $this->hasMany(RowLevelPolicy::class);
    }

    /** @return HasMany<BusinessRule, $this> */
    public function businessRules(): HasMany
    {
        return $this->hasMany(BusinessRule::class);
    }

    /** @return BelongsTo<Dataset, $this> */
    public function baseDataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class, 'base_dataset_id');
    }
}
