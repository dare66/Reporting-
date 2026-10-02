<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReportExport extends Model
{
    use BelongsToOrganisation, HasUuids;

    protected $table = 'report_exports';

    protected $guarded = ['id'];
}
