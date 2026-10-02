<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DashboardWidget extends Model
{
    use HasUuids;

    protected $table = 'dashboard_widgets';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'query' => 'array',
            'viz' => 'array',
            'position' => 'array',
        ];
    }
}
