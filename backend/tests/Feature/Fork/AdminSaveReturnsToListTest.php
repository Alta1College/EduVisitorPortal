<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use App\Filament\Resources\Sites\Pages\EditSite;
use App\Filament\Resources\Sites\SiteResource;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\PermissionHelper;
use Tests\TestCase;

/**
 * Saving an edit in the admin panel goes back to the list (panel resourceEditPageRedirect).
 */
class AdminSaveReturnsToListTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_user_returns_to_the_user_list(): void
    {
        $admin = (new PermissionHelper)->getSuperAdminUser();
        $user = (new PermissionHelper)->getUser();

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['first_name' => 'Renamed', 'password' => ''])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(UserResource::getUrl('index'));

        $this->assertSame('Renamed', $user->fresh()->first_name);
    }

    public function test_saving_a_site_returns_to_the_site_list(): void
    {
        $admin = (new PermissionHelper)->getSuperAdminUser();
        $site = Site::default();

        Livewire::actingAs($admin)
            ->test(EditSite::class, ['record' => $site->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(SiteResource::getUrl('index'));
    }
}
