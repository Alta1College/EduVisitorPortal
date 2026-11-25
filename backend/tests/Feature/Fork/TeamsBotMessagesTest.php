<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use App\HostDirectory\Entra\DirectoryAccount;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\Notifications\Host\GuestCheckedInMailNotification;
use App\Notifications\Host\VisitCreated;
use App\Notifications\Host\VisitReminderDaily;
use App\Teams\TeamsActivityChannel;
use App\Teams\TeamsAppManifest;
use App\Teams\TeamsChatMessage;
use App\Teams\TeamsNotificationException;
use App\VisitorWorkflow\Models\FormField;
use App\VisitorWorkflow\Models\VisitResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TeamsBotMessagesTest extends TestCase
{
    use RefreshDatabase;

    private const APP_ID = '0f8e3a52-7c1d-4b6e-9a2f-5d3c1b7e8a90';

    private const CLIENT_ID = '6a1b2c3d-4e5f-4a7b-8c9d-0e1f2a3b4c5d';

    private const BOT_ID = '9b8c7d6e-5f4a-4b3c-8d2e-1f0a9b8c7d6e';

    private const TENANT = 'tenant-1';

    private const TOKEN_URL = 'https://login.microsoftonline.com/tenant-1/oauth2/v2.0/token';

    private const SERVICE = 'https://smba.trafficmanager.net/teams/';

    private const OBJECT_ID = '11111111-2222-4333-8444-555555555555';

    private const CONVERSATION = 'a:1chat-with-host';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'teams.enabled' => true,
            'teams.app_id' => self::APP_ID,
            'teams.tenant_id' => self::TENANT,
            'teams.client_id' => self::CLIENT_ID,
            'teams.client_secret' => 'graph-secret',
            'teams.authority' => 'https://login.microsoftonline.com',
            'teams.graph_endpoint' => 'https://graph.microsoft.com',
            'teams.delivery' => 'chat',
            'teams.bot' => [
                'app_id' => self::BOT_ID,
                'app_secret' => 'bot-secret',
                'tenant_id' => self::TENANT,
                'service_url' => self::SERVICE,
                'include_photo' => false,
                'photo_link_hours' => 24,
            ],
        ]);
    }

    public function test_token_is_requested_for_the_bot_framework_from_the_bot_tenant(): void
    {
        $this->fakeBot();
        [$visit, $visitor] = $this->visitWithVisitor();

        (new TeamsActivityChannel)->send($visit->host, new GuestCheckedInMailNotification($visit, $visitor));

        Http::assertSent(fn (Request $r): bool => $r->url() === self::TOKEN_URL
            && $r['grant_type'] === 'client_credentials'
            && $r['client_id'] === self::BOT_ID
            && $r['client_secret'] === 'bot-secret'
            && $r['scope'] === 'https://api.botframework.com/.default');
        Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), self::SERVICE)
            && $r->hasHeader('Authorization', 'Bearer bot-token'));
        // Chat delivered: no activity alert.
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'graph.microsoft.com'));
    }

    public function test_conversation_is_created_once_and_cached(): void
    {
        $this->fakeBot();
        [$visit, $visitor] = $this->visitWithVisitor();
        $channel = new TeamsActivityChannel;

        $channel->send($visit->host, new GuestCheckedInMailNotification($visit, $visitor));
        $channel->send($visit->host, new GuestCheckedInMailNotification($visit, $visitor));

        $created = Http::recorded(fn (Request $r): bool => $r->url() === self::SERVICE.'v3/conversations')->values();
        $this->assertCount(1, $created);
        $this->assertSame([
            'isGroup' => false,
            'bot' => ['id' => '28:'.self::BOT_ID],
            'members' => [['id' => self::OBJECT_ID]],
            'channelData' => ['tenant' => ['id' => self::TENANT]],
        ], $created[0][0]->data());

        $this->assertCount(2, Http::recorded(fn (Request $r): bool => $r->url() === self::SERVICE.'v3/conversations/'.rawurlencode(self::CONVERSATION).'/activities'));
    }

    public function test_arrival_message_is_an_adaptive_card_with_escaped_visitor_text(): void
    {
        $this->fakeBot();
        [$visit, $visitor] = $this->visitWithVisitor(firstName: '[Click](https://evil.example)', company: '*Bold* <b>Ltd</b>');

        (new TeamsActivityChannel)->send($visit->host, new GuestCheckedInMailNotification($visit, $visitor));

        $activity = $this->postedActivities()[0];
        $this->assertSame('message', $activity['type']);
        $this->assertSame('application/vnd.microsoft.card.adaptive', $activity['attachments'][0]['contentType']);
        $card = $activity['attachments'][0]['content'];

        $this->assertSame('AdaptiveCard', $card['type']);
        $this->assertSame('1.5', $card['version']);
        $this->assertSame('Ihr Besuch ist angekommen', $card['body'][0]['text']);
        $facts = collect($card['body'])->firstWhere('type', 'FactSet')['facts'];
        $values = array_column($facts, 'value', 'title');
        $this->assertSame('\[Click\]\(https://evil.example\) Example', $values['Besucher']);
        $this->assertSame('\*Bold\* \<b\>Ltd\</b\>', $values['Firma']);
        $this->assertSame('North Campus', $values['Standort']);
        $this->assertArrayHasKey('Angekommen um', $values);
        $this->assertStringNotContainsString('](https', (string) json_encode($card, JSON_UNESCAPED_SLASHES));
        $this->assertSame([[
            'type' => 'Action.OpenUrl',
            'title' => 'Im Portal öffnen',
            'url' => route('portal.visits.show', $visit),
        ]], $card['actions']);
        // No photo unless switched on.
        $this->assertNull(collect($card['body'])->firstWhere('type', 'Image'));
    }

    public function test_card_uses_the_hosts_language(): void
    {
        $this->fakeBot();
        [$visit, $visitor] = $this->visitWithVisitor();
        $visit->host->forceFill(['locale' => 'en'])->save();

        (new TeamsActivityChannel)->send($visit->host->fresh(), new GuestCheckedInMailNotification($visit, $visitor));

        $card = $this->postedActivities()[0]['attachments'][0]['content'];
        $this->assertSame('Your visitor has arrived', $card['body'][0]['text']);
        $this->assertSame('Open in portal', $card['actions'][0]['title']);
    }

    public function test_visit_created_and_reminder_cards(): void
    {
        $this->fakeBot();
        [$visit] = $this->visitWithVisitor();
        $channel = new TeamsActivityChannel;

        $channel->send($visit->host, new VisitCreated($visit, $visit->visitors));
        $channel->send($visit->host, new VisitReminderDaily(collect([$visit]), collect([$visit->id => $visit->visitors])));

        [$created, $reminder] = array_map(fn (array $a) => $a['attachments'][0]['content'], $this->postedActivities());

        $this->assertSame('Für Sie wurde ein Besuch gebucht', $created['body'][0]['text']);
        $values = array_column(collect($created['body'])->firstWhere('type', 'FactSet')['facts'], 'value', 'title');
        $this->assertSame('Pat Example', $values['Besucher']);
        $this->assertSame('Example Industries', $values['Firma']);
        $this->assertArrayHasKey('Uhrzeit', $values);
        $this->assertSame(route('portal.visits.show', $visit), $created['actions'][0]['url']);

        $this->assertSame('Ihre Besuche heute', $reminder['body'][0]['text']);
        $this->assertStringContainsString('Pat Example · North Campus', $reminder['body'][1]['text']);
        $this->assertSame(route('portal.visits.show', $visit), $reminder['actions'][0]['url']);
    }

    public function test_photo_is_a_signed_link_only_when_enabled(): void
    {
        Storage::fake('local');
        $this->fakeBot();
        [$visit, $visitor] = $this->visitWithVisitor();
        $path = 'evidence/'.$visit->id.'/photo.jpg';
        Storage::disk('local')->put($path, $this->jpeg());
        VisitResponse::query()->create([
            'visit_id' => $visit->id, 'visitor_id' => $visitor->id, 'field_key' => 'photo',
            'field_label' => 'Photo', 'field_type' => FormField::TYPE_PHOTO, 'file_path' => $path,
        ]);
        $channel = new TeamsActivityChannel;

        $channel->send($visit->host, new GuestCheckedInMailNotification($visit, $visitor));
        $this->assertNull(collect($this->postedActivities()[0]['attachments'][0]['content']['body'])->firstWhere('type', 'Image'));

        config(['teams.bot.include_photo' => true]);
        $channel->send($visit->host, new GuestCheckedInMailNotification($visit, $visitor));
        $image = collect($this->postedActivities()[1]['attachments'][0]['content']['body'])->firstWhere('type', 'Image');

        $url = $image['url'];
        $this->assertStringStartsWith(route('teams.visitor-photo'), $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);
        $this->assertStringNotContainsString('evidence', $url);
        $this->assertStringNotContainsString('photo.jpg', $url);

        $response = $this->get($url)->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        // Unsigned or tampered links are refused.
        $this->get(preg_replace('/&signature=[^&]+/', '', $url))->assertForbidden();
        $this->get(str_replace('signature=', 'signature=0', $url))->assertForbidden();

        // Expired links are refused.
        $this->travel(25)->hours();
        $this->get($url)->assertForbidden();
    }

    public function test_photo_route_serves_only_evidence_images(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('evidence/1/notes.txt', 'not an image');
        Storage::disk('local')->put('other/photo.jpg', $this->jpeg());

        foreach (['evidence/1/notes.txt', 'other/photo.jpg', 'evidence/../other/photo.jpg', 'evidence/1/missing.jpg'] as $path) {
            $url = URL::temporarySignedRoute('teams.visitor-photo', now()->addHour(), ['photo' => Crypt::encryptString($path)]);
            $this->get($url)->assertNotFound();
        }

        $url = URL::temporarySignedRoute('teams.visitor-photo', now()->addHour(), ['photo' => 'not-encrypted']);
        $this->get($url)->assertNotFound();
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function permanentFailures(): array
    {
        return [
            'conversation 403 (bot not installed)' => ['conversation', 403],
            'conversation 404' => ['conversation', 404],
            'message 403' => ['message', 403],
            'message 404' => ['message', 404],
        ];
    }

    #[DataProvider('permanentFailures')]
    public function test_permanent_bot_failures_fall_back_to_the_activity_alert(string $step, int $status): void
    {
        $this->fakeBot(conversationStatus: $step === 'conversation' ? $status : 201, messageStatus: $step === 'message' ? $status : 201);
        Log::spy();
        [$visit, $visitor] = $this->visitWithVisitor();

        (new TeamsActivityChannel)->send($visit->host, new GuestCheckedInMailNotification($visit, $visitor));

        $alerts = Http::recorded(fn (Request $r): bool => $r->url() === 'https://graph.microsoft.com/v1.0/users/'.self::OBJECT_ID.'/teamwork/sendActivityNotification');
        $this->assertCount(1, $alerts);
        Http::assertSent(fn (Request $r): bool => $r->url() === self::TOKEN_URL && $r['scope'] === 'https://graph.microsoft.com/.default');

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => str_starts_with($message, 'Teams bot message not delivered')
            && $context['user_id'] === $visit->host_user_id
            && $context['step'] === $step
            && $context['status'] === $status
            && $context['bot_error'] === 'BotNotInConversationRoster'
            && ! str_contains((string) json_encode($context), 'token')
            && ! str_contains((string) json_encode($context), 'secret')
            && ! str_contains((string) json_encode($context), 'Pat'));
    }

    public function test_refused_bot_credentials_fall_back_to_the_activity_alert(): void
    {
        Http::fake(function (Request $request) {
            if ($request->url() === self::TOKEN_URL) {
                return $request['scope'] === 'https://api.botframework.com/.default'
                    ? Http::response(['error' => 'invalid_client', 'error_description' => 'secret detail'], 401)
                    : Http::response(['access_token' => 'graph-token', 'expires_in' => 3600]);
            }

            return Http::response(null, str_starts_with($request->url(), 'https://graph.microsoft.com/') ? 204 : 500);
        });
        Log::spy();
        [$visit, $visitor] = $this->visitWithVisitor();

        (new TeamsActivityChannel)->send($visit->host, new GuestCheckedInMailNotification($visit, $visitor));

        Http::assertNotSent(fn (Request $r): bool => str_starts_with($r->url(), self::SERVICE));
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'sendActivityNotification'));
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context['step'] === 'token'
            && $context['status'] === 401
            && ! str_contains((string) json_encode($context), 'secret'));
    }

    public function test_server_errors_throw_so_the_job_is_retried(): void
    {
        $this->fakeBot(messageStatus: 503);
        [$visit, $visitor] = $this->visitWithVisitor();

        try {
            (new TeamsActivityChannel)->send($visit->host, new GuestCheckedInMailNotification($visit, $visitor));
            $this->fail('Expected TeamsNotificationException.');
        } catch (TeamsNotificationException) {
            // No fallback alert while the message will be retried.
            Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'sendActivityNotification'));
        }
    }

    public function test_throttling_throws(): void
    {
        $this->fakeBot(conversationStatus: 429);
        [$visit, $visitor] = $this->visitWithVisitor();

        $this->expectException(TeamsNotificationException::class);

        (new TeamsActivityChannel)->send($visit->host, new GuestCheckedInMailNotification($visit, $visitor));
    }

    public function test_hosts_without_an_entra_link_get_the_activity_alert(): void
    {
        $this->fakeBot();
        [$visit, $visitor] = $this->visitWithVisitor(linkEntra: false);

        (new TeamsActivityChannel)->send($visit->host, new GuestCheckedInMailNotification($visit, $visitor));

        Http::assertNotSent(fn (Request $r): bool => str_starts_with($r->url(), self::SERVICE));
        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://graph.microsoft.com/v1.0/users/host%40school.example/teamwork/sendActivityNotification');
    }

    public function test_activity_delivery_keeps_the_activity_alert(): void
    {
        config(['teams.delivery' => 'activity']);
        $this->fakeBot();
        [$visit, $visitor] = $this->visitWithVisitor();

        (new TeamsActivityChannel)->send($visit->host, new GuestCheckedInMailNotification($visit, $visitor));

        Http::assertNotSent(fn (Request $r): bool => str_starts_with($r->url(), self::SERVICE));
        Http::assertNotSent(fn (Request $r): bool => ($r->data()['scope'] ?? null) === 'https://api.botframework.com/.default');
        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://graph.microsoft.com/v1.0/users/'.self::OBJECT_ID.'/teamwork/sendActivityNotification');
    }

    public function test_bot_defaults_to_the_graph_app_registration(): void
    {
        config(['teams.bot.app_id' => null, 'teams.bot.app_secret' => null, 'teams.bot.tenant_id' => null]);
        $this->fakeBot();
        [$visit, $visitor] = $this->visitWithVisitor();

        (new TeamsActivityChannel)->send($visit->host, new GuestCheckedInMailNotification($visit, $visitor));

        Http::assertSent(fn (Request $r): bool => $r->url() === self::TOKEN_URL
            && $r['client_id'] === self::CLIENT_ID && $r['client_secret'] === 'graph-secret'
            && $r['scope'] === 'https://api.botframework.com/.default');
        Http::assertSent(fn (Request $r): bool => $r->url() === self::SERVICE.'v3/conversations'
            && $r['bot']['id'] === '28:'.self::CLIENT_ID);
    }

    public function test_messaging_endpoint_acknowledges_and_ignores_the_body(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $before = [User::query()->count(), DirectoryAccount::query()->count()];

        $response = $this->postJson('/teams/bot/messages', [
            'type' => 'message',
            'text' => 'delete everything',
            'from' => ['id' => '29:someone', 'aadObjectId' => self::OBJECT_ID],
            'serviceUrl' => 'https://attacker.example/',
        ], ['Authorization' => 'Bearer forged']);

        $response->assertOk();
        $this->assertSame('', $response->getContent());
        $this->assertSame([], $response->headers->getCookies());
        Http::assertNothingSent();
        $this->assertSame($before, [User::query()->count(), DirectoryAccount::query()->count()]);
        $this->assertNotNull($user->fresh());

        // Not in the web group: no session and no CSRF check (Azure posts without a token).
        $middleware = Route::getRoutes()->getByName('teams.bot.messages')->gatherMiddleware();
        $this->assertNotContains('web', $middleware);
        $this->assertContains('throttle:teams-bot', $middleware);
        $this->post('/teams/bot/messages', ['text' => 'x'])->assertOk();
    }

    public function test_manifest_declares_the_notification_only_bot_for_chat_delivery(): void
    {
        config(['app.url' => 'https://visitors.school.example']);

        $manifest = TeamsAppManifest::build('1.1.0');

        $this->assertSame('1.1.0', $manifest['version']);
        $this->assertSame([[
            'botId' => self::BOT_ID,
            'scopes' => ['personal'],
            'isNotificationOnly' => true,
            'supportsFiles' => false,
            'supportsCalling' => false,
            'supportsVideo' => false,
        ]], $manifest['bots']);
        // The tab, activity types and resource-specific permission stay.
        $this->assertSame('visits', $manifest['staticTabs'][0]['entityId']);
        $this->assertCount(3, $manifest['activities']['activityTypes']);
        $this->assertSame('TeamsActivity.Send.User', $manifest['authorization']['permissions']['resourceSpecific'][0]['name']);

        config(['teams.delivery' => 'activity']);
        $this->assertArrayNotHasKey('bots', TeamsAppManifest::build());

        config(['teams.delivery' => 'chat', 'teams.bot.app_id' => 'not-a-guid']);
        $this->expectExceptionMessage('TEAMS_BOT_APP_ID');
        TeamsAppManifest::build();
    }

    public function test_escape_keeps_ordinary_text_readable(): void
    {
        $this->assertSame('Example Pty. Ltd.', TeamsChatMessage::escape('Example Pty. Ltd.'));
        $this->assertSame('Smith-Jones', TeamsChatMessage::escape("Smith-Jones\n"));
        $this->assertSame('1\. Floor', TeamsChatMessage::escape('1. Floor'));
        $this->assertSame('\- item', TeamsChatMessage::escape('- item'));
        $this->assertSame('\# heading \_x\_', TeamsChatMessage::escape("# heading\n_x_"));
    }

    private function fakeBot(int $conversationStatus = 201, int $messageStatus = 201): void
    {
        Http::fake(function (Request $request) use ($conversationStatus, $messageStatus) {
            $url = $request->url();

            if ($url === self::TOKEN_URL) {
                $bot = $request['scope'] === 'https://api.botframework.com/.default';

                return Http::response(['access_token' => $bot ? 'bot-token' : 'graph-token', 'expires_in' => 3600]);
            }

            $error = ['error' => ['code' => 'BotNotInConversationRoster', 'message' => 'secret detail']];

            if ($url === self::SERVICE.'v3/conversations') {
                return $conversationStatus < 300
                    ? Http::response(['id' => self::CONVERSATION], $conversationStatus)
                    : Http::response($error, $conversationStatus);
            }

            if (str_starts_with($url, self::SERVICE.'v3/conversations/')) {
                return $messageStatus < 300
                    ? Http::response(['id' => 'activity-1'], $messageStatus)
                    : Http::response($error, $messageStatus);
            }

            if (str_starts_with($url, 'https://graph.microsoft.com/')) {
                return Http::response(null, 204);
            }

            return Http::response(null, 500);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function postedActivities(): array
    {
        return Http::recorded(fn (Request $r): bool => str_ends_with($r->url(), '/activities'))
            ->map(fn (array $pair): array => $pair[0]->data())
            ->values()
            ->all();
    }

    /**
     * @return array{Visit, Visitor}
     */
    private function visitWithVisitor(string $firstName = 'Pat', string $company = 'Example Industries', bool $linkEntra = true): array
    {
        $site = Site::query()->where('name', 'North Campus')->first() ?? Site::factory()->create(['name' => 'North Campus']);
        $host = User::factory()->create(['site_id' => $site->id, 'email' => 'host@school.example', 'locale' => 'de']);
        $visit = Visit::factory()->create(['site_id' => $site->id, 'host_user_id' => $host->id]);
        $visitor = Visitor::factory()->create([
            'first_name' => $firstName,
            'name' => 'Example',
            'email' => 'pat@example.org',
            'company' => $company,
            'created_by_user_id' => $host->id,
        ]);
        $visit->visitors()->attach($visitor->id, ['checked_in_at' => now()]);

        if ($linkEntra) {
            DirectoryAccount::query()->create(['user_id' => $host->id, 'provider' => 'entra', 'external_id' => self::OBJECT_ID]);
        }

        return [$visit->fresh(['host', 'site', 'visitors']), $visitor];
    }

    private function jpeg(): string
    {
        // 1x1 JPEG (no GD needed).
        return (string) base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==');
    }
}
