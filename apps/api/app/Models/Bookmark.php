<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Bookmark extends Model
{
    use BelongsToOrganisation, HasUuids;

    protected $table = 'bookmarks';

    protected $guarded = ['id'];
}
