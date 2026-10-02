<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Report extends Model
{
    use BelongsToOrganisation, HasUuids;

    protected $table = 'reports';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /** @return HasMany<ReportSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(ReportSection::class)->orderBy('position');
    }

    /** @return HasMany<ReportVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ReportVersion::class)->orderByDesc('version');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
