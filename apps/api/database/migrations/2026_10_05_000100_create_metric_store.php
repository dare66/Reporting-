<?php

use App\Domain\Metrics\MetricDefinition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Metric store: a governed lifecycle (proposed → approved → certified, or
 * deprecated) on every metric, named owners, and a version history of every
 * change. Existing metrics become approved version 1, so nothing in use changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metrics', function (Blueprint $table) {
            $table->string('status')->default('proposed')->index(); // proposed | approved | certified | deprecated
            $table->unsignedInteger('version')->default(1);
            $table->string('definition_hash', 64)->nullable();
            $table->foreignUuid('business_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('data_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('certified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('certified_at')->nullable();
            $table->text('status_note')->nullable(); // why it was deprecated, or why certification lapsed
            $table->string('replaced_by')->nullable(); // metric key that supersedes a deprecated metric
        });

        Schema::create('metric_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('semantic_model_id')->constrained()->cascadeOnDelete();
            $table->string('metric_key');
            $table->unsignedInteger('version');
            $table->jsonb('definition'); // full snapshot: what it means and how it is computed
            $table->string('definition_hash', 64);
            $table->string('summary');
            $table->foreignUuid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->unique(['semantic_model_id', 'metric_key', 'version']);
        });

        // Existing metrics were curated by hand: they start as approved version 1.
        $definitions = new MetricDefinition;
        foreach (DB::table('semantic_models')->get() as $model) {
            $base = DB::table('datasets')->where('id', $model->base_dataset_id)->value('name');
            $measures = DB::table('measures')->where('semantic_model_id', $model->id)->get()
                ->mapWithKeys(fn ($m) => [$m->key => ['key' => $m->key, 'aggregation' => $m->aggregation, 'field' => $m->field, 'filters' => json_decode($m->filters ?? '[]', true)]])->all();
            foreach (DB::table('metrics')->where('semantic_model_id', $model->id)->get() as $metric) {
                $snapshot = $definitions->snapshot((array) $metric, $measures, (string) $base);
                $hash = $definitions->hash($snapshot);
                DB::table('metrics')->where('id', $metric->id)->update(['status' => 'approved', 'definition_hash' => $hash, 'approved_at' => now()]);
                DB::table('metric_versions')->insert([
                    'id' => (string) Str::uuid7(), 'organisation_id' => $model->organisation_id, 'semantic_model_id' => $model->id,
                    'metric_key' => $metric->key, 'version' => 1, 'definition' => json_encode($snapshot), 'definition_hash' => $hash,
                    'summary' => 'Recorded when the metric store was introduced.', 'created_at' => now(),
                ]);
            }
        }

        // The new permission, granted to the platform role that owns the semantic layer.
        $permission = DB::table('permissions')->where('key', 'metrics.certify')->value('id');
        if (! $permission) {
            $permission = (string) Str::uuid7();
            DB::table('permissions')->insert(['id' => $permission, 'key' => 'metrics.certify', 'group' => 'governance',
                'description' => 'Certify, revoke and deprecate governed metrics', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (DB::table('roles')->whereNull('organisation_id')->where('key', 'data_engineer')->pluck('id') as $role) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_versions');
        Schema::table('metrics', function (Blueprint $table) {
            foreach (['business_owner_id', 'data_owner_id', 'approved_by', 'certified_by'] as $fk) {
                $table->dropConstrainedForeignId($fk);
            }
            $table->dropColumn(['status', 'version', 'definition_hash', 'approved_at', 'certified_at', 'status_note', 'replaced_by']);
        });
        DB::table('permissions')->where('key', 'metrics.certify')->delete();
    }
};
