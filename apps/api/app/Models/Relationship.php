<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Relationship extends Model
{
    use HasUuids;

    protected $table = 'relationships';

    protected $guarded = ['id'];
}
