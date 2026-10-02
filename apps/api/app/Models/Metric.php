<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Metric extends Model
{
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
        ];
    }

    /** @return BelongsTo<SemanticModel, $this> */
    public function semanticModel(): BelongsTo
    {
        return $this->belongsTo(SemanticModel::class);
    }
}
