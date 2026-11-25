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
 * Fork: the sites the Entra host sync gave each account last time, so access is removed when a
 * site's Entra group mapping is removed (EntraHostSync::assignSites), not only when the person
 * leaves a group that is still mapped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('directory_accounts', function (Blueprint $table): void {
            $table->json('synced_site_ids')->nullable()->after('created_user');
        });
    }

    public function down(): void
    {
        Schema::table('directory_accounts', function (Blueprint $table): void {
            $table->dropColumn('synced_site_ids');
        });
    }
};
