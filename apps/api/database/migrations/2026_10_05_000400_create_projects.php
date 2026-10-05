<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Projects: separate spaces inside an organisation, each with its own data
 * sources, datasets, semantic models (and their metrics), dashboards, reports
 * and alerts. Everything that exists moves into one default project per
 * organisation ("General", open to the whole organisation), so nothing
 * changes for current users.
 */
return new class extends Migration
{
    /** Tables whose rows belong to a project; child rows (fields, widgets, sections, metrics…) follow their parent. */
    private const OWNED = ['data_sources', 'datasets', 'semantic_models', 'dashboards', 'reports', 'alert_rules'];

    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('visibility')->default('members'); // organisation | members
            $table->boolean('is_default')->default(false);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organisation_id', 'key']);
        });

        Schema::create('project_members', function (Blueprint $table) {
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('member'); // owner | member
            $table->timestamps();
            $table->primary(['project_id', 'user_id']);
        });

        Schema::table('data_sources', function (Blueprint $table) {
            $table->foreignUuid('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->index('project_id');
        });
        Schema::table('datasets', function (Blueprint $table) {
            $table->foreignUuid('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->index('project_id');
        });
        Schema::table('semantic_models', function (Blueprint $table) {
            $table->foreignUuid('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->index('project_id');
        });
        Schema::table('dashboards', function (Blueprint $table) {
            $table->foreignUuid('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->index('project_id');
        });
        Schema::table('reports', function (Blueprint $table) {
            $table->foreignUuid('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->index('project_id');
        });
        Schema::table('alert_rules', function (Blueprint $table) {
            $table->foreignUuid('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->index('project_id');
        });

        foreach (DB::table('organisations')->pluck('id') as $org) {
            $id = (string) Str::uuid7();
            DB::table('projects')->insert(['id' => $id, 'organisation_id' => $org, 'key' => 'general', 'name' => 'General',
                'description' => 'Everything that existed before projects were introduced. Open to the whole organisation.',
                'visibility' => 'organisation', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
            foreach (self::OWNED as $owned) {
                DB::table($owned)->where('organisation_id', $org)->whereNull('project_id')->update(['project_id' => $id]);
            }
        }

        foreach (self::OWNED as $owned) {
            DB::statement("ALTER TABLE {$owned} ALTER COLUMN project_id SET NOT NULL");
        }
    }

    public function down(): void
    {
        foreach (self::OWNED as $owned) {
            Schema::table($owned, function (Blueprint $table) {
                $table->dropConstrainedForeignId('project_id');
            });
        }
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('projects');
    }
};
