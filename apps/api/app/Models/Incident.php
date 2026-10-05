<?php

namespace App\Models;

use App\Support\Projects\BelongsToProject;
use App\Support\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The built-in ticket an approved action opens, numbered per organisation (INC-0001). */
class Incident extends Model
{
    use BelongsToOrganisation, BelongsToProject, HasUuids;

    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    public const STATUSES = ['open', 'investigating', 'resolved'];

    protected $table = 'incidents';

    protected $guarded = ['id'];

    protected $appends = ['reference'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'resolved_at' => 'datetime', 'number' => 'integer'];
    }

    public function getReferenceAttribute(): string
    {
        return 'INC-'.str_pad((string) $this->number, 4, '0', STR_PAD_LEFT);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return BelongsTo<ActionRequest, $this> */
    public function action(): BelongsTo
    {
        return $this->belongsTo(ActionRequest::class, 'action_id');
    }
}
