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
 * Emergency messages sent when a roll call starts (docs/fork/emergency-broadcast.md): one row
 * per person and channel with the outcome shown on the roll call board. Only a masked address is
 * stored; the message goes to the visitor's current contact details.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roll_calls', function (Blueprint $table): void {
            // Set when messages were requested for this roll call (real emergency, or a drill with messages).
            $table->timestamp('broadcast_at')->nullable()->after('kind');
        });

        Schema::create('emergency_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('roll_call_id')->constrained('roll_calls')->cascadeOnDelete();
            $table->foreignId('roll_call_entry_id')->nullable()->constrained('roll_call_entries')->cascadeOnDelete();
            $table->foreignId('visitor_id')->nullable()->constrained('visitors')->nullOnDelete();
            $table->string('channel', 10); // sms | email
            $table->string('to_masked', 64)->nullable();
            // SMS: our correlation id sent to the provider and echoed in delivery receipts.
            $table->string('reference', 64)->nullable()->unique();
            $table->string('provider_message_id', 64)->nullable();
            // pending | accepted | sent | delivered | failed | no_mobile | no_email
            $table->string('status', 20);
            $table->string('error_code', 64)->nullable();
            $table->timestamps();

            $table->unique(['roll_call_entry_id', 'channel']);
            $table->index(['roll_call_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_notifications');

        Schema::table('roll_calls', function (Blueprint $table): void {
            $table->dropColumn('broadcast_at');
        });
    }
};
