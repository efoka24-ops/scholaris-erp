<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 2 : structure pedagogique.
 * Hierarchie : Cycle -> (Program optionnel : filiere/serie) -> Level -> ClassRoom -> Group.
 * Program et Level portent chacun un cycle_id direct (denormalise, cf. guide §2.2)
 * pour qu'un niveau sans filiere (ex: college) s'attache directement a son cycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cycles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 50);
            $table->string('name');
            $table->integer('order');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'code']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 50);
            $table->string('name');
            $table->uuid('head_teacher_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'code']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('head_teacher_id')->references('id')->on('users');
        });

        Schema::create('programs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 50);
            $table->string('name');
            $table->uuid('cycle_id');
            $table->uuid('department_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'code']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('cycle_id')->references('id')->on('cycles');
            $table->foreign('department_id')->references('id')->on('departments');
        });

        Schema::create('levels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 50);
            $table->string('name');
            $table->integer('order');
            $table->uuid('cycle_id');
            $table->uuid('program_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'code']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('cycle_id')->references('id')->on('cycles');
            $table->foreign('program_id')->references('id')->on('programs');
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 50);
            $table->string('name');
            $table->enum('type', ['SALLE_CLASSE', 'LABORATOIRE', 'SALLE_INFO', 'AMPHITHEATRE', 'TERRAIN_SPORT'])
                ->default('SALLE_CLASSE');
            $table->integer('capacity')->nullable();
            $table->string('building')->nullable();
            $table->string('floor', 50)->nullable();
            // Postgres text[] -> JSON en MySQL.
            $table->json('equipment')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'code']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        Schema::create('classrooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 50);
            $table->string('name');
            $table->integer('capacity');
            $table->uuid('level_id');
            $table->uuid('main_teacher_id')->nullable();
            $table->uuid('room_id')->nullable();
            $table->enum('section', ['FRANCOPHONE', 'ANGLOPHONE'])->default('FRANCOPHONE');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('level_id')->references('id')->on('levels');
            $table->foreign('main_teacher_id')->references('id')->on('users');
            $table->foreign('room_id')->references('id')->on('rooms');
        });

        Schema::create('groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 50);
            $table->string('name');
            $table->enum('type', ['TD', 'TP', 'LV2']);
            $table->uuid('classroom_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'classroom_id', 'code']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('classroom_id')->references('id')->on('classrooms');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('groups');
        Schema::dropIfExists('classrooms');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('levels');
        Schema::dropIfExists('programs');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('cycles');
    }
};
