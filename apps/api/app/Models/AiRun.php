<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiRun extends Model
{
    use HasUuids, BelongsToOrganisation;

    protected $table = 'ai_runs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'trace' => 'array',
            'evidence' => 'array',
            'cost_usd' => 'float',
        ];
    }
}
