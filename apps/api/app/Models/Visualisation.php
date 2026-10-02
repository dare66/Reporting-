<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Visualisation extends Model
{
    use HasUuids, BelongsToOrganisation;

    protected $table = 'visualisations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'query' => 'array',
            'viz' => 'array',
        ];
    }
}
