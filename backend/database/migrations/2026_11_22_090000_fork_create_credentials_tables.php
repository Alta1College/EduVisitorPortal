<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visitor credentials such as a Working with Children Check or a Blue Card: one record per
 * person and credential type, checked by staff against the issuing body (docs/fork/credentials.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credential_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            // The issuing body's public validation page, opened for a manual check.
            $table->string('verification_url', 500)->nullable();
            // Re-check a valid credential after this many days (null: only when it expires).
            $table->unsignedSmallInteger('recheck_days')->nullable();
            $table->unsignedSmallInteger('expiry_warning_days')->default(30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('visitor_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visitor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credential_type_id')->constrained()->cascadeOnDelete();
            $table->text('number');                 // encrypted
            $table->string('number_hash', 64)->index(); // keyed hash for exact search
            $table->date('expires_on')->nullable();
            $table->string('status', 16)->default('unchecked');
            $table->timestamp('checked_at')->nullable();
            $table->foreignId('checked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('check_note', 255)->nullable();
            $table->string('source', 16);
            $table->timestamps();

            $table->unique(['visitor_id', 'credential_type_id']);
        });

        Schema::table('form_fields', function (Blueprint $table): void {
            $table->foreignId('credential_type_id')->nullable()->after('remember_for_returning')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('form_fields', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('credential_type_id');
        });
        Schema::dropIfExists('visitor_credentials');
        Schema::dropIfExists('credential_types');
    }
};
