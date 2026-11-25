<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Credentials;

use App\Credentials\Livewire\CredentialsPage;
use App\Support\ForkPermissions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Visitor credentials such as Working with Children Checks (docs/fork/credentials.md).
 */
class CredentialsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(CredentialType::class, CredentialTypePolicy::class);
        Livewire::component('credentials.page', CredentialsPage::class);

        Route::middleware(['web', 'auth', 'verified', 'can:'.ForkPermissions::CHECK_CREDENTIALS])
            ->prefix('reception')->name('reception.')
            ->group(function (): void {
                Route::view('/credentials', 'credentials.index')->name('credentials');
            });
    }
}
