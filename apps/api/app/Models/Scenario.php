<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Scenario extends Model
{
    use BelongsToOrganisation, HasUuids;

    protected $table = 'scenarios';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'assumptions' => 'array',
            'baseline' => 'array',
            'results' => 'array',
        ];
    }
}
