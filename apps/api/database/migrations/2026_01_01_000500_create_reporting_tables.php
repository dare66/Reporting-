<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->string('audience'); // ceo | cfo | coo | board | operations ...
            $table->text('description')->nullable();
            $table->jsonb('sections'); // ordered section blueprints
            $table->string('theme')->default('executive');
            $table->timestamps();
            $table->unique(['organisation_id', 'key']);
        });

        Schema::create('reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('template_id')->nullable()->constrained('report_templates')->nullOnDelete();
            $table->foreignUuid('semantic_model_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('type')->default('management'); // ceo | board | management | operational ...
            $table->string('status')->default('draft'); // draft | published | archived
            $table->string('theme')->default('executive');
            $table->jsonb('parameters')->default('{}'); // period, filters
            $table->integer('current_version')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['organisation_id', 'status']);
        });

        Schema::create('report_sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_id')->constrained()->cascadeOnDelete();
            $table->integer('position');
            $table->string('type'); // summary | kpis | chart | insights | anomalies | root_cause | forecast | table | text | risks
            $table->string('title');
            $table->jsonb('content')->default('{}');
            $table->timestamps();
            $table->index(['report_id', 'position']);
        });

        Schema::create('report_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_id')->constrained()->cascadeOnDelete();
            $table->integer('version');
            $table->string('status');
            $table->jsonb('snapshot'); // full report + sections at that version
            $table->string('note')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['report_id', 'version']);
        });

        Schema::create('report_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('report_id')->constrained()->cascadeOnDelete();
            $table->string('format'); // pdf | pptx | xlsx | csv | html
            $table->string('status')->default('queued'); // queued | running | ready | failed
            $table->string('path')->nullable();
            $table->bigInteger('bytes')->nullable();
            $table->text('error')->nullable();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('scheduled_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('report_id')->constrained()->cascadeOnDelete();
            $table->string('frequency'); // daily | weekly | monthly | quarterly
            $table->string('time_of_day')->default('08:00');
            $table->jsonb('formats')->default('["pdf"]');
            $table->jsonb('channels')->default('["email"]'); // email | push | in_app
            $table->jsonb('recipients')->default('[]');
            $table->boolean('is_active')->default(true);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['is_active', 'next_run_at']);
        });

        Schema::create('alert_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('semantic_model_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('metric_key');
            $table->string('operator'); // lt | lte | gt | gte | change_pct_gt | change_pct_lt
            $table->decimal('threshold', 18, 4);
            $table->string('window')->default('today'); // today | last_7_days | this_month
            $table->jsonb('filters')->default('[]');
            $table->integer('frequency_minutes')->default(15);
            $table->jsonb('channels')->default('["in_app"]');
            $table->jsonb('recipients')->default('[]');
            $table->boolean('is_active')->default(true);
            $table->string('last_state')->default('ok'); // ok | breached
            $table->decimal('last_value', 20, 6)->nullable();
            $table->timestamp('last_evaluated_at')->nullable();
            $table->string('created_via')->default('form'); // form | conversation
            $table->timestamps();
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->decimal('value', 20, 6);
            $table->string('message');
            $table->jsonb('evidence')->default('{}');
            $table->timestamp('fired_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignUuid('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // alert | anomaly | report | data | forecast | mention
            $table->string('severity')->default('info');
            $table->string('title');
            $table->text('body');
            $table->string('link')->nullable(); // deep link, e.g. /investigate?metric=sla_compliance
            $table->jsonb('data')->default('{}');
            $table->jsonb('channels')->default('["in_app"]');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at', 'created_at']);
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->string('resource_type');
            $table->uuid('resource_id');
            $table->text('body');
            $table->jsonb('mentions')->default('[]');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['resource_type', 'resource_id']);
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('comments')->cascadeOnDelete();
        });

        Schema::create('bookmarks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type');
            $table->uuid('resource_id');
            $table->timestamps();
            $table->unique(['user_id', 'resource_type', 'resource_id']);
        });
    }

    public function down(): void
    {
        foreach (['bookmarks', 'comments', 'notifications', 'alerts', 'alert_rules', 'scheduled_reports', 'report_exports', 'report_versions', 'report_sections', 'reports', 'report_templates'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
