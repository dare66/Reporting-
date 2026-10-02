<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable copy of a report as it stood when a version was taken.
 *
 * @phpstan-type SectionSnapshot array{position: int, type: string, title: ?string, content: array<string, mixed>}
 * @phpstan-type Snapshot array{title: string, subtitle: ?string, theme: string, parameters: array<string, mixed>|null, sections: list<SectionSnapshot>}
 *
 * @property Snapshot $snapshot
 */
class ReportVersion extends Model
{
    use HasUuids;

    protected $table = 'report_versions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
        ];
    }
}
