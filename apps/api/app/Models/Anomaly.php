<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Anomaly extends Model
{
    use HasUuids, BelongsToOrganisation;

    protected $table = 'anomalies';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'slice' => 'array',
            'evidence' => 'array',
            'period' => 'date',
            'expected' => 'float',
            'actual' => 'float',
            'lower' => 'float',
            'upper' => 'float',
            'score' => 'float',
        ];
    }
}
