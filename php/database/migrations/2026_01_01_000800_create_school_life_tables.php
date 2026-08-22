<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modules 9 a 18 : emplois du temps, presences, discipline, sante, vie
 * scolaire, bibliotheque, transport, cantine, patrimoine, RH.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Module 9 : emplois du temps
        Schema::create('timetable_slots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('academic_year_id');
            $table->uuid('classroom_id');
            $table->uuid('subject_id');
            $table->uuid('teacher_id');
            $table->uuid('room_id')->nullable();
            $table->enum('day_of_week', [
                'MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY',
            ]);
            $table->string('start_time', 10);
            $table->string('end_time', 10);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('academic_year_id')->references('id')->on('academic_years');
            $table->foreign('classroom_id')->references('id')->on('classrooms');
            $table->foreign('subject_id')->references('id')->on('subjects');
            $table->foreign('teacher_id')->references('id')->on('users');
            $table->foreign('room_id')->references('id')->on('rooms');
        });

        // Module 10 : presences
        Schema::create('attendances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('student_id');
            $table->uuid('classroom_id');
            $table->date('date');
            $table->enum('status', ['PRESENT', 'ABSENT', 'LATE', 'EXCUSED']);
            $table->text('reason')->nullable();
            $table->string('justified_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'classroom_id', 'date']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('student_id')->references('id')->on('students');
            $table->foreign('classroom_id')->references('id')->on('classrooms');
        });

        // Module 11 : discipline
        Schema::create('discipline_incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('student_id');
            $table->uuid('reported_by');
            $table->enum('type', [
                'RETARD', 'ABSENCE_INJUSTIFIEE', 'INSOLENCE', 'BAGARRE', 'TRICHERIE', 'AUTRE',
            ]);
            $table->text('description');
            $table->date('date');
            $table->enum('sanction', [
                'AVERTISSEMENT', 'BLAME', 'EXCLUSION_COURS', 'EXCLUSION_TEMPORAIRE',
                'CONVOCATION_PARENTS', 'AUTRE',
            ])->nullable();
            $table->text('sanction_details')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('student_id')->references('id')->on('students');
            $table->foreign('reported_by')->references('id')->on('users');
        });

        // Module 12 : sante
        Schema::create('health_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('student_id')->unique();
            $table->string('blood_type', 10)->nullable();
            $table->text('allergies')->nullable();
            $table->text('chronic_diseases')->nullable();
            $table->text('medications')->nullable();
            $table->text('vaccinations')->nullable();
            $table->string('emergency_contact')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'student_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('student_id')->references('id')->on('students');
        });

        // Module 13 : vie scolaire
        Schema::create('clubs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->uuid('supervisor_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('supervisor_id')->references('id')->on('users');
        });

        Schema::create('club_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('club_id');
            $table->uuid('student_id');
            $table->timestamp('joined_at')->useCurrent();

            $table->unique(['club_id', 'student_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('club_id')->references('id')->on('clubs')->cascadeOnDelete();
            $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
        });

        Schema::create('school_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->dateTime('start_date');
            $table->dateTime('end_date');
            $table->string('location')->nullable();
            $table->uuid('organizer_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('organizer_id')->references('id')->on('users');
        });

        // Module 14 : bibliotheque
        Schema::create('library_books', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('title');
            $table->string('author')->nullable();
            $table->string('isbn', 32)->nullable();
            $table->string('category', 100)->nullable();
            $table->integer('quantity')->default(1);
            $table->integer('available')->default(1);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        Schema::create('library_borrows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('book_id');
            $table->uuid('student_id');
            $table->date('borrow_date');
            $table->date('due_date');
            $table->date('return_date')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('book_id')->references('id')->on('library_books');
            $table->foreign('student_id')->references('id')->on('students');
        });

        // Module 15 : transport. transport_routes.vehicle_id est contraint apres
        // la creation de transport_vehicles (dependance circulaire de lecture).
        Schema::create('transport_vehicles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->integer('capacity');
            $table->string('status', 50)->default('ACTIVE');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        Schema::create('transport_routes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->uuid('vehicle_id')->nullable();
            $table->text('stops')->nullable();
            $table->text('schedule')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('vehicle_id')->references('id')->on('transport_vehicles');
        });

        Schema::create('transport_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('route_id');
            $table->uuid('student_id');
            $table->string('stop_name')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('route_id')->references('id')->on('transport_routes');
            $table->foreign('student_id')->references('id')->on('students');
        });

        // Module 16 : cantine
        Schema::create('catering_menus', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->date('date');
            $table->string('meal', 50);
            $table->text('items')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        // Module 17 : patrimoine
        Schema::create('assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->enum('category', ['MOBILIER', 'EQUIPEMENT', 'BATIMENT', 'VEHICULE']);
            $table->date('acquisition_date')->nullable();
            $table->double('acquisition_value')->nullable();
            $table->enum('status', ['ACTIF', 'ENDOMMAGE', 'HORS_SERVICE'])->default('ACTIF');
            $table->string('location')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        Schema::create('asset_maintenances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('asset_id');
            $table->date('date');
            $table->text('description');
            $table->double('cost')->nullable();
            $table->string('technician')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('asset_id')->references('id')->on('assets');
        });

        // Module 18 : RH et paie
        Schema::create('employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('position');
            $table->string('department')->nullable();
            $table->date('hire_date');
            $table->double('salary')->nullable();
            $table->string('status', 50)->default('ACTIVE');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('user_id')->references('id')->on('users');
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('employee_id');
            $table->date('start_date');
            $table->date('end_date');
            $table->text('reason');
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED'])->default('PENDING');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('employee_id')->references('id')->on('employees');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('asset_maintenances');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('catering_menus');
        Schema::dropIfExists('transport_subscriptions');
        Schema::dropIfExists('transport_routes');
        Schema::dropIfExists('transport_vehicles');
        Schema::dropIfExists('library_borrows');
        Schema::dropIfExists('library_books');
        Schema::dropIfExists('school_events');
        Schema::dropIfExists('club_members');
        Schema::dropIfExists('clubs');
        Schema::dropIfExists('health_records');
        Schema::dropIfExists('discipline_incidents');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('timetable_slots');
    }
};
