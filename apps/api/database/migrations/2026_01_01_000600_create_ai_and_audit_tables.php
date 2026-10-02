<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_registry', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider');
            $table->string('model');
            $table->string('purpose'); // planner | narrative | embedding
            $table->decimal('cost_per_mtok_in', 10, 4)->default(0);
            $table->decimal('cost_per_mtok_out', 10, 4)->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['provider', 'model', 'purpose']);
        });

        Schema::create('ai_agents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description');
            $table->string('stage'); // position in the orchestration graph
            $table->jsonb('upstream')->default('[]');
            $table->boolean('enabled')->default(true);
            $table->jsonb('config')->default('{}');
            $table->timestamps();
        });

        Schema::create('prompts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key');
            $table->integer('version');
            $table->text('template');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->unique(['key', 'version']);
        });

        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->jsonb('context')->default('{}'); // last metric/dimensions/filters for follow-ups
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('ai_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('conversation_id')->nullable()->constrained('ai_conversations')->cascadeOnDelete();
            $table->text('question');
            $table->string('intent')->nullable();
            $table->string('status'); // running | succeeded | failed | refused
            $table->string('planner')->nullable(); // llm | deterministic
            $table->string('model')->nullable();
            $table->jsonb('trace')->default('[]'); // agent steps
            $table->jsonb('evidence')->default('[]'); // query ids/sql hashes backing every claim
            $table->integer('tokens_in')->default(0);
            $table->integer('tokens_out')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->integer('latency_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['organisation_id', 'created_at']);
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->foreignUuid('run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->string('role'); // user | assistant
            $table->text('content');
            $table->jsonb('blocks')->default('[]'); // structured response: kpis, charts, tables, drivers
            $table->timestamps();
            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create('ai_feedback', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('run_id')->constrained('ai_runs')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('rating'); // -1 | 1
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'user_id']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action'); // auth.login | query.run | report.export | ai.run ...
            $table->string('resource_type')->nullable();
            $table->string('resource_id')->nullable();
            $table->string('decision')->default('allow'); // allow | deny
            $table->string('result')->default('success'); // success | failure
            $table->string('query_hash', 64)->nullable();
            $table->text('query_sql')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->integer('row_count')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->jsonb('meta')->default('{}');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['organisation_id', 'created_at']);
            $table->index(['organisation_id', 'action']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'ai_feedback', 'ai_messages', 'ai_runs', 'ai_conversations', 'prompts', 'ai_agents', 'model_registry'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
