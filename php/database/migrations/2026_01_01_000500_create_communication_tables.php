<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 8 : communication multicanal (guide §23).
 * Les preferences de canal vivent dans une table separee
 * (user_channel_preferences, relation 1:1) plutot que sur users.
 */
return new class extends Migration
{
    /** Canaux supportes, partages par les trois tables du module. */
    private const CHANNELS = ['EMAIL', 'SMS', 'WHATSAPP', 'PUSH', 'INTERNAL'];

    public function up(): void
    {
        Schema::create('communication_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 50);
            $table->string('name');
            $table->enum('channel', self::CHANNELS);
            $table->string('subject_fr')->nullable();
            $table->string('subject_en')->nullable();
            $table->text('body_fr');
            $table->text('body_en')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        Schema::create('communication_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            // null = message ad-hoc (pas de template)
            $table->uuid('template_id')->nullable();
            $table->enum('channel', self::CHANNELS);
            $table->uuid('recipient_user_id');
            $table->string('subject')->nullable();
            $table->text('body');
            $table->enum('status', ['PENDING', 'SENT', 'DELIVERED', 'FAILED'])->default('PENDING');
            $table->string('provider_message_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('template_id')->references('id')->on('communication_templates');
            $table->foreign('recipient_user_id')->references('id')->on('users');
        });

        Schema::create('user_channel_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id')->unique();
            $table->enum('preferred_channel', self::CHANNELS);
            $table->enum('fallback_channel', self::CHANNELS)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('user_id')->references('id')->on('users');
        });

        Schema::create('internal_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('sender_user_id');
            $table->uuid('recipient_user_id');
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('sender_user_id')->references('id')->on('users');
            $table->foreign('recipient_user_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_messages');
        Schema::dropIfExists('user_channel_preferences');
        Schema::dropIfExists('communication_messages');
        Schema::dropIfExists('communication_templates');
    }
};
