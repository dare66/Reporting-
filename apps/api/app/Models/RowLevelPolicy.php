<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RowLevelPolicy extends Model
{
    use HasUuids;

    protected $table = 'row_level_policies';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'exempt_roles' => 'array',
        ];
    }
}
