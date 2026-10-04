<?php

use App\Domain\Trust\SchemaSnapshot;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Data Trust Center: one snapshot of each dataset's schema, profile and trust
 * score per load or re-profile, and the drift found between consecutive
 * snapshots. Existing datasets get a baseline snapshot so drift is measured
 * from their next load onwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dataset_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('dataset_id')->constrained()->cascadeOnDelete();
            $table->string('trigger'); // load | profile | baseline
            $table->unsignedBigInteger('row_count');
            $table->jsonb('columns'); // name → type, null %, distinct, range, top values
            $table->decimal('trust_score', 5, 1)->nullable();
            $table->jsonb('trust')->nullable();
            $table->timestamp('taken_at');
            $table->index(['dataset_id', 'taken_at']);
        });

        Schema::create('data_drift_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('dataset_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('snapshot_id')->constrained('dataset_snapshots')->cascadeOnDelete();
            // column_added | column_removed | column_renamed | type_changed | nulls_increased | range_shifted | new_values | row_count_dropped
            $table->string('kind');
            $table->string('column')->nullable();
            $table->string('severity'); // critical | warning | info
            $table->text('message');
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->string('status')->default('open'); // open | acknowledged
            $table->foreignUuid('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('detected_at');
            $table->index(['organisation_id', 'status', 'detected_at']);
        });

        $snapshots = new SchemaSnapshot;
        foreach (DB::table('datasets')->get() as $dataset) {
            $fields = DB::table('dataset_fields')->where('dataset_id', $dataset->id)->get()
                ->map(fn ($f) => ['name' => $f->name, 'data_type' => $f->data_type, 'profile' => json_decode($f->profile ?? '{}', true) ?? []])->all();
            DB::table('dataset_snapshots')->insert([
                'id' => (string) Str::uuid7(), 'organisation_id' => $dataset->organisation_id, 'dataset_id' => $dataset->id, 'trigger' => 'baseline',
                'row_count' => (int) $dataset->row_count, 'columns' => json_encode($snapshots->columns($fields)), 'taken_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('data_drift_events');
        Schema::dropIfExists('dataset_snapshots');
    }
};
