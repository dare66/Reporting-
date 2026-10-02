<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AlertRule extends Model
{
    use HasUuids, BelongsToOrganisation;

    protected $table = 'alert_rules';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'channels' => 'array',
            'recipients' => 'array',
            'is_active' => 'boolean',
            'threshold' => 'float',
            'last_value' => 'float',
            'last_evaluated_at' => 'datetime',
        ];
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class)->latest('fired_at');
    }

    public function semanticModel(): BelongsTo
    {
        return $this->belongsTo(SemanticModel::class);
    }
}
