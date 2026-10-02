<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BusinessRule extends Model
{
    use HasUuids;

    protected $table = 'business_rules';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rule' => 'array',
        ];
    }
}
