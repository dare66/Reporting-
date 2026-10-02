<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Forecast extends Model
{
    use BelongsToOrganisation, HasUuids;

    protected $table = 'forecasts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'history' => 'array',
            'points' => 'array',
            'diagnostics' => 'array',
        ];
    }
}
