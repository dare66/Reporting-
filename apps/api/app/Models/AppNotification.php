<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AppNotification extends Model
{
    use HasUuids, BelongsToOrganisation;

    protected $table = 'notifications';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'channels' => 'array',
            'read_at' => 'datetime',
        ];
    }
}
