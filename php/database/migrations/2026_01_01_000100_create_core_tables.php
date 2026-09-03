<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Socle : etablissements (tenants), comptes, RBAC, calendrier academique, audit.
 * Porte depuis packages/prisma/prisma/schema.prisma (PostgreSQL -> MySQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->enum('type', ['PRIMAIRE', 'SECONDAIRE', 'SUPERIEUR', 'TECHNIQUE', 'FORMATION_PRO']);
            $table->enum('status', ['PUBLIC', 'PRIVE'])->default('PUBLIC');
            $table->string('address')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('logo_url')->nullable();
            // Visible dans l'annuaire public de pre-inscription (site vitrine) : opt-in explicite.
            $table->boolean('public_enrollment_enabled')->default(false);
            // Configuration du moteur de calcul et autres parametres (§1.4 du guide).
            $table->json('config_json')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('email');
            $table->string('password_hash');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone', 50)->nullable();
            $table->enum('status', ['ACTIVE', 'INACTIVE', 'SUSPENDED'])->default('ACTIVE');
            $table->boolean('mfa_enabled')->default(false);
            $table->string('mfa_secret')->nullable();
            $table->timestamp('last_login')->nullable();
            $table->string('avatar_url')->nullable();
            $table->integer('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'email']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // null = role systeme global (ex: SUPER_ADMIN)
            $table->uuid('tenant_id')->nullable();
            $table->string('name', 100);
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'name']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('resource', 100);
            // create | read | update | delete | manage
            $table->string('action', 50);
            $table->string('description')->nullable();

            $table->unique(['resource', 'action']);
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->uuid('role_id');
            $table->uuid('permission_id');

            $table->primary(['role_id', 'permission_id']);
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
        });

        Schema::create('user_roles', function (Blueprint $table) {
            $table->uuid('user_id');
            $table->uuid('role_id');

            $table->primary(['user_id', 'role_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
        });

        Schema::create('academic_years', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('label', 100);
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['ACTIVE', 'CLOSED', 'ARCHIVED'])->default('ACTIVE');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'label']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        Schema::create('periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('academic_year_id');
            $table->enum('type', ['SEQUENCE', 'TRIMESTER', 'SEMESTER']);
            $table->integer('number');
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('grading_status', ['CLOSED', 'OPEN', 'LOCKED'])->default('CLOSED');

            $table->unique(['academic_year_id', 'type', 'number']);
            $table->foreign('academic_year_id')->references('id')->on('academic_years');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->string('action', 100);
            $table->string('resource', 100);
            $table->string('resource_id')->nullable();
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('timestamp')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('periods');
        Schema::dropIfExists('academic_years');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('tenants');
    }
};
