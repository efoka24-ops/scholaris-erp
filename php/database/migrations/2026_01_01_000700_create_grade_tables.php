<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modules 5 et 6 : saisie des notes, moteur de calcul, bulletins.
 * grades (note unitaire) -> grade_calculations (moyenne matiere/EC sur une
 * periode) -> period_results (moyenne generale + rang + deliberation) ->
 * annual_results (bilan annuel : moyenne, rang, mention, decision, GPA LMD).
 * Le moteur de calcul lui-meme est un service sans etat qui consomme la
 * configuration tenants.config_json.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grades', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('student_id');
            // Exactement l'un des deux est renseigne : subject_id (primaire/
            // secondaire) OU course_element_id (superieur LMD).
            $table->uuid('subject_id')->nullable();
            $table->uuid('course_element_id')->nullable();
            $table->uuid('period_id');
            $table->uuid('teacher_id');
            $table->enum('type', ['TEST', 'HOMEWORK', 'EXAM', 'RESIT'])->default('TEST');
            // Note brute sur max_value ; null si is_absent et qu'aucune note de
            // substitution n'a ete saisie.
            $table->decimal('value', 5, 2)->nullable();
            $table->decimal('max_value', 5, 2)->default(20);
            // Ponderation de cette evaluation dans la moyenne de la periode.
            $table->decimal('weight', 5, 2)->default(1);
            $table->timestamp('date')->useCurrent();
            $table->text('comment')->nullable();
            $table->boolean('is_absent')->default(false);
            $table->boolean('is_justified')->default(false);
            // Verrouillee par l'enseignant : non modifiable sauf deverrouillage
            // explicite (censeur/admin, permission grades:unlock).
            $table->boolean('is_locked')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'student_id', 'period_id']);
            $table->index(['tenant_id', 'subject_id', 'period_id']);
            $table->index(['tenant_id', 'course_element_id', 'period_id']);
            $table->index(['tenant_id', 'period_id', 'is_locked']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('student_id')->references('id')->on('students');
            $table->foreign('subject_id')->references('id')->on('subjects');
            $table->foreign('course_element_id')->references('id')->on('course_elements');
            $table->foreign('period_id')->references('id')->on('periods');
            $table->foreign('teacher_id')->references('id')->on('users');
        });

        // Moyenne calculee (matiere ou EC) d'un eleve sur une periode.
        // classroom_id est denormalise depuis l'inscription active de l'eleve au
        // moment du calcul, pour un classement par classe sans re-parcourir les
        // inscriptions.
        Schema::create('grade_calculations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('student_id');
            $table->uuid('period_id');
            $table->uuid('subject_id')->nullable();
            $table->uuid('course_element_id')->nullable();
            $table->uuid('classroom_id');
            $table->decimal('calculated_average', 5, 2);
            $table->decimal('coefficient', 5, 2)->default(1);
            $table->decimal('weighted_total', 8, 2);
            // Rang dans la matiere/EC au sein de la classe (ex aequo => meme rang).
            $table->integer('rank')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // MySQL n'applique pas l'unicite quand une colonne est NULL : le
            // service recalcule (upsert manuel) plutot que de s'appuyer
            // uniquement sur la contrainte.
            $table->unique(
                ['tenant_id', 'student_id', 'period_id', 'subject_id', 'course_element_id'],
                'gc_unique'
            );
            $table->index(['tenant_id', 'classroom_id', 'period_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('student_id')->references('id')->on('students');
            $table->foreign('period_id')->references('id')->on('periods');
            $table->foreign('subject_id')->references('id')->on('subjects');
            $table->foreign('course_element_id')->references('id')->on('course_elements');
            $table->foreign('classroom_id')->references('id')->on('classrooms');
        });

        // Bilan d'un eleve sur une periode. is_published controle la visibilite
        // pour les parents et les eleves.
        Schema::create('period_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('student_id');
            $table->uuid('period_id');
            $table->uuid('classroom_id');
            $table->decimal('general_average', 5, 2);
            $table->integer('rank')->nullable();
            $table->integer('total_students')->nullable();
            $table->string('mention', 50)->nullable();
            // Decision de la deliberation (texte libre : "Admis(e)", "Avertissement"...).
            $table->string('decision')->nullable();
            $table->text('observations')->nullable();
            $table->text('teacher_comment')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'student_id', 'period_id']);
            $table->index(['tenant_id', 'classroom_id', 'period_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('student_id')->references('id')->on('students');
            $table->foreign('period_id')->references('id')->on('periods');
            $table->foreign('classroom_id')->references('id')->on('classrooms');
        });

        Schema::create('annual_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('student_id');
            $table->uuid('classroom_id');
            $table->uuid('academic_year_id');
            $table->decimal('annual_average', 5, 2);
            $table->integer('rank')->nullable();
            $table->string('mention', 50)->nullable();
            $table->enum('decision', ['PASS', 'REPEAT', 'EXCLUDE'])->nullable();
            // LMD uniquement : credits valides / GPA (echelle configurable via
            // calculationEngineSchema.gpaScale).
            $table->integer('credits_validated')->nullable();
            $table->decimal('gpa', 4, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'student_id', 'academic_year_id']);
            $table->index(['tenant_id', 'classroom_id', 'academic_year_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('student_id')->references('id')->on('students');
            $table->foreign('classroom_id')->references('id')->on('classrooms');
            $table->foreign('academic_year_id')->references('id')->on('academic_years');
        });

        // Bulletin scolaire, genere apres calcul des moyennes d'une periode.
        Schema::create('bulletins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('student_id');
            $table->uuid('period_id');
            $table->uuid('classroom_id');
            $table->string('verification_code', 64)->unique();
            // draft | published | sent
            $table->string('status', 20)->default('draft');
            $table->string('pdf_url')->nullable();
            // Snapshot des notes, moyennes, etc.
            $table->json('data');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'student_id', 'period_id']);
            $table->index(['tenant_id', 'classroom_id', 'period_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('student_id')->references('id')->on('students');
            $table->foreign('period_id')->references('id')->on('periods');
            $table->foreign('classroom_id')->references('id')->on('classrooms');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulletins');
        Schema::dropIfExists('annual_results');
        Schema::dropIfExists('period_results');
        Schema::dropIfExists('grade_calculations');
        Schema::dropIfExists('grades');
    }
};
