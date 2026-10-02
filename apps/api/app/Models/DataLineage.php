<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DataLineage extends Model
{
    use BelongsToOrganisation, HasUuids;

    protected $table = 'data_lineage';

    protected $guarded = ['id'];
}
