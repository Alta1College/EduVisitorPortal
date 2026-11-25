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
 * Badge favicon and the portal header showing only the logo.
 */
class BrandIconTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_link_the_svg_favicon_and_the_files_exist(): void
    {
        $this->get('/login')->assertOk()->assertSee('favicon.svg', false);

        foreach (['favicon.svg', 'favicon.ico', 'favicon-16x16.png', 'favicon-32x32.png', 'apple-touch-icon.png', 'android-chrome-192x192.png', 'android-chrome-512x512.png'] as $file) {
            $this->assertFileExists(public_path($file));
        }

        $this->assertSame('Edu Visitor Portal', json_decode((string) file_get_contents(public_path('site.webmanifest')), true)['name']);
    }

    public function test_the_portal_header_shows_only_the_logo(): void
    {
        $user = (new PermissionHelper)->getUser();

        $this->actingAs($user)->get(route('overview'))
            ->assertOk()
            ->assertSee('images/branding/logo-with-text-light.svg', false)
            ->assertDontSee('<p class="truncate text-sm font-semibold">', false)
            ->assertDontSee('<h1 class="truncate text-base font-semibold tracking-tight">', false);
    }

    public function test_without_a_logo_the_header_falls_back_to_the_name(): void
    {
        config(['branding.logo_light' => null, 'branding.logo_dark' => null, 'branding.name' => 'Riverside College']);
        $user = (new PermissionHelper)->getUser();

        $this->actingAs($user)->get(route('overview'))->assertOk()->assertSee('Riverside College');
    }
}
