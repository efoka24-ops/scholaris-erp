<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 3 : matieres, UE et EC (referentiel des disciplines).
 * Matieres simples (coefficient + heures hebdo) pour le primaire/secondaire ;
 * UE (teaching_units) -> EC (course_elements) pour le superieur LMD.
 * subject_assignments relie un enseignant a une matiere OU a un EC pour une
 * classe et une annee academique donnees.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 50);
            $table->string('name');
            $table->decimal('coefficient', 5, 2);
            $table->integer('weekly_hours');
            $table->enum('category', ['LITERARY', 'SCIENTIFIC', 'TECHNICAL', 'LANGUAGE', 'SPORTS']);
            $table->boolean('is_eliminatory')->default(false);
            // Seuil (note) en dessous duquel la matiere est eliminatoire ; 0 = desactive.
            $table->decimal('eliminatory_threshold', 5, 2)->default(0);
            // Niveaux auxquels la matiere est enseignee (Postgres text[] -> JSON).
            $table->json('level_ids')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        Schema::create('teaching_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 50);
            $table->string('name');
            $table->integer('credits');
            $table->integer('semester');
            $table->boolean('is_fundamental')->default(false);
            $table->uuid('department_id');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('department_id')->references('id')->on('departments');
        });

        Schema::create('course_elements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 50);
            $table->string('name');
            $table->integer('credits');
            $table->integer('hours_cm')->default(0);
            $table->integer('hours_td')->default(0);
            $table->integer('hours_tp')->default(0);
            $table->decimal('coefficient', 5, 2)->default(1);
            $table->uuid('teaching_unit_id');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('teaching_unit_id')->references('id')->on('teaching_units');
        });

        Schema::create('subject_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            // Exactement l'un des deux est renseigne : subject_id (secondaire)
            // OU course_element_id (superieur LMD). Validation cote service.
            $table->uuid('subject_id')->nullable();
            $table->uuid('course_element_id')->nullable();
            $table->uuid('teacher_id');
            $table->uuid('classroom_id');
            $table->uuid('academic_year_id');
            $table->timestamps();
            $table->softDeletes();

            // MySQL n'applique pas l'unicite quand une colonne est NULL : le
            // controle applicatif (409) reste la barriere principale, ces index
            // couvrent les courses concurrentes usuelles.
            $table->unique(['tenant_id', 'subject_id', 'classroom_id', 'academic_year_id'], 'sa_subject_unique');
            $table->unique(['tenant_id', 'course_element_id', 'classroom_id', 'academic_year_id'], 'sa_ce_unique');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('subject_id')->references('id')->on('subjects');
            $table->foreign('course_element_id')->references('id')->on('course_elements');
            $table->foreign('teacher_id')->references('id')->on('users');
            $table->foreign('classroom_id')->references('id')->on('classrooms');
            $table->foreign('academic_year_id')->references('id')->on('academic_years');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_assignments');
        Schema::dropIfExists('course_elements');
        Schema::dropIfExists('teaching_units');
        Schema::dropIfExists('subjects');
    }
};
