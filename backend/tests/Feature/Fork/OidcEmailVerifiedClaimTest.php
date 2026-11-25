<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use App\Auth\Sso\Facile\FacileOidcAuthenticator;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Which ID-token claims mark an SSO email as verified (sso.oidc.email_verified_claims).
 * Microsoft Entra ID sends the optional claim xms_edov instead of email_verified.
 */
class OidcEmailVerifiedClaimTest extends TestCase
{
    public function test_by_default_only_email_verified_counts(): void
    {
        $this->assertTrue($this->verified(['email_verified' => true]));
        $this->assertTrue($this->verified(['email_verified' => 'true']));
        $this->assertFalse($this->verified(['email_verified' => false]));
        $this->assertFalse($this->verified(['email_verified' => 'false']));
        $this->assertFalse($this->verified(['xms_edov' => true]));
        $this->assertFalse($this->verified([]));
    }

    public function test_entra_xms_edov_counts_when_configured(): void
    {
        config(['sso.oidc.email_verified_claims' => ['email_verified', 'xms_edov']]);

        $this->assertTrue($this->verified(['xms_edov' => true]));
        $this->assertTrue($this->verified(['email_verified' => true]));
        $this->assertFalse($this->verified(['xms_edov' => false]));
        $this->assertFalse($this->verified(['upn' => 'someone@school.test']));
    }

    public function test_an_empty_list_falls_back_to_email_verified(): void
    {
        config(['sso.oidc.email_verified_claims' => []]);

        $this->assertTrue($this->verified(['email_verified' => true]));
        $this->assertFalse($this->verified(['xms_edov' => true]));
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function verified(array $claims): bool
    {
        return (new ReflectionMethod(FacileOidcAuthenticator::class, 'emailVerified'))
            ->invoke(app(FacileOidcAuthenticator::class), $claims);
    }
}
