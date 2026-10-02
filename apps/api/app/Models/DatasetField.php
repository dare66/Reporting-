<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DatasetField extends Model
{
    use HasUuids;

    protected $table = 'dataset_fields';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'profile' => 'array',
            'is_sensitive' => 'boolean',
        ];
    }
}
