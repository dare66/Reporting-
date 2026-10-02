<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dimension extends Model
{
    use HasUuids;

    protected $table = 'dimensions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'synonyms' => 'array',
            'is_sensitive' => 'boolean',
            'root_cause_candidate' => 'boolean',
        ];
    }

    /** @return BelongsTo<Dataset, $this> */
    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }
}
