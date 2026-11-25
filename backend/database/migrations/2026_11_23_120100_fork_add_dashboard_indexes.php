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
 * Indexes for the admin dashboard counts (docs/fork/admin-dashboard.md): who is signed in now,
 * arrivals per day and pre-booked visits per site and day. Indexes only; no column changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_visitor', function (Blueprint $table): void {
            $table->index(['checked_out_at', 'checked_in_at'], 'visit_visitor_dashboard_on_site_index');
            $table->index('checked_in_at', 'visit_visitor_dashboard_checked_in_index');
        });

        Schema::table('visits', function (Blueprint $table): void {
            $table->index(['site_id', 'scheduled_from'], 'visits_dashboard_site_scheduled_index');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            $table->dropIndex('visits_dashboard_site_scheduled_index');
        });

        Schema::table('visit_visitor', function (Blueprint $table): void {
            $table->dropIndex('visit_visitor_dashboard_on_site_index');
            $table->dropIndex('visit_visitor_dashboard_checked_in_index');
        });
    }
};
