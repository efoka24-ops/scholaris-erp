<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 7 : gestion financiere (frais de scolarite, paiements).
 * fee_structures definit une grille tarifaire par niveau (level_id = null ->
 * s'applique a tous les niveaux de l'annee) et par annee academique ; ses
 * fee_installments sont les tranches affichees a titre indicatif (echeancier),
 * mais le paiement se fait toujours contre le solde global de la facture,
 * jamais tranche par tranche.
 *
 * Montants en double (Float cote Prisma) sauf payment_transactions, deja en
 * Decimal(12,2) dans le schema source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_structures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->uuid('level_id')->nullable();
            $table->uuid('academic_year_id');
            $table->double('total_amount');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'academic_year_id', 'level_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('level_id')->references('id')->on('levels');
            $table->foreign('academic_year_id')->references('id')->on('academic_years');
        });

        Schema::create('fee_installments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('fee_structure_id');
            $table->string('label');
            $table->double('amount');
            $table->date('due_date');
            $table->integer('order');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'fee_structure_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('fee_structure_id')->references('id')->on('fee_structures');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('student_id');
            $table->uuid('enrollment_id');
            $table->uuid('fee_structure_id');
            $table->uuid('academic_year_id');
            $table->double('total_amount');
            $table->double('paid_amount')->default(0);
            $table->double('balance');
            // Echeance retenue pour le calcul du statut OVERDUE : la plus tardive
            // des tranches de la grille au moment de la generation (null si la
            // grille n'a aucune tranche definie).
            $table->date('due_date')->nullable();
            $table->enum('status', ['PENDING', 'PARTIAL', 'PAID', 'OVERDUE'])->default('PENDING');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'student_id', 'status']);
            $table->index(['tenant_id', 'enrollment_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('student_id')->references('id')->on('students');
            $table->foreign('enrollment_id')->references('id')->on('enrollments');
            $table->foreign('fee_structure_id')->references('id')->on('fee_structures');
            $table->foreign('academic_year_id')->references('id')->on('academic_years');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('invoice_id');
            $table->uuid('student_id');
            $table->double('amount');
            $table->enum('method', ['CASH', 'MOBILE_MONEY', 'BANK_TRANSFER', 'CHECK']);
            $table->string('reference')->nullable();
            $table->string('receipt_number', 50);
            $table->timestamp('paid_at')->useCurrent();
            $table->uuid('received_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'receipt_number']);
            $table->index(['tenant_id', 'invoice_id']);
            $table->index(['tenant_id', 'student_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('invoice_id')->references('id')->on('invoices');
            $table->foreign('student_id')->references('id')->on('students');
            $table->foreign('received_by')->references('id')->on('users');
        });

        Schema::create('discounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            // Au moins l'un des deux est renseigne (validation cote service) :
            // invoice_id applique immediatement la reduction a cette facture ;
            // student_id seul enregistre une bourse generale sans impact immediat.
            $table->uuid('student_id')->nullable();
            $table->uuid('invoice_id')->nullable();
            $table->enum('type', ['PERCENTAGE', 'FIXED']);
            $table->double('value');
            $table->string('reason')->nullable();
            $table->uuid('approved_by');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'student_id']);
            $table->index(['tenant_id', 'invoice_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('student_id')->references('id')->on('students');
            $table->foreign('invoice_id')->references('id')->on('invoices');
            $table->foreign('approved_by')->references('id')->on('users');
        });

        // Compteur atomique de numeros de recu par tenant + annee (meme pattern
        // que matricule_sequences) : incremente dans la meme transaction que la
        // creation du paiement, deux paiements concurrents ne peuvent jamais
        // obtenir le meme numero.
        Schema::create('receipt_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('year', 10);
            $table->integer('last_number')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'year']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        // Passerelles Mobile Money (CAMOO / apisungku). Table volontairement
        // denormalisee (FK scalaires, pas de contraintes) pour rester isolee
        // des modeles tenant/student/invoice.
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('gateway_id')->nullable();
            $table->string('external_reference')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 10)->default('XAF');
            $table->string('phone_number', 50);
            $table->string('network', 50)->nullable();
            $table->string('status', 50)->default('PENDING');
            $table->decimal('fees', 12, 2)->nullable();
            $table->decimal('net_amount', 12, 2)->nullable();
            $table->uuid('student_id')->nullable();
            $table->uuid('invoice_id')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('gateway_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('receipt_sequences');
        Schema::dropIfExists('discounts');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('fee_installments');
        Schema::dropIfExists('fee_structures');
    }
};
