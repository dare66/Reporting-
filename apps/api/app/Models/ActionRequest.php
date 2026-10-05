<?php

namespace App\Models;

use App\Support\Projects\BelongsToProject;
use App\Support\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One action, from proposal to verified result. Proposed by a person, the AI
 * analyst or an alert; run only once someone allowed to approves it.
 */
class ActionRequest extends Model
{
    use BelongsToOrganisation, BelongsToProject, HasUuids;

    protected $table = 'action_requests';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array', 'evidence' => 'array', 'result' => 'array',
            'decided_at' => 'datetime', 'executed_at' => 'datetime', 'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return BelongsTo<ActionDestination, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(ActionDestination::class, 'destination_id');
    }

    /** @return HasOne<Incident, $this> */
    public function incident(): HasOne
    {
        return $this->hasOne(Incident::class, 'action_id');
    }
}
