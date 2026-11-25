<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use App\Support\ForkPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Existing installs: admin and receptionist can check credentials; admin can import them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate(ForkPermissions::CHECK_CREDENTIALS, 'web');
        Permission::findOrCreate(ForkPermissions::IMPORT_CREDENTIALS, 'web');

        Role::query()->whereIn('name', ['admin', 'receptionist'])->where('guard_name', 'web')->get()
            ->each(fn (Role $role) => $role->givePermissionTo(ForkPermissions::CHECK_CREDENTIALS));
        Role::query()->where('name', 'admin')->where('guard_name', 'web')->get()
            ->each(fn (Role $role) => $role->givePermissionTo(ForkPermissions::IMPORT_CREDENTIALS));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::query()->whereIn('name', [ForkPermissions::CHECK_CREDENTIALS, ForkPermissions::IMPORT_CREDENTIALS])->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
