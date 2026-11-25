<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use App\Filament\LocalInitialsAvatarProvider;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Departments\DepartmentResource;
use App\Filament\Widgets\DashboardWelcomeWidget;
use Filament\Facades\Filament;
use Filament\Widgets\AccountWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PermissionHelper;
use Tests\TestCase;

/**
 * Departments switch, local admin avatars and the admin start page without the GitHub link.
 * (The credentials-page duplicate fix is covered in CredentialsTest.)
 */
class LowRiskBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_departments_are_hidden_by_default(): void
    {
        $admin = (new PermissionHelper)->getSuperAdminUser();
        $this->actingAs($admin);

        $this->assertFalse(config('visitorportal.departments.enabled'));
        $this->assertFalse(DepartmentResource::canAccess());
        $this->assertFalse(DepartmentResource::shouldRegisterNavigation());
    }

    public function test_departments_can_be_switched_back_on(): void
    {
        config(['visitorportal.departments.enabled' => true]);
        $admin = (new PermissionHelper)->getSuperAdminUser();
        $this->actingAs($admin);

        $this->assertTrue(DepartmentResource::canAccess());
    }

    public function test_admin_avatars_are_drawn_locally(): void
    {
        $admin = (new PermissionHelper)->getSuperAdminUser();
        $admin->forceFill(['first_name' => 'Ada', 'name' => 'Lovelace'])->save();

        $url = (new LocalInitialsAvatarProvider)->get($admin->fresh());

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $url);
        $this->assertStringContainsString('>AL<', base64_decode(substr($url, strlen('data:image/svg+xml;base64,'))));
        $this->assertStringNotContainsString('ui-avatars', $url);
        $this->assertSame(LocalInitialsAvatarProvider::class, Filament::getPanel('admin')->getDefaultAvatarProvider());
    }

    public function test_admin_start_page_has_no_github_link(): void
    {
        $this->assertFileDoesNotExist(app_path('Filament/Widgets/GithubLinkWidget.php'));
        // The AccountWidget placeholder gave way to the admin dashboard (docs/fork/admin-dashboard.md).
        $this->assertNotContains(AccountWidget::class, Filament::getPanel('admin')->getWidgets());
        $this->assertContains(DashboardWelcomeWidget::class, (new Dashboard)->getWidgets());
    }
}
