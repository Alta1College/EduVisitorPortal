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
 * Local users kept in sync with an external directory (Microsoft Entra). created_user marks
 * accounts the sync created, the only ones it may deactivate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directory_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('external_id', 64);
            $table->boolean('created_user')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
            $table->unique(['provider', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_accounts');
    }
};
