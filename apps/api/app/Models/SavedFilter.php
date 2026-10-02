<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SavedFilter extends Model
{
    use HasUuids, BelongsToOrganisation;

    protected $table = 'filters';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
        ];
    }
}
