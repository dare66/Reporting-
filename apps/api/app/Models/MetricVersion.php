<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One saved state of a metric's definition; versions are append-only. */
class MetricVersion extends Model
{
    use BelongsToOrganisation, HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'metric_versions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['definition' => 'array', 'version' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
