<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    use BelongsToOrganisation, HasUuids;

    protected $table = 'alerts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'value' => 'float',
            'fired_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AlertRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'alert_rule_id');
    }
}
