<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PermissionHelper;
use Tests\TestCase;

/**
 * The `name` column holds the surname, so the admin panel shows "first_name name" instead.
 */
class UserDisplayNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_panel_name_is_the_full_name(): void
    {
        $user = (new PermissionHelper)->getUser();
        $user->forceFill(['first_name' => 'Ada', 'name' => 'Lovelace'])->save();

        $this->assertSame('Ada Lovelace', $user->fresh()->getFilamentName());
    }

    public function test_admin_panel_name_falls_back_to_the_email(): void
    {
        $user = (new PermissionHelper)->getUser();
        $user->forceFill(['first_name' => '', 'name' => ''])->save();

        $this->assertSame($user->email, $user->fresh()->getFilamentName());
    }

    public function test_sidebar_footer_shows_the_full_name(): void
    {
        $user = (new PermissionHelper)->getUser();
        $user->forceFill(['first_name' => 'Ada', 'name' => 'Lovelace'])->save();

        $this->actingAs($user->fresh());

        $html = view('filament.sidebar-footer')->render();

        $this->assertStringContainsString('<div class="custom-sb-name">Ada Lovelace</div>', $html);
    }
}
