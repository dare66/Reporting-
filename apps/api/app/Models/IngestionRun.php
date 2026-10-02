<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IngestionRun extends Model
{
    use BelongsToOrganisation, HasUuids;

    protected $table = 'ingestion_runs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'log' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
