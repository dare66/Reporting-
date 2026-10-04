<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A dataset's schema, profile and trust score at one load or re-profile. */
class DatasetSnapshot extends Model
{
    use BelongsToOrganisation, HasUuids;

    public $timestamps = false;

    protected $table = 'dataset_snapshots';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['columns' => 'array', 'trust' => 'array', 'trust_score' => 'float', 'row_count' => 'integer', 'taken_at' => 'datetime'];
    }

    /** @return HasMany<DriftEvent, $this> */
    public function drift(): HasMany
    {
        return $this->hasMany(DriftEvent::class, 'snapshot_id');
    }
}
