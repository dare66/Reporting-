<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReportTemplate extends Model
{
    use HasUuids;

    protected $table = 'report_templates';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sections' => 'array',
        ];
    }
}
