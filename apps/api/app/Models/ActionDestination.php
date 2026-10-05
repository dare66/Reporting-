<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Where an approved action can be sent: a webhook (any REST API, such as Jira
 * or ServiceNow), a Slack or Teams incoming webhook, or an email list. The
 * configuration holds secrets, so it is encrypted and never serialised.
 */
class ActionDestination extends Model
{
    use BelongsToOrganisation, HasUuids;

    public const KINDS = ['webhook', 'slack', 'teams', 'email'];

    protected $table = 'action_destinations';

    protected $guarded = ['id'];

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return ['config' => 'encrypted:array', 'is_active' => 'boolean'];
    }
}
