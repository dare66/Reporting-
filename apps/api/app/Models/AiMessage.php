<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiMessage extends Model
{
    use HasUuids;

    protected $table = 'ai_messages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'blocks' => 'array',
        ];
    }
}
