<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use App\PublicVisit\ReturningVisitors;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fork: indexed last nine digits of the visitor's phone number, so a returning visitor is found
 * by mobile number without scanning the table (ReturningVisitors::find). Kept up to date when a
 * visitor is saved (PublicVisitServiceProvider).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $table): void {
            $table->string('phone_tail', 9)->nullable()->after('phone')->index();
        });

        DB::table('visitors')->whereNotNull('phone')->select(['id', 'phone'])->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('visitors')->where('id', $row->id)->update(['phone_tail' => ReturningVisitors::phoneTail($row->phone)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table): void {
            $table->dropIndex(['phone_tail']);
            $table->dropColumn('phone_tail');
        });
    }
};
