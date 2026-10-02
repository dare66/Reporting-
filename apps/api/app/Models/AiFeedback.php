<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiFeedback extends Model
{
    use HasUuids;

    protected $table = 'ai_feedback';

    protected $guarded = ['id'];
}
