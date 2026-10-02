<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('plan')->default('enterprise');
            $table->string('industry')->nullable();
            $table->string('currency', 8)->default('MYR');
            $table->string('timezone')->default('UTC');
            $table->jsonb('branding')->default('{}');
            $table->jsonb('settings')->default('{}');
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->string('name');
            $table->timestamps();
            $table->unique(['organisation_id', 'name']);
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('departments')->nullOnDelete();
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['organisation_id', 'name']);
        });

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('title')->nullable();
            $table->string('status')->default('active');
            // ABAC attributes, e.g. {"country_codes": ["CN", "IN"]}. Drives row-level security.
            $table->jsonb('attributes')->default('{}');
            $table->jsonb('preferences')->default('{}');
            $table->text('mfa_secret')->nullable();
            $table->boolean('mfa_enabled')->default(false);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->index(['organisation_id', 'status']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Null organisation = platform-defined system role available to every tenant.
            $table->foreignUuid('organisation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('experience')->default('analyst'); // executive | analyst | engineer | admin
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->unique(['organisation_id', 'key']);
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->string('group');
            $table->string('description');
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignUuid('role_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('user_roles', function (Blueprint $table) {
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'role_id']);
        });

        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('device')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('feature_flags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->boolean('enabled')->default(false);
            $table->string('description')->nullable();
            $table->timestamps();
            $table->unique(['organisation_id', 'key']);
        });
    }

    public function down(): void
    {
        foreach (['feature_flags', 'refresh_tokens', 'user_roles', 'role_permissions', 'permissions', 'roles', 'users', 'teams', 'departments', 'organisations'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
