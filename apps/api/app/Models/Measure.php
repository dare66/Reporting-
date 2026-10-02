<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Measure extends Model
{
    use HasUuids;

    protected $table = 'measures';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
        ];
    }
}
