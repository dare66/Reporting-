<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('semantic_models', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('base_dataset_id')->constrained('datasets')->restrictOnDelete();
            $table->string('key');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('domain')->nullable(); // operations | finance | sales | risk ...
            $table->string('time_dimension')->nullable(); // key of the default time dimension
            $table->string('status')->default('published');
            $table->integer('version')->default(1);
            $table->timestamps();
            $table->unique(['organisation_id', 'key']);
        });

        Schema::create('relationships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('semantic_model_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('from_dataset_id')->constrained('datasets')->cascadeOnDelete();
            $table->string('from_field');
            $table->foreignUuid('to_dataset_id')->constrained('datasets')->cascadeOnDelete();
            $table->string('to_field');
            $table->string('type')->default('many_to_one');
            $table->timestamps();
        });

        Schema::create('hierarchies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('semantic_model_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('label');
            $table->jsonb('levels'); // ordered dimension keys, e.g. ["region","country"]
            $table->timestamps();
            $table->unique(['semantic_model_id', 'key']);
        });

        Schema::create('dimensions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('semantic_model_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('dataset_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('label');
            $table->string('field');
            $table->string('type')->default('string'); // string | number | time | geo | boolean
            $table->text('description')->nullable();
            $table->boolean('is_sensitive')->default(false);
            $table->boolean('root_cause_candidate')->default(true);
            $table->jsonb('synonyms')->default('[]');
            $table->timestamps();
            $table->unique(['semantic_model_id', 'key']);
        });

        Schema::create('measures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('semantic_model_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('label');
            $table->string('aggregation'); // count | count_distinct | sum | avg | min | max
            $table->string('field')->nullable(); // base-dataset field; null for count(*)
            $table->jsonb('filters')->default('[]'); // [{field, op, value}] applied as FILTER (WHERE ...)
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['semantic_model_id', 'key']);
        });

        Schema::create('metrics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('semantic_model_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('label');
            $table->text('description')->nullable();
            // Arithmetic over measure keys only, e.g. "approved_applications / total_applications".
            $table->string('expression');
            $table->string('format')->default('number'); // number | percent | currency | duration_days
            $table->boolean('higher_is_better')->default(true);
            $table->decimal('target', 18, 4)->nullable();
            $table->jsonb('synonyms')->default('[]');
            $table->boolean('is_kpi')->default(false);
            $table->string('owner')->nullable();
            $table->timestamps();
            $table->unique(['semantic_model_id', 'key']);
        });

        Schema::create('business_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('semantic_model_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description');
            $table->jsonb('rule')->default('{}');
            $table->timestamps();
        });

        // Row-level security: restrict a dimension to values held in a user attribute.
        Schema::create('row_level_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('semantic_model_id')->constrained()->cascadeOnDelete();
            $table->string('dimension_key');
            $table->string('user_attribute');
            $table->jsonb('exempt_roles')->default('[]');
            $table->timestamps();
        });

        Schema::create('data_lineage', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('from_type');
            $table->string('from_ref');
            $table->string('to_type');
            $table->string('to_ref');
            $table->string('transformation')->nullable();
            $table->timestamps();
            $table->index(['organisation_id', 'to_type', 'to_ref']);
        });
    }

    public function down(): void
    {
        foreach (['data_lineage', 'row_level_policies', 'business_rules', 'metrics', 'measures', 'dimensions', 'hierarchies', 'relationships', 'semantic_models'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
