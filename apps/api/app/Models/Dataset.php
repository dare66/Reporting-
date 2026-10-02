<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dataset extends Model
{
    use BelongsToOrganisation, HasUuids;

    protected $table = 'datasets';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'profile' => 'array',
            'freshness_at' => 'datetime',
        ];
    }

    /** @return HasMany<DatasetField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(DatasetField::class);
    }

    /** @return BelongsTo<DataSource, $this> */
    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }
}
