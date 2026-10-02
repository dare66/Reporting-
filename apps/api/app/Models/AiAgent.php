<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiAgent extends Model
{
    use HasUuids;

    protected $table = 'ai_agents';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'upstream' => 'array',
            'config' => 'array',
            'enabled' => 'boolean',
        ];
    }
}
