<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataSource extends Model
{
    use BelongsToOrganisation, HasUuids;

    protected $table = 'data_sources';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'load_progress' => 'array',
            'last_sync_at' => 'datetime',
        ];
    }

    protected $hidden = ['config'];

    /** @return HasMany<IngestionRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(IngestionRun::class)->latest('started_at');
    }

    /** @return HasMany<Dataset, $this> */
    public function datasets(): HasMany
    {
        return $this->hasMany(Dataset::class);
    }
}
