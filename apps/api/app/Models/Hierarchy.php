<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Hierarchy extends Model
{
    use HasUuids;

    protected $table = 'hierarchies';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'levels' => 'array',
        ];
    }
}
