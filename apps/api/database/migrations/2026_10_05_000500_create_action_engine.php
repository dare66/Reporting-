<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Action engine: actions are proposed (by a person, the AI analyst or an alert),
 * approved by someone allowed to, then run, verified and audited. Incidents are
 * the built-in ticket an action can open; destinations are the organisation's
 * webhooks, Slack and Teams channels, and email lists.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string, 2: list<string>}> permission => [group, description, platform roles granted it] */
    private const PERMISSIONS = [
        'actions.request' => ['actions', 'Propose actions such as incidents and notifications', ['ceo', 'executive', 'manager', 'analyst', 'data_engineer']],
        'actions.approve' => ['actions', 'Approve, reject and run proposed actions', ['ceo', 'executive', 'manager']],
    ];

    public function up(): void
    {
        Schema::create('action_destinations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind'); // webhook | slack | teams | email
            $table->text('config'); // encrypted: url, headers, recipients
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('action_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $table->string('kind'); // incident | notify | webhook | slack | teams | email
            $table->foreignUuid('destination_id')->nullable()->constrained('action_destinations')->nullOnDelete();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->jsonb('payload')->default('{}');
            $table->jsonb('evidence')->default('{}');
            $table->string('source')->default('manual'); // manual | ai | alert
            $table->string('source_ref')->nullable();
            // proposed → approved → running → done | failed;  proposed → rejected | cancelled
            $table->string('status')->default('proposed');
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->jsonb('result')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamps();
            $table->index(['organisation_id', 'status']);
            $table->index(['source', 'source_ref']);
        });

        Schema::create('incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('severity')->default('medium'); // low | medium | high | critical
            $table->string('status')->default('open'); // open | investigating | resolved
            $table->string('metric_ref')->nullable();
            $table->jsonb('evidence')->default('{}');
            $table->foreignUuid('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('action_id')->nullable()->constrained('action_requests')->nullOnDelete();
            $table->foreignUuid('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['organisation_id', 'number']);
        });

        Schema::table('alert_rules', function (Blueprint $table) {
            // What to propose when the rule fires, e.g. [{"kind":"incident","severity":"high"}].
            $table->jsonb('actions')->default('[]');
        });

        foreach (self::PERMISSIONS as $key => [$group, $description, $roles]) {
            $permission = DB::table('permissions')->where('key', $key)->value('id');
            if (! $permission) {
                $permission = (string) Str::uuid7();
                DB::table('permissions')->insert(['id' => $permission, 'key' => $key, 'group' => $group,
                    'description' => $description, 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach (DB::table('roles')->whereNull('organisation_id')->whereIn('key', $roles)->pluck('id') as $role) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('alert_rules', fn (Blueprint $table) => $table->dropColumn('actions'));
        Schema::dropIfExists('incidents');
        Schema::dropIfExists('action_requests');
        Schema::dropIfExists('action_destinations');
        DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->delete();
    }
};
