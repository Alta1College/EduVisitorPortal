<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use App\Models\User;
use App\Support\AppMfaPolicy;
use App\Support\VisitorPortalPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * APP_MFA_REQUIRED_ROLES=* : MFA for every password sign-in, never after SSO.
 */
class MfaForEveryoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_wildcard_requires_mfa_for_every_role_on_password_sign_in_only(): void
    {
        VisitorPortalPermissions::sync();
        config([
            'security.mfa.enabled' => true,
            'security.mfa.app_required_roles' => ['*'],
            'security.mfa.app_required_for_auth_methods' => ['local'],
            'security.mfa.app_required_for_admin_panel_auth_methods' => ['local'],
        ]);

        $host = User::factory()->create();
        $host->assignRole('user');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $policy = app(AppMfaPolicy::class);

        foreach ([$host, $admin] as $user) {
            $this->assertTrue($policy->isRequiredForAuthMethod($user, 'local'));
            $this->assertFalse($policy->isRequiredForAuthMethod($user, 'sso'));
            $this->assertFalse($policy->isRequiredForAdminPanel($user, 'sso'));
        }

        $this->assertTrue($policy->isRequiredForAdminPanel($admin, 'local'));

        // Without the wildcard, only the listed roles.
        config(['security.mfa.app_required_roles' => ['admin', 'super_admin']]);
        $this->assertFalse($policy->isRequiredForAuthMethod($host, 'local'));
        $this->assertTrue($policy->isRequiredForAuthMethod($admin, 'local'));
    }
}
