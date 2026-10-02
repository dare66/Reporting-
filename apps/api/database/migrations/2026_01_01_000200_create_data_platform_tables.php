<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform-wide connector catalogue. New connectors register a row plus a driver class.
        Schema::create('data_connectors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('category'); // database | warehouse | file | api | stream | storage
            $table->jsonb('capabilities')->default('[]'); // full_refresh, incremental, cdc, webhook, polling
            $table->jsonb('config_schema')->default('[]');
            $table->string('status')->default('available'); // available | beta | planned
            $table->timestamps();
        });

        Schema::create('data_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('connector_key');
            $table->string('name');
            $table->text('config')->nullable(); // encrypted JSON (Laravel encrypter, AES-256-GCM)
            $table->string('status')->default('pending'); // pending | connected | syncing | error
            $table->string('sync_mode')->default('full'); // full | incremental | cdc
            $table->string('schedule')->nullable(); // cron expression
            $table->timestamp('last_sync_at')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organisation_id', 'status']);
        });

        Schema::create('ingestion_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('data_source_id')->constrained()->cascadeOnDelete();
            $table->string('mode');
            $table->string('status'); // running | succeeded | failed
            $table->bigInteger('records')->default(0);
            $table->integer('duration_ms')->nullable();
            $table->integer('error_count')->default(0);
            $table->integer('warning_count')->default(0);
            $table->jsonb('log')->default('[]');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['data_source_id', 'started_at']);
        });

        Schema::create('datasets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('data_source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('label');
            $table->text('description')->nullable();
            $table->string('physical_schema')->default('analytics');
            $table->string('physical_table');
            $table->bigInteger('row_count')->nullable();
            $table->timestamp('freshness_at')->nullable();
            $table->jsonb('profile')->default('{}');
            $table->timestamps();
            $table->unique(['organisation_id', 'name']);
        });

        Schema::create('dataset_fields', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dataset_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('label');
            $table->string('data_type'); // string | integer | decimal | boolean | date | timestamp
            $table->text('description')->nullable();
            $table->boolean('is_sensitive')->default(false);
            $table->jsonb('profile')->default('{}');
            $table->timestamps();
            $table->unique(['dataset_id', 'name']);
        });
    }

    public function down(): void
    {
        foreach (['dataset_fields', 'datasets', 'ingestion_runs', 'data_sources', 'data_connectors'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
