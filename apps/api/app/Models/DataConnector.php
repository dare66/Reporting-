<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DataConnector extends Model
{
    use HasUuids;

    protected $table = 'data_connectors';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'config_schema' => 'array',
        ];
    }
}
