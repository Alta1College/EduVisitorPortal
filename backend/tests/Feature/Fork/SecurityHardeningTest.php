<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use App\Mail\Host\GuestCheckedInMail;
use App\Models\Visitor;
use App\Support\MarkdownText;
use App\Support\RequestTempFiles;
use Carbon\CarbonInterval as Duration;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Tests\Support\PermissionHelper;
use Tests\TestCase;

/**
 * Phase 11 security validation fixes (docs/fork/phase-11-security-validation.md).
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_deactivated_user_is_signed_out_on_the_next_request(): void
    {
        $user = (new PermissionHelper)->getUser();
        $this->actingAs($user)->get(route('overview'))->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->get(route('overview'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_deactivation_rotates_the_remember_me_token(): void
    {
        $user = (new PermissionHelper)->getUser();
        $user->forceFill(['remember_token' => 'before'])->save();

        $user->forceFill(['is_active' => false])->save();

        $this->assertNotSame('before', $user->fresh()->remember_token);
    }

    public function test_password_reset_gives_the_same_answer_for_unknown_and_known_addresses(): void
    {
        Notification::fake();
        $user = (new PermissionHelper)->getUser();

        $known = $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email]);
        $unknown = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'nobody@example.test']);

        $known->assertSessionHas('status', __('passwords.sent'))->assertSessionHasNoErrors();
        $unknown->assertSessionHas('status', __('passwords.sent'))->assertSessionHasNoErrors();
        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertCount(1);
    }

    public function test_password_reset_requests_are_rate_limited_without_saying_so(): void
    {
        Notification::fake();
        config(['auth.password_reset_rate_limit.max_attempts' => 2]);
        $user = (new PermissionHelper)->getUser();

        foreach (range(1, 4) as $attempt) {
            $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email])
                ->assertSessionHas('status', __('passwords.sent'))
                ->assertSessionHasNoErrors();
        }

        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    public function test_a_wrong_login_for_an_unknown_address_still_takes_the_minimum_time(): void
    {
        // A long minimum, so even a slow test machine is far from using it up before the wait.
        config(['auth.login_minimum_failure_microseconds' => 5_000_000]);
        Sleep::fake();

        $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        // Timebox waits for the rest of the minimum time (most of it, as no password was hashed).
        Sleep::assertSlept(fn (Duration $duration) => $duration->totalMicroseconds > 4_000_000, 1);
    }

    public function test_a_successful_login_does_not_wait(): void
    {
        $user = (new PermissionHelper)->getUser();
        Sleep::fake();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
        // Fortify (1.43+) times its own password check at about 0.2 s for success and failure
        // alike; what must not happen is our longer failure minimum on a successful login.
        Sleep::assertSlept(fn ($duration): bool => $duration->totalMicroseconds >= 1_000_000, 0);
    }

    public function test_a_deactivated_account_gets_the_generic_login_error(): void
    {
        $user = (new PermissionHelper)->getUser();
        $user->forceFill(['is_active' => false])->save();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => __('auth.failed')]);
        $this->assertGuest();
    }

    public function test_two_factor_attempts_are_also_limited_per_account(): void
    {
        $request = Request::create('/two-factor-challenge', 'POST');
        $request->setLaravelSession(app('session.store'));
        $limits = RateLimiter::limiter('two-factor')($request);

        $this->assertIsArray($limits);
        $this->assertCount(2, $limits);
        $this->assertContainsOnlyInstancesOf(Limit::class, $limits);
        $this->assertStringStartsWith('two-factor-account:', $limits[1]->key);
    }

    public function test_admin_panel_responses_carry_the_security_headers(): void
    {
        $admin = (new PermissionHelper)->getSuperAdminUser();

        $this->actingAs($admin)->get('/admin')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy');
    }

    public function test_visitor_text_cannot_add_links_to_markdown_emails(): void
    {
        $this->assertSame('\[Click\]\(https://evil\.example\)', MarkdownText::escape('[Click](https://evil.example)'));
        $this->assertSame('Smith\-Jones a\|b', MarkdownText::escape("Smith-Jones\na|b"));

        $host = (new PermissionHelper)->getUser();
        $visitor = Visitor::factory()->create([
            'first_name' => '[Click here](https://evil.example)',
            'name' => 'Pipe|Name',
            'company' => '**Bold**',
        ]);

        $html = (new GuestCheckedInMail($host, $visitor))->render();

        $this->assertStringNotContainsString('href="https://evil.example"', $html);
        $this->assertStringContainsString('[Click here](https://evil.example)', html_entity_decode($html));
        $this->assertStringNotContainsString('<strong>Bold</strong>', $html);
    }

    public function test_request_temp_files_are_deleted_when_the_request_ends(): void
    {
        $path = RequestTempFiles::write('test', 'data');
        $this->assertFileExists($path);

        RequestTempFiles::cleanUp();

        $this->assertFileDoesNotExist($path);
    }

    public function test_temporary_uploads_have_a_tighter_rate_limit(): void
    {
        $this->assertSame('throttle:15,1', config('livewire.temporary_file_upload.middleware'));
    }
}
