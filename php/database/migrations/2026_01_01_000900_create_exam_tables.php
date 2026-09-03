<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Examens officiels (CEP / BEPC / Probatoire / BAC + configurables) et demandes
 * de creation d'etablissement.
 *
 * Les tables d'examens utilisent des FK scalaires vers tenants/students/levels/
 * academic_years (pas de contrainte) pour isoler le module ; le scoping tenant
 * est assure par le scope global applicatif.
 *
 * establishment_requests n'est pas tenant-scopee : un directeur soumet une
 * demande publique (sans authentification), le Super Admin la valide, ce qui
 * cree le tenant + le compte directeur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('official_exams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->enum('code', ['CEP', 'BEPC', 'PROBATOIRE', 'BAC', 'CUSTOM']);
            $table->uuid('level_id')->nullable();
            $table->uuid('academic_year_id');
            $table->dateTime('registration_start');
            $table->dateTime('registration_end');
            $table->dateTime('exam_start')->nullable();
            $table->dateTime('exam_end')->nullable();
            $table->decimal('fee_amount', 12, 2)->default(0);
            $table->integer('min_age')->nullable();
            $table->integer('max_age')->nullable();
            $table->integer('required_sequences')->default(0);
            $table->decimal('pass_mark', 5, 2)->default(10);
            $table->decimal('oral_min_mark', 5, 2)->nullable();
            $table->boolean('is_official')->default(true);
            $table->json('config_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'academic_year_id', 'code', 'name'], 'official_exams_unique');
            $table->index('tenant_id');
        });

        Schema::create('exam_registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('exam_id');
            $table->uuid('student_id');
            $table->string('registration_number', 50);
            $table->string('center_code', 50)->nullable();
            $table->string('center_name')->nullable();
            $table->string('series', 50)->nullable();
            $table->enum('status', ['PENDING', 'VALIDATED', 'REJECTED', 'ABSENT', 'PASSED', 'FAILED'])
                ->default('PENDING');
            $table->boolean('fee_paid')->default(false);
            $table->decimal('average', 5, 2)->nullable();
            $table->string('mention', 50)->nullable();
            $table->integer('rank')->nullable();
            $table->timestamp('registered_at')->useCurrent();
            $table->uuid('validated_by')->nullable();
            $table->timestamps();

            $table->unique(['exam_id', 'student_id']);
            $table->index('tenant_id');
            $table->foreign('exam_id')->references('id')->on('official_exams')->cascadeOnDelete();
        });

        Schema::create('exam_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('registration_id');
            $table->string('subject');
            $table->decimal('coefficient', 5, 2)->default(1);
            $table->decimal('mark', 5, 2)->nullable();
            $table->boolean('is_absent')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index('tenant_id');
            $table->index('registration_id');
            $table->foreign('registration_id')->references('id')->on('exam_registrations')->cascadeOnDelete();
        });

        Schema::create('establishment_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('code', 50);
            $table->enum('type', ['PRIMAIRE', 'SECONDAIRE', 'SUPERIEUR', 'TECHNIQUE', 'FORMATION_PRO']);
            $table->enum('status', ['PUBLIC', 'PRIVE'])->default('PUBLIC');
            $table->string('address')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('director_first_name');
            $table->string('director_last_name');
            $table->string('director_email');
            $table->string('director_phone', 50)->nullable();
            $table->enum('request_status', ['PENDING', 'APPROVED', 'REJECTED'])->default('PENDING');
            $table->text('rejection_reason')->nullable();
            $table->uuid('created_tenant_id')->nullable();
            $table->timestamps();

            $table->index('request_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('establishment_requests');
        Schema::dropIfExists('exam_results');
        Schema::dropIfExists('exam_registrations');
        Schema::dropIfExists('official_exams');
    }
};
