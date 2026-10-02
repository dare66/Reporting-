<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRun extends Model
{
    use BelongsToOrganisation, HasUuids;

    protected $table = 'ai_runs';

    protected $guarded = ['id'];

    /** @return BelongsTo<AiConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    protected function casts(): array
    {
        return [
            'trace' => 'array',
            'evidence' => 'array',
            'cost_usd' => 'float',
        ];
    }
}
