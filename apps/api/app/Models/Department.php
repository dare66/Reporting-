<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use BelongsToOrganisation, HasUuids;

    protected $table = 'departments';

    protected $guarded = ['id'];
}
