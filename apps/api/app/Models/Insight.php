<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Insight extends Model
{
    use BelongsToOrganisation, HasUuids;

    protected $table = 'insights';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
        ];
    }
}
