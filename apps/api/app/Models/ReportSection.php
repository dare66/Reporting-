<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReportSection extends Model
{
    use HasUuids;

    protected $table = 'report_sections';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'content' => 'array',
        ];
    }
}
