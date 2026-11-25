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
 * Per-host choice of notification channels (docs/fork/teams-notifications.md). Null means the
 * organisation default ("Host notifications" settings) applies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('notify_by_email')->nullable();
            $table->boolean('notify_by_teams')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['notify_by_email', 'notify_by_teams']);
        });
    }
};
