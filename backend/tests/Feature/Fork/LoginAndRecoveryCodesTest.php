<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use App\Support\AppMfaPolicy;
use App\Support\SensitiveActionConfirmation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Tests\Support\PermissionHelper;
use Tests\TestCase;

/**
 * The login e-mail placeholder is a setting (sso.login_email_placeholder), and the recovery-code
 * screens offer a .txt download of the codes they show.
 */
class LoginAndRecoveryCodesTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_placeholder_defaults_to_the_upstream_example(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('placeholder="max.mustermann@example.com"', false);
    }

    public function test_login_placeholder_can_be_set_per_instance(): void
    {
        config(['sso.login_email_placeholder' => 'first.last@school.example']);

        $this->get('/login')
            ->assertOk()
            ->assertSee('placeholder="first.last@school.example"', false)
            ->assertDontSee('max.mustermann@example.com');
    }

    public function test_profile_recovery_codes_page_offers_a_text_download_of_the_codes(): void
    {
        $user = (new PermissionHelper)->getUser();
        app(EnableTwoFactorAuthentication::class)($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $codes = $user->fresh()->recoveryCodes();

        $response = $this
            ->actingAs($user)
            ->withSession([
                'security.step_up.confirmed_at' => now()->timestamp,
                'security.step_up.method' => 'totp',
                'security.step_up.confirmed_for' => SensitiveActionConfirmation::PURPOSE_RECOVERY_CODES_VIEW,
            ])
            ->get(route('profile.security.recovery-codes'))
            ->assertOk()
            ->assertSee('data-recovery-codes-download', false)
            ->assertSee(__('Als Textdatei herunterladen'))
            ->assertSee('-recovery-codes.txt', false);

        $content = $this->downloadContent($response->getContent());

        foreach ($codes as $code) {
            $this->assertStringContainsString($code, $content);
        }
        $this->assertStringContainsString($user->email, $content);
        $this->assertStringContainsString(__('Jeder Code kann nur einmal verwendet werden.'), $content);
    }

    public function test_download_button_is_hidden_when_there_are_no_codes(): void
    {
        $this->blade('<x-recovery-codes-download :codes="$codes" />', ['codes' => []])
            ->assertDontSee('data-recovery-codes-download', false);
    }

    public function test_microsoft_sign_in_can_skip_app_mfa_for_the_admin_panel(): void
    {
        $admin = (new PermissionHelper)->getSuperAdminUser();
        $policy = app(AppMfaPolicy::class);

        $this->assertTrue($policy->isRequiredForAdminPanel($admin, 'sso'));

        config(['security.mfa.app_required_for_admin_panel_auth_methods' => ['local']]);

        $this->assertFalse($policy->isRequiredForAdminPanel($admin, 'sso'));
        $this->assertTrue($policy->isRequiredForAdminPanel($admin, 'local'));
    }

    private function downloadContent(string $html): string
    {
        $this->assertSame(1, preg_match('/data-content="([^"]*)"/', $html, $matches));

        return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
    }
}
