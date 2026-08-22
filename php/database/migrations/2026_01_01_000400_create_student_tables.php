<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4 : inscriptions et admissions.
 * Toutes les entites portent tenant_id + soft delete (jamais de suppression
 * physique, guide §0.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('matricule', 50);
            $table->string('first_name');
            $table->string('last_name');
            $table->date('date_of_birth');
            $table->string('place_of_birth')->nullable();
            $table->enum('gender', ['MALE', 'FEMALE']);
            $table->string('nationality')->default('Camerounaise');
            $table->string('photo_url')->nullable();
            $table->string('blood_group', 10)->nullable();
            $table->text('allergies')->nullable();
            $table->text('handicap')->nullable();
            $table->string('emergency_contact')->nullable();
            $table->enum('status', ['ACTIVE', 'SUSPENDED', 'GRADUATED', 'EXCLUDED', 'ABANDONED'])->default('ACTIVE');
            // Lien optionnel vers un compte de connexion (role Eleve) : permet de
            // scoper l'acces en lecture de l'eleve a ses seules donnees (anti-IDOR).
            $table->uuid('user_id')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'matricule']);
            $table->index(['tenant_id', 'last_name', 'first_name']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('user_id')->references('id')->on('users');
        });

        Schema::create('parents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone', 50);
            $table->string('whatsapp', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('profession')->nullable();
            $table->string('address')->nullable();
            $table->enum('relationship', ['FATHER', 'MOTHER', 'GUARDIAN']);
            // Lien optionnel vers un compte de connexion (role Parent) : permet de
            // scoper l'acces en lecture du parent a ses seuls enfants (anti-IDOR).
            $table->uuid('user_id')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('user_id')->references('id')->on('users');
        });

        // Liaison M:N eleve <-> parent : la relation (pere/mere/tuteur) est portee
        // par le lien, un meme parent pouvant etre pere d'un eleve et tuteur d'un autre.
        Schema::create('student_parents', function (Blueprint $table) {
            $table->uuid('student_id');
            $table->uuid('parent_id');
            $table->enum('relationship', ['FATHER', 'MOTHER', 'GUARDIAN']);

            $table->primary(['student_id', 'parent_id']);
            $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
            $table->foreign('parent_id')->references('id')->on('parents')->cascadeOnDelete();
        });

        Schema::create('enrollments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('student_id');
            $table->uuid('classroom_id');
            $table->uuid('academic_year_id');
            $table->timestamp('enrollment_date')->useCurrent();
            $table->enum('type', ['NEW', 'RE_ENROLLMENT', 'TRANSFER'])->default('NEW');
            $table->enum('status', ['PENDING', 'ACTIVE', 'CANCELLED'])->default('ACTIVE');
            $table->enum('regime', ['EXTERNAL', 'HALF_BOARD', 'BOARDING'])->default('EXTERNAL');
            $table->boolean('is_repeater')->default(false);
            $table->string('previous_school')->nullable();
            $table->double('previous_average')->nullable();
            $table->json('documents')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'classroom_id', 'status']);
            $table->index(['tenant_id', 'student_id', 'academic_year_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('student_id')->references('id')->on('students');
            $table->foreign('classroom_id')->references('id')->on('classrooms');
            $table->foreign('academic_year_id')->references('id')->on('academic_years');
        });

        Schema::create('admission_applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('applicant_name');
            $table->json('applicant_info')->nullable();
            $table->enum('type', ['EXAM', 'DOSSIER', 'DIRECT'])->default('DOSSIER');
            $table->double('score')->nullable();
            $table->integer('rank')->nullable();
            $table->enum('status', ['PENDING', 'ACCEPTED', 'REJECTED', 'WAITLISTED'])->default('PENDING');
            $table->uuid('academic_year_id');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('academic_year_id')->references('id')->on('academic_years');
        });

        // Compteur atomique de matricules par tenant + annee : incremente dans la
        // meme transaction que la creation de l'eleve (ni trou ni doublon).
        Schema::create('matricule_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('year', 10);
            $table->integer('last_number')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'year']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matricule_sequences');
        Schema::dropIfExists('admission_applications');
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('student_parents');
        Schema::dropIfExists('parents');
        Schema::dropIfExists('students');
    }
};
