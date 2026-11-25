<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use App\Enums\VisitStatusEnum;
use App\HostDirectory\Entra\DirectoryAccount;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\Notifications\Host\GuestCheckedInMailNotification;
use App\Notifications\Host\VisitCreated;
use App\Notifications\Host\VisitReminderDaily;
use App\Notifications\HostNotificationChannels;
use App\Settings\SettingsRepository;
use App\Teams\TeamsActivity;
use App\Teams\TeamsActivityChannel;
use App\Teams\TeamsNotificationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PermissionHelper;
use Tests\TestCase;
use ZipArchive;

class TeamsNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private const APP_ID = '0f8e3a52-7c1d-4b6e-9a2f-5d3c1b7e8a90';

    private const CLIENT_ID = '6a1b2c3d-4e5f-4a7b-8c9d-0e1f2a3b4c5d';

    private const TOKEN_URL = 'https://login.microsoftonline.com/tenant-1/oauth2/v2.0/token';

    private const OBJECT_ID = '11111111-2222-4333-8444-555555555555';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'teams.enabled' => true,
            'teams.app_id' => self::APP_ID,
            'teams.tenant_id' => 'tenant-1',
            'teams.client_id' => self::CLIENT_ID,
            'teams.client_secret' => 'secret-value',
            'teams.authority' => 'https://login.microsoftonline.com',
            'teams.graph_endpoint' => 'https://graph.microsoft.com',
            'organisation.host_notifications.email' => null,
            'organisation.host_notifications.teams' => null,
        ]);
    }

    public function test_channels_follow_the_organisation_defaults(): void
    {
        $host = User::factory()->create();

        $this->assertSame(['mail'], HostNotificationChannels::for($host));

        config(['organisation.host_notifications.teams' => '1']);
        $this->assertSame(['mail', TeamsActivityChannel::class], HostNotificationChannels::for($host));

        config(['organisation.host_notifications.email' => '0']);
        $this->assertSame([TeamsActivityChannel::class], HostNotificationChannels::for($host));
    }

    public function test_organisation_defaults_are_admin_settings(): void
    {
        $host = User::factory()->create();

        app(SettingsRepository::class)->saveOrganisation([
            'organisation.host_notifications.email' => '0',
            'organisation.host_notifications.teams' => '1',
        ], null);
        app(SettingsRepository::class)->applyOverlay();

        $this->assertSame([TeamsActivityChannel::class], HostNotificationChannels::for($host));
    }

    public function test_host_choice_overrides_the_defaults(): void
    {
        config(['organisation.host_notifications.teams' => '1']);
        $host = User::factory()->create();
        $host->forceFill(['notify_by_email' => true, 'notify_by_teams' => false])->save();

        $this->assertSame(['mail'], HostNotificationChannels::for($host->fresh()));

        $host->forceFill(['notify_by_email' => false, 'notify_by_teams' => true])->save();
        $this->assertSame([TeamsActivityChannel::class], HostNotificationChannels::for($host->fresh()));
    }

    public function test_teams_is_never_used_while_it_is_not_set_up(): void
    {
        $host = User::factory()->create();
        $host->forceFill(['notify_by_teams' => true])->save();

        config(['teams.enabled' => false]);
        $this->assertSame(['mail'], HostNotificationChannels::for($host->fresh()));

        config(['teams.enabled' => true, 'teams.app_id' => null]);
        $this->assertSame(['mail'], HostNotificationChannels::for($host->fresh()));
    }

    public function test_nothing_is_sent_when_both_channels_are_off(): void
    {
        Mail::fake();
        Http::fake();
        [$visit, $visitor] = $this->visitWithVisitor();
        $visit->host->forceFill(['notify_by_email' => false, 'notify_by_teams' => false])->save();

        $this->assertSame([], HostNotificationChannels::for($visit->host->fresh()));

        $visit->host->fresh()->notifyNow(new GuestCheckedInMailNotification($visit, $visitor));
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_arrival_alert_goes_to_the_entra_object_id_with_the_expected_body(): void
    {
        $this->fakeGraph(204);
        [$visit, $visitor] = $this->visitWithVisitor();
        DirectoryAccount::query()->create(['user_id' => $visit->host_user_id, 'provider' => 'entra', 'external_id' => self::OBJECT_ID]);

        (new TeamsActivityChannel)->send($visit->host, new GuestCheckedInMailNotification($visit, $visitor));

        $sent = Http::recorded(fn (Request $r): bool => str_contains($r->url(), 'sendActivityNotification'))->values();
        $this->assertCount(1, $sent);
        [$request] = $sent[0];

        $this->assertSame('https://graph.microsoft.com/v1.0/users/'.self::OBJECT_ID.'/teamwork/sendActivityNotification', $request->url());
        $this->assertTrue($request->hasHeader('Authorization', 'Bearer graph-token'));
        $this->assertSame('POST', $request->method());
        $this->assertSame('text', $request['topic']['source']);
        $this->assertSame(
            'https://teams.microsoft.com/l/entity/'.self::APP_ID.'/visits?context='.rawurlencode('{"subEntityId":"'.$visit->id.'"}'),
            $request['topic']['webUrl'],
        );
        $this->assertSame(TeamsActivity::VISITOR_ARRIVED, $request['activityType']);
        $this->assertSame([
            ['name' => 'visitor', 'value' => 'Pat Quillfeather'],
            ['name' => 'site', 'value' => 'North Campus'],
        ], $request['templateParameters']);
        $this->assertStringContainsString('Pat Quillfeather', $request['previewText']['content']);
        // No other visitor data leaves the portal.
        $this->assertStringNotContainsString('pat@example.org', json_encode($request->data()));
        $this->assertStringNotContainsString('Zyxwell Industries', json_encode($request->data()));

        Http::assertSent(fn (Request $r): bool => $r->url() === self::TOKEN_URL
            && $r['grant_type'] === 'client_credentials' && $r['client_id'] === self::CLIENT_ID);
    }

    public function test_alert_falls_back_to_the_email_address(): void
    {
        $this->fakeGraph(204);
        [$visit, $visitor] = $this->visitWithVisitor();

        (new TeamsActivityChannel)->send($visit->host, new GuestCheckedInMailNotification($visit, $visitor));

        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://graph.microsoft.com/v1.0/users/host%40school.example/teamwork/sendActivityNotification');
    }

    public function test_visit_created_and_reminder_alerts(): void
    {
        $this->fakeGraph(204);
        [$visit] = $this->visitWithVisitor();
        $channel = new TeamsActivityChannel;

        $channel->send($visit->host, new VisitCreated($visit, $visit->visitors));
        $channel->send($visit->host, new VisitReminderDaily(collect([$visit]), collect([$visit->id => $visit->visitors])));

        $bodies = Http::recorded(fn (Request $r): bool => str_contains($r->url(), 'sendActivityNotification'))
            ->map(fn (array $pair) => $pair[0]->data())->values();

        $this->assertSame(TeamsActivity::VISIT_CREATED, $bodies[0]['activityType']);
        $this->assertSame(['name' => 'visitor', 'value' => 'Pat Quillfeather'], $bodies[0]['templateParameters'][0]);
        $this->assertSame(TeamsActivity::VISIT_REMINDER, $bodies[1]['activityType']);
        $this->assertSame([['name' => 'count', 'value' => '1']], $bodies[1]['templateParameters']);
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function permanentFailures(): array
    {
        return ['app not installed or permission missing' => [403, 'Forbidden'], 'user not found' => [404, 'NotFound']];
    }

    #[DataProvider('permanentFailures')]
    public function test_permanent_failures_are_logged_not_thrown(int $status, string $code): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'graph-token', 'expires_in' => 3600]),
            'https://graph.microsoft.com/*' => Http::response(['error' => ['code' => $code, 'message' => 'secret detail']], $status),
        ]);
        Log::spy();
        [$visit, $visitor] = $this->visitWithVisitor();

        (new TeamsActivityChannel)->send($visit->host, new GuestCheckedInMailNotification($visit, $visitor));

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'Teams notification not delivered.'
            && $context['user_id'] === $visit->host_user_id
            && $context['visit_id'] === $visit->id
            && $context['graph_error'] === $code
            && ! str_contains((string) json_encode($context), 'graph-token')
            && ! str_contains((string) json_encode($context), 'secret'));
    }

    public function test_server_errors_throw_so_the_job_is_retried(): void
    {
        $this->fakeGraph(503);
        [$visit, $visitor] = $this->visitWithVisitor();

        $this->expectException(TeamsNotificationException::class);

        (new TeamsActivityChannel)->send($visit->host, new GuestCheckedInMailNotification($visit, $visitor));
    }

    public function test_a_rejected_token_is_refreshed_once(): void
    {
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls) {
            if (str_contains($request->url(), '/oauth2/v2.0/token')) {
                return Http::response(['access_token' => 'graph-token', 'expires_in' => 3600]);
            }

            return ++$calls === 1 ? Http::response(null, 401) : Http::response(null, 204);
        });
        [$visit, $visitor] = $this->visitWithVisitor();

        (new TeamsActivityChannel)->send($visit->host, new GuestCheckedInMailNotification($visit, $visitor));

        $this->assertSame(2, $calls);
    }

    public function test_booking_for_someone_else_notifies_the_host(): void
    {
        Notification::fake();
        $receptionist = (new PermissionHelper)->getReceptionistUser();
        $host = User::factory()->create(['site_id' => $receptionist->site_id]);

        $this->actingAs($receptionist)
            ->post(route('portal.visits.store'), $this->visitPayload($host))
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($host, VisitCreated::class);
        Notification::assertNotSentTo($receptionist, VisitCreated::class);
    }

    public function test_booking_your_own_visit_sends_no_notification(): void
    {
        Notification::fake();
        $user = (new PermissionHelper)->getUser();

        $this->actingAs($user)
            ->post(route('portal.visits.store'), $this->visitPayload($user))
            ->assertSessionHasNoErrors();

        Notification::assertNothingSentTo($user);
    }

    public function test_profile_saves_the_notification_choice(): void
    {
        $user = (new PermissionHelper)->getUser();

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('name="notify_by_email"', false)
            ->assertSee('name="notify_by_teams"', false);

        $this->actingAs($user)
            ->patch(route('profile.update'), ['notification_preferences' => '1', 'notify_by_email' => '0', 'notify_by_teams' => '1'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertFalse((bool) $user->notify_by_email);
        $this->assertNotNull($user->notify_by_email);
        $this->assertTrue((bool) $user->notify_by_teams);
        $this->assertSame([TeamsActivityChannel::class], HostNotificationChannels::for($user));

        // The language form leaves the choice alone.
        $this->actingAs($user)->patch(route('profile.update'), ['locale' => 'en'])->assertSessionHasNoErrors();
        $this->assertTrue((bool) $user->fresh()->notify_by_teams);
    }

    public function test_profile_hides_and_keeps_teams_while_teams_is_off(): void
    {
        config(['teams.enabled' => false]);
        $user = (new PermissionHelper)->getUser();

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('name="notify_by_email"', false)
            ->assertDontSee('name="notify_by_teams"', false);

        $this->actingAs($user)
            ->patch(route('profile.update'), ['notification_preferences' => '1', 'notify_by_email' => '1'])
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertTrue((bool) $user->notify_by_email);
        $this->assertNull($user->notify_by_teams);
    }

    public function test_package_command_builds_the_teams_app(): void
    {
        config(['app.url' => 'https://visitors.school.example', 'branding.name' => 'Riverside College']);
        $path = sys_get_temp_dir().'/teams-app-'.uniqid().'.zip';

        try {
            $this->artisan('visitorportal:teams:package', ['--output' => $path, '--app-version' => '1.2.0'])
                ->expectsOutputToContain($path)
                ->assertSuccessful();

            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path) === true);
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
            $color = getimagesizefromstring((string) $zip->getFromName('color.png'));
            $outline = getimagesizefromstring((string) $zip->getFromName('outline.png'));
            $zip->close();
        } finally {
            @unlink($path);
        }

        $this->assertSame('1.17', $manifest['manifestVersion']);
        $this->assertSame('1.2.0', $manifest['version']);
        $this->assertSame(self::APP_ID, $manifest['id']);
        $this->assertSame(self::CLIENT_ID, $manifest['webApplicationInfo']['id']);
        $this->assertStringEndsWith('/'.self::CLIENT_ID, $manifest['webApplicationInfo']['resource']);
        $this->assertSame(
            [['name' => 'TeamsActivity.Send.User', 'type' => 'Application']],
            $manifest['authorization']['permissions']['resourceSpecific'],
        );
        $this->assertSame('Riverside College', $manifest['name']['short']);
        $this->assertSame(['visitors.school.example'], $manifest['validDomains']);
        $this->assertSame('visits', $manifest['staticTabs'][0]['entityId']);
        $this->assertSame('https://visitors.school.example/teams/tab?visit={subEntityId}', $manifest['staticTabs'][0]['contentUrl']);
        $this->assertSame(['personal'], $manifest['staticTabs'][0]['scopes']);
        $this->assertSame(
            [TeamsActivity::VISITOR_ARRIVED, TeamsActivity::VISIT_CREATED, TeamsActivity::VISIT_REMINDER],
            array_column($manifest['activities']['activityTypes'], 'type'),
        );
        $this->assertStringContainsString('{visitor}', $manifest['activities']['activityTypes'][0]['templateText']);
        $this->assertSame([192, 192, IMAGETYPE_PNG], [$color[0], $color[1], $color[2]]);
        $this->assertSame([32, 32, IMAGETYPE_PNG], [$outline[0], $outline[1], $outline[2]]);
    }

    public function test_package_command_requires_a_guid_app_id(): void
    {
        config(['teams.app_id' => 'not-a-guid']);

        $this->artisan('visitorportal:teams:package', ['--output' => sys_get_temp_dir().'/never.zip'])
            ->expectsOutputToContain('TEAMS_APP_ID')
            ->assertFailed();
    }

    public function test_teams_tab_may_be_framed_by_teams_and_shows_no_visit_data(): void
    {
        [$visit] = $this->visitWithVisitor();

        $response = $this->get('/teams/tab?visit='.$visit->id)->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'self' https://teams.microsoft.com https://*.teams.microsoft.com https://*.cloud.microsoft https://*.office.com https://*.microsoft365.com", $csp);
        $response->assertSee(route('portal.visits.show', $visit), false)
            ->assertSee('target="_blank" rel="noopener"', false)
            ->assertDontSee('Quillfeather')
            ->assertDontSee('Confidential site tour')
            ->assertDontSee('North Campus')
            ->assertDontSee('<script', false);

        $this->get('/teams/tab?visit=abc')->assertOk()->assertSee(route('overview'), false);

        // Every other page keeps frame-ancestors 'self'.
        $other = (string) $this->get('/login')->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'self';", $other);
        $this->assertStringNotContainsString('teams.microsoft.com', $other);
    }

    private function fakeGraph(int $status): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'graph-token', 'expires_in' => 3600]),
            'https://graph.microsoft.com/*' => Http::response($status === 204 ? null : ['error' => ['code' => 'ServiceUnavailable']], $status),
        ]);
    }

    /**
     * @return array{Visit, Visitor}
     */
    private function visitWithVisitor(string $hostEmail = 'host@school.example'): array
    {
        $site = Site::query()->where('name', 'North Campus')->first() ?? Site::factory()->create(['name' => 'North Campus']);
        $host = User::factory()->create(['site_id' => $site->id, 'email' => $hostEmail]);
        $visit = Visit::factory()->create(['site_id' => $site->id, 'host_user_id' => $host->id, 'title' => 'Confidential site tour']);
        $visitor = Visitor::factory()->create([
            // Names no random test data can produce, so "not shown" checks are reliable.
            'first_name' => 'Pat',
            'name' => 'Quillfeather',
            'email' => 'pat@example.org',
            'company' => 'Zyxwell Industries',
            'created_by_user_id' => $host->id,
        ]);
        $visit->visitors()->attach($visitor->id);

        return [$visit->fresh(['host', 'site', 'visitors']), $visitor];
    }

    /**
     * @return array<string, mixed>
     */
    private function visitPayload(User $host): array
    {
        return [
            'title' => 'Campus tour',
            'host_user_id' => $host->id,
            'scheduled_from' => now()->addDay()->setTime(9, 0)->format('Y-m-d H:i:s'),
            'scheduled_until' => now()->addDay()->setTime(10, 0)->format('Y-m-d H:i:s'),
            'status' => VisitStatusEnum::Planned->value,
            'participants' => [
                ['first_name' => 'Anna', 'name' => 'Becker', 'email' => 'anna@example.com', 'phone' => '+493023125024', 'company' => 'Example Industries'],
            ],
        ];
    }
}
