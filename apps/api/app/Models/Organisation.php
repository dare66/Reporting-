<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Organisation extends Model
{
    use HasUuids;

    protected $table = 'organisations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'branding' => 'array',
            'settings' => 'array',
        ];
    }
}
