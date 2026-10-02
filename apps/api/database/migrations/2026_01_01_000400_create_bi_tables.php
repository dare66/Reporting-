<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('semantic_model_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('theme')->default('dark-intelligence');
            $table->jsonb('filters')->default('[]'); // global dashboard filters
            $table->jsonb('sections')->default('[]'); // named sections for mobile swipe
            $table->string('visibility')->default('organisation'); // private | organisation
            $table->boolean('is_home')->default(false);
            $table->timestamps();
            $table->index(['organisation_id', 'visibility']);
        });

        Schema::create('dashboard_widgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dashboard_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // kpi | chart | table | insight | text | globe | forecast | anomalies
            $table->string('title')->nullable();
            $table->string('section')->nullable();
            $table->jsonb('query')->default('{}'); // semantic query
            $table->jsonb('viz')->default('{}'); // chart type & options
            // Desktop 12-col position; tablet/mobile layouts are derived by the reflow engine.
            $table->jsonb('position')->default('{}');
            $table->integer('priority')->default(100); // reflow order on small screens
            $table->timestamps();
        });

        Schema::create('visualisations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->jsonb('query');
            $table->jsonb('viz');
            $table->timestamps();
        });

        Schema::create('filters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('dashboard_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->jsonb('definition'); // saved view: [{dimension, op, value}]
            $table->timestamps();
        });

        Schema::create('insights', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('semantic_model_id')->nullable()->constrained()->nullOnDelete();
            $table->string('metric_key')->nullable();
            $table->string('kind'); // change | driver | anomaly | forecast | capacity
            $table->string('severity')->default('info'); // info | positive | warning | critical
            $table->string('title');
            $table->text('body');
            $table->jsonb('evidence')->default('{}'); // query, sql hash, filters, period, calculation
            $table->string('generated_by')->default('insight-engine');
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamps();
            $table->index(['organisation_id', 'created_at']);
        });

        Schema::create('anomalies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('semantic_model_id')->nullable()->constrained()->nullOnDelete();
            $table->string('metric_key');
            $table->jsonb('slice')->default('[]');
            $table->date('period');
            $table->string('grain')->default('day');
            $table->decimal('expected', 20, 6);
            $table->decimal('actual', 20, 6);
            $table->decimal('lower', 20, 6)->nullable();
            $table->decimal('upper', 20, 6)->nullable();
            $table->decimal('score', 10, 4);
            $table->string('method');
            $table->string('severity');
            $table->string('status')->default('open'); // open | investigating | resolved | dismissed
            $table->jsonb('evidence')->default('{}');
            $table->timestamps();
            $table->unique(['organisation_id', 'metric_key', 'period', 'grain']);
        });

        Schema::create('forecasts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('semantic_model_id')->nullable()->constrained()->nullOnDelete();
            $table->string('metric_key');
            $table->string('grain');
            $table->integer('horizon');
            $table->string('method');
            $table->jsonb('history');
            $table->jsonb('points');
            $table->jsonb('diagnostics')->default('{}');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('scenarios', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->jsonb('assumptions');
            $table->jsonb('baseline');
            $table->jsonb('results');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['scenarios', 'forecasts', 'anomalies', 'insights', 'filters', 'visualisations', 'dashboard_widgets', 'dashboards'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
