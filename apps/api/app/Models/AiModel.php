<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiModel extends Model
{
    use HasUuids;

    protected $table = 'model_registry';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'enabled' => 'boolean',
            'cost_per_mtok_in' => 'float',
            'cost_per_mtok_out' => 'float',
        ];
    }
}
