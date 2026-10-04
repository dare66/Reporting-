<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A governed metric. Lifecycle: proposed → approved → certified; any state → deprecated.
 */
class Metric extends Model
{
    public const STATUSES = ['proposed', 'approved', 'certified', 'deprecated'];

    use HasUuids;

    protected $table = 'metrics';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'synonyms' => 'array',
            'higher_is_better' => 'boolean',
            'is_kpi' => 'boolean',
            'target' => 'float',
            'version' => 'integer',
            'approved_at' => 'datetime',
            'certified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function businessOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'business_owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function dataOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'data_owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function certifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'certified_by');
    }

    /** @return BelongsTo<SemanticModel, $this> */
    public function semanticModel(): BelongsTo
    {
        return $this->belongsTo(SemanticModel::class);
    }
}
