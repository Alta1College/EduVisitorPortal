<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use App\Audit\AuditEvent;
use App\Emergency\Broadcast\EmergencyBroadcast;
use App\Emergency\Broadcast\EmergencyBroadcastMail;
use App\Emergency\Broadcast\EmergencyNotification;
use App\Emergency\Broadcast\SendEmergencyBroadcast;
use App\Emergency\Broadcast\SendEmergencyEmail;
use App\Emergency\Livewire\OnSitePage;
use App\Emergency\Livewire\RollCallPage;
use App\Emergency\RollCall;
use App\Emergency\RollCallEntry;
use App\Emergency\RollCalls;
use App\Emergency\Sms\PhoneNumber;
use App\Emergency\Sms\SmsText;
use App\Filament\Pages\ManageOrganisationSettings;
use App\Models\Site;
use App\Models\User;
use App\PublicVisit\Channel;
use App\PublicVisit\Commands\ArrivalRequest;
use App\PublicVisit\Commands\ArrivalResult;
use App\PublicVisit\Commands\RegisterArrival;
use App\Settings\SettingsRepository;
use App\Support\VisitorPortalPermissions;
use App\VisitorWorkflow\Models\VisitorType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

class EmergencyBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private const SEND_URL = 'https://app.messageport.com.au/api/v2/sms';

    private Site $site;

    private Site $otherSite;

    private VisitorType $type;

    private User $warden;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();
        VisitorPortalPermissions::sync();

        config([
            'app.locale' => 'en',
            'emergency.sms_enabled' => true,
            'emergency.email_enabled' => true,
            'messageport.access_token' => 'test-access-token',
            'messageport.token_id' => 'test-token-id',
            'messageport.sender_id' => 'TestSender',
            'messageport.webhook_secret' => 'test-webhook-secret',
            'messageport.base_url' => 'https://app.messageport.com.au/api',
            'messageport.dry_run' => false,
        ]);

        $this->site = Site::factory()->create(['name' => 'North Campus', 'timezone' => config('app.timezone')]);
        $this->otherSite = Site::factory()->create(['name' => 'Central Office', 'timezone' => config('app.timezone')]);

        foreach ([$this->site, $this->otherSite] as $site) {
            $reception = User::factory()->create(['site_id' => $site->getKey(), 'is_active' => true]);
            app(SettingsRepository::class)->saveSite($site, ['organisation.reception_host_user_id' => $reception->getKey()], null);
        }

        $this->type = VisitorType::query()->create(['name' => 'Contractor', 'slug' => 'contractor', 'host_requirement' => VisitorType::HOST_NONE]);
        $this->warden = User::factory()->create(['site_id' => $this->site->getKey()]);
        $this->warden->assignRole('receptionist');
    }

    private function arrive(string $first, ?string $phone, ?string $email = null, ?Site $site = null): ArrivalResult
    {
        $result = app(RegisterArrival::class)->handle(new ArrivalRequest(
            site: $site ?? $this->site,
            channel: Channel::Kiosk,
            submissionKey: (string) Str::uuid(),
            visitorType: $this->type,
            details: ['first_name' => $first, 'name' => 'Visitor', 'company' => 'Acme'],
            hostToken: null,
            answers: [],
            acceptedPolicyVersionIds: [],
        ));
        DB::table('visitors')->where('id', $result->visitorId)->update(['phone' => $phone, 'email' => $email]);

        return $result;
    }

    private function fakeMessagePort(array $errors = [], int $status = 200): void
    {
        Http::fake(['app.messageport.com.au/*' => function (Request $request) use ($errors, $status) {
            if ($status !== 200) {
                return Http::response(['message' => 'Unauthenticated.'], $status);
            }

            $accepted = collect($request->data()['messages'])
                ->reject(fn (array $m): bool => in_array(ltrim($m['to'], '+'), array_keys($errors), true))
                ->map(fn (array $m): array => ['status' => 'VALID', 'to' => ltrim($m['to'], '+'), 'reference' => $m['reference']])->values();
            $rejected = collect($request->data()['messages'])
                ->filter(fn (array $m): bool => isset($errors[ltrim($m['to'], '+')]))
                ->map(fn (array $m): array => ['status' => 'ERROR', 'error_code' => $errors[ltrim($m['to'], '+')], 'error_help' => 'Help text', 'to' => ltrim($m['to'], '+')])->values();

            return Http::response(['data' => ['messages' => $accepted, 'errors' => $rejected]]);
        }]);
    }

    private function start(string $kind, bool $sendDrillMessages = false): RollCall
    {
        return app(RollCalls::class)->start($this->site, $this->warden, $kind, $sendDrillMessages);
    }

    private function smsRow(ArrivalResult $person): ?EmergencyNotification
    {
        return EmergencyNotification::query()->where('visitor_id', $person->visitorId)->where('channel', 'sms')->first();
    }

    private function webhook(array $payload, ?string $secret = 'test-webhook-secret'): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/webhooks/messageport', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_SIGNATURE' => $secret !== null ? hash_hmac('sha256', $body, $secret) : '',
        ], $body);
    }

    public function test_a_real_emergency_messages_everyone_signed_in_at_that_site_only(): void
    {
        $this->fakeMessagePort();
        $ada = $this->arrive('Ada', '0412 345 678', 'ada@example.org');
        $ben = $this->arrive('Ben', null, 'ben@example.org');
        $cara = $this->arrive('Cara', '+61 498 765 432');
        $gone = $this->arrive('Gone', '0400000001', 'gone@example.org');
        DB::table('visit_visitor')->where('visit_id', $gone->visitId)->update(['checked_out_at' => now()]);
        $this->arrive('Other', '0400000002', 'other@example.org', $this->otherSite);

        $rollCall = $this->start(RollCall::KIND_EMERGENCY);

        $this->assertNotNull($rollCall->fresh()->broadcast_at);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($rollCall): bool {
            $messages = $request->data()['messages'];
            $tos = collect($messages)->pluck('to')->sort()->values()->all();

            return $request->url() === self::SEND_URL
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('test-access-token:test-token-id'))
                && $request->hasHeader('Accept', 'application/json')
                && $tos === ['+61412345678', '+61498765432']
                && collect($messages)->every(fn (array $m): bool => $m['from'] === 'TestSender'
                    && str_starts_with($m['reference'], 'rc'.$rollCall->id.'-e')
                    && str_starts_with($m['body'], 'EMERGENCY at North Campus: please leave now')
                    && SmsText::fitsOneSegment($m['body']));
        });

        $this->assertSame('accepted', $this->smsRow($ada)->status);
        $this->assertSame('678', substr($this->smsRow($ada)->to_masked, -3));
        $this->assertStringNotContainsString('412345', (string) $this->smsRow($ada)->to_masked);
        $this->assertSame('no_mobile', $this->smsRow($ben)->status);
        $this->assertSame('accepted', $this->smsRow($cara)->status);
        $this->assertNull($this->smsRow($gone));

        Mail::assertSent(EmergencyBroadcastMail::class, 2);
        Mail::assertSent(EmergencyBroadcastMail::class, fn (EmergencyBroadcastMail $mail): bool => $mail->hasTo('ada@example.org') && ! $mail->drill
            && $mail->envelope()->subject === 'Emergency at North Campus');
        Mail::assertSent(EmergencyBroadcastMail::class, fn (EmergencyBroadcastMail $mail): bool => $mail->hasTo('ben@example.org'));
        Mail::assertNotSent(EmergencyBroadcastMail::class, fn (EmergencyBroadcastMail $mail): bool => $mail->hasTo('gone@example.org') || $mail->hasTo('other@example.org'));
        $this->assertSame(2, EmergencyNotification::query()->where('channel', 'email')->where('status', 'sent')->count());

        $audit = AuditEvent::query()->where('action', 'roll_call.broadcast')->sole();
        $this->assertSame($this->warden->id, $audit->actor_user_id);
        $this->assertSame(['kind' => 'emergency', 'sms_accepted' => 2, 'sms_failed' => 0, 'sms_no_mobile' => 1, 'emails' => 2, 'dry_run' => false], $audit->context);

        // A second warden pressing start opens the same roll call and sends nothing again.
        $this->start(RollCall::KIND_EMERGENCY);
        Http::assertSentCount(1);
        Mail::assertSent(EmergencyBroadcastMail::class, 2);
    }

    public function test_a_run_that_stopped_part_way_is_finished_by_the_retry_without_repeats(): void
    {
        $this->fakeMessagePort();
        $ada = $this->arrive('Ada', '0412 345 678', 'ada@example.org');
        $ben = $this->arrive('Ben', null, 'ben@example.org');
        $cara = $this->arrive('Cara', '+61 498 765 432');
        $rollCall = $this->start(RollCall::KIND_EMERGENCY);
        Http::assertSentCount(1);
        Mail::assertSent(EmergencyBroadcastMail::class, 2);

        // As if the worker had stopped before Cara's text went and before Ben's e-mail row.
        $this->smsRow($cara)->forceFill(['status' => EmergencyNotification::PENDING])->save();
        EmergencyNotification::query()->where('visitor_id', $ben->visitorId)->where('channel', 'email')->delete();

        app(EmergencyBroadcast::class)->send($rollCall->id);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => collect($request->data()['messages'] ?? [])->pluck('to')->all() === ['+61498765432']);
        $this->assertSame('accepted', $this->smsRow($cara)->status);
        Mail::assertSent(EmergencyBroadcastMail::class, 3);
        Mail::assertSent(EmergencyBroadcastMail::class, fn (EmergencyBroadcastMail $mail): bool => $mail->hasTo('ben@example.org'));
        $this->assertSame(3, EmergencyNotification::query()->where('channel', 'sms')->count());

        // Nothing left: another run sends nothing.
        app(EmergencyBroadcast::class)->send($rollCall->id);
        Http::assertSentCount(2);
        Mail::assertSent(EmergencyBroadcastMail::class, 3);
        $this->assertSame(2, AuditEvent::query()->where('action', 'roll_call.broadcast')->count());
        $this->assertSame(5, (new SendEmergencyBroadcast($rollCall->id))->tries);
    }

    public function test_temporary_sms_failures_stay_pending_and_go_on_the_next_try(): void
    {
        config(['emergency.email_enabled' => false]);
        $calls = 0;
        Http::fake(['app.messageport.com.au/*' => function (Request $request) use (&$calls) {
            $calls++;

            if ($calls === 1) {
                return Http::response(['message' => 'Service unavailable'], 503);
            }

            return Http::response(['data' => ['messages' => collect($request->data()['messages'])
                ->map(fn (array $m): array => ['status' => 'VALID', 'to' => ltrim($m['to'], '+'), 'reference' => $m['reference']])->all(), 'errors' => []]]);
        }]);
        Queue::fake();
        $ada = $this->arrive('Ada', '0412345678');
        $rollCall = $this->start(RollCall::KIND_EMERGENCY);

        // First try: the provider is down. The text stays pending (shown as "sending"), not failed.
        $this->assertTrue(app(EmergencyBroadcast::class)->send($rollCall->id));
        $this->assertSame(['pending', 'HTTP_503'], [$this->smsRow($ada)->status, $this->smsRow($ada)->error_code]);
        $this->assertSame(1, AuditEvent::query()->where('action', 'roll_call.broadcast')->sole()->context['sms_retrying']);

        // Next try: it goes.
        $this->assertFalse(app(EmergencyBroadcast::class)->send($rollCall->id));
        $this->assertSame(['accepted', null], [$this->smsRow($ada)->status, $this->smsRow($ada)->error_code]);
        Http::assertSentCount(2);
    }

    public function test_without_a_queue_worker_a_temporary_failure_is_shown_as_failed_with_its_reason(): void
    {
        $this->fakeMessagePort(status: 503);
        $ada = $this->arrive('Ada', '0412345678');

        // The sync queue cannot come back later: it gives up at once, keeping the reason.
        $this->start(RollCall::KIND_EMERGENCY);
        $this->assertSame(['failed', 'HTTP_503'], [$this->smsRow($ada)->status, $this->smsRow($ada)->error_code]);
    }

    public function test_a_delivery_receipt_that_arrives_during_the_provider_call_is_kept(): void
    {
        config(['emergency.email_enabled' => false]);
        Http::fake(['app.messageport.com.au/*' => function (Request $request) {
            $message = $request->data()['messages'][0];
            // The receipt webhook lands while the worker is still waiting for the API response.
            EmergencyNotification::query()->where('reference', $message['reference'])->update(['status' => EmergencyNotification::DELIVERED]);

            return Http::response(['data' => ['messages' => [['status' => 'VALID', 'to' => ltrim($message['to'], '+'), 'reference' => $message['reference']]], 'errors' => []]]);
        }]);
        $ada = $this->arrive('Ada', '0412345678');

        $this->start(RollCall::KIND_EMERGENCY);

        $this->assertSame('delivered', $this->smsRow($ada)->status);
    }

    public function test_an_email_queued_twice_is_sent_once(): void
    {
        config(['emergency.sms_enabled' => false]);
        Queue::fake();
        $ada = $this->arrive('Ada', null, 'ada@example.org');
        $rollCall = $this->start(RollCall::KIND_EMERGENCY);
        app(EmergencyBroadcast::class)->send($rollCall->id);
        app(EmergencyBroadcast::class)->send($rollCall->id); // e.g. a retry re-queues the pending e-mail
        Queue::assertPushed(SendEmergencyEmail::class, 2);
        $row = EmergencyNotification::query()->where('visitor_id', $ada->visitorId)->where('channel', 'email')->sole();

        // A second worker picks up the duplicate while the first is still sending: it sends nothing.
        $lock = Cache::lock('emergency-email:'.$row->id, 60);
        $this->assertTrue($lock->get());
        (new SendEmergencyEmail($row->id))->handle();
        Mail::assertNothingSent();
        $lock->release();

        (new SendEmergencyEmail($row->id))->handle();
        (new SendEmergencyEmail($row->id))->handle();
        Mail::assertSent(EmergencyBroadcastMail::class, 1);
        $this->assertSame('sent', $row->fresh()->status);
    }

    public function test_a_real_emergency_during_an_open_drill_replaces_it_keeping_marks_and_messages_once(): void
    {
        $this->fakeMessagePort();
        $ada = $this->arrive('Ada', '0412345678');
        $this->arrive('Ben', '0498765432');
        $drill = $this->start(RollCall::KIND_DRILL);
        Http::assertNothingSent();

        $roll = app(RollCalls::class);
        $roll->mark($drill->entries()->where('visitor_id', $ada->visitorId)->sole(), RollCallEntry::ACCOUNTED, $this->warden);
        $roll->addManual($drill, 'Zoe Found', 'Cleaner', $this->warden);
        $this->arrive('Cara', '0491578888'); // came in after the drill started

        Livewire::actingAs($this->warden)->test(OnSitePage::class)->assertSeeHtml('data-test="escalate"');

        $emergency = $this->start(RollCall::KIND_EMERGENCY);

        $this->assertFalse($emergency->is($drill));
        $this->assertSame(RollCall::KIND_EMERGENCY, $emergency->kind);
        $this->assertFalse($drill->fresh()->isOpen());
        $this->assertSame($this->warden->id, $drill->fresh()->ended_by_user_id);
        $entries = $emergency->entries()->get()->keyBy('first_name');
        $this->assertSame(['Ada', 'Ben', 'Cara', 'Zoe Found'], $entries->keys()->sort()->values()->all());
        $this->assertSame([RollCallEntry::ACCOUNTED, RollCallEntry::UNACCOUNTED, RollCallEntry::UNACCOUNTED, RollCallEntry::ACCOUNTED],
            [$entries['Ada']->status, $entries['Ben']->status, $entries['Cara']->status, $entries['Zoe Found']->status]);
        $this->assertSame($this->warden->id, $entries['Ada']->status_changed_by_user_id);

        // One emergency broadcast to everyone on site now, Cara included.
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => collect($request->data()['messages'])->pluck('to')->sort()->values()->all() === ['+61412345678', '+61491578888', '+61498765432']
            && str_starts_with($request->data()['messages'][0]['body'], 'EMERGENCY'));
        $this->assertSame(['emergency_roll_call_id' => $emergency->id, 'marks_kept' => 2],
            AuditEvent::query()->where('action', 'roll_call.escalated')->sole()->context);

        // Pressing again (another warden) opens the same emergency and sends nothing more.
        $this->assertTrue($this->start(RollCall::KIND_EMERGENCY)->is($emergency));
        $this->assertTrue(app(RollCalls::class)->escalate($drill->fresh(), $this->warden)->is($emergency));
        Http::assertSentCount(1);

        // Wardens still on the drill's board are sent on to the emergency.
        Livewire::actingAs($this->warden)->test(RollCallPage::class, ['rollCall' => $drill])
            ->assertSeeHtml('data-test="open-now"')->assertSee(route('reception.roll-calls.show', $emergency));
    }

    public function test_the_drill_board_escalates_and_people_texted_about_the_drill_get_the_emergency_text(): void
    {
        $this->fakeMessagePort();
        $this->arrive('Ada', '0412345678');
        $drill = $this->start(RollCall::KIND_DRILL, sendDrillMessages: true);
        Http::assertSentCount(1);

        Livewire::actingAs($this->warden)->test(RollCallPage::class, ['rollCall' => $drill])
            ->assertSeeHtml('data-test="escalate"')
            ->call('escalate')
            ->assertRedirect(route('reception.roll-calls.show', RollCall::query()->where('kind', RollCall::KIND_EMERGENCY)->sole()));

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->data()['messages'][0]['body'], 'EMERGENCY'));
        $this->assertSame(1, RollCall::query()->whereNull('ended_at')->count());

        // An emergency board has no escalate button.
        Livewire::actingAs($this->warden)->test(RollCallPage::class, ['rollCall' => RollCall::query()->whereNull('ended_at')->sole()])
            ->assertDontSeeHtml('data-test="escalate"');
    }

    public function test_the_email_is_markdown_safe_and_short(): void
    {
        $mail = (new EmergencyBroadcastMail('[Click](https://evil.example)', true))->locale('en');

        $html = $mail->render();

        $this->assertStringNotContainsString('href="https://evil.example"', $html);
        $this->assertStringContainsString('DRILL', $html);
    }

    public function test_a_drill_sends_nothing_unless_switched_on(): void
    {
        $this->fakeMessagePort();
        $this->arrive('Ada', '0412345678', 'ada@example.org');

        $rollCall = $this->start(RollCall::KIND_DRILL);

        $this->assertNull($rollCall->fresh()->broadcast_at);
        Http::assertNothingSent();
        Mail::assertNotSent(EmergencyBroadcastMail::class);
        $this->assertSame(0, EmergencyNotification::query()->count());
    }

    public function test_a_drill_with_messages_switched_on_is_marked_as_a_drill(): void
    {
        $this->fakeMessagePort();
        $this->arrive('Ada', '0412345678', 'ada@example.org');

        Livewire::actingAs($this->warden)->test(OnSitePage::class)
            ->assertSee(__('Übungsnachrichten an alle Angemeldeten senden'))
            ->call('startRollCall', 'drill', true);

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->data()['messages'][0]['body'], 'DRILL - no real emergency. North Campus: please leave now')
            && SmsText::gsmLength($request->data()['messages'][0]['body']) !== null);
        Mail::assertSent(EmergencyBroadcastMail::class, fn (EmergencyBroadcastMail $mail): bool => $mail->drill && $mail->envelope()->subject === 'Drill at North Campus');
    }

    public function test_dry_run_uses_the_dry_endpoint(): void
    {
        config(['messageport.dry_run' => true]);
        Http::fake(['app.messageport.com.au/*' => Http::response(['data' => ['messages' => [], 'errors' => []]])]);
        $this->arrive('Ada', '61491578888');

        $this->start(RollCall::KIND_EMERGENCY);

        Http::assertSent(fn (Request $request): bool => $request->url() === self::SEND_URL.'/dry' && $request->data()['messages'][0]['to'] === '+61491578888');
    }

    public function test_per_message_errors_are_stored_as_failed(): void
    {
        $this->fakeMessagePort(['61491570156' => 'INVALID_RECIPIENT', '61491570157' => 'RECIPIENT_OPTED_OUT']);
        $ok = $this->arrive('Ada', '0491578888');
        $invalid = $this->arrive('Ben', '0491570156');
        $optedOut = $this->arrive('Cara', '0491570157');

        $this->start(RollCall::KIND_EMERGENCY);

        $this->assertSame('accepted', $this->smsRow($ok)->status);
        $this->assertSame(['failed', 'INVALID_RECIPIENT'], [$this->smsRow($invalid)->status, $this->smsRow($invalid)->error_code]);
        $this->assertSame(['failed', 'RECIPIENT_OPTED_OUT'], [$this->smsRow($optedOut)->status, $this->smsRow($optedOut)->error_code]);
    }

    public function test_a_rejected_api_key_is_shown_as_failed_and_the_roll_call_still_starts(): void
    {
        $this->fakeMessagePort(status: 401);
        $ada = $this->arrive('Ada', '0412345678');

        Livewire::actingAs($this->warden)->test(OnSitePage::class)
            ->call('startRollCall', 'emergency')
            ->assertRedirect();

        $rollCall = RollCall::query()->sole();
        $this->assertTrue($rollCall->isOpen());
        $this->assertSame(1, $rollCall->entries()->count());
        $this->assertSame(['failed', 'HTTP_401'], [$this->smsRow($ada)->status, $this->smsRow($ada)->error_code]);

        Livewire::actingAs($this->warden)->test(RollCallPage::class, ['rollCall' => $rollCall])
            ->assertSee(__('SMS fehlgeschlagen'))
            ->assertSee(__('SMS: :delivered zugestellt, :sending ausstehend, :failed fehlgeschlagen, :none ohne Handynummer', ['delivered' => 0, 'sending' => 0, 'failed' => 1, 'none' => 0]));
    }

    public function test_missing_credentials_fail_gracefully(): void
    {
        config(['messageport.access_token' => null]);
        Http::fake();
        $ada = $this->arrive('Ada', '0412345678');

        $this->start(RollCall::KIND_EMERGENCY);

        Http::assertNothingSent();
        $this->assertSame(['failed', 'NOT_CONFIGURED'], [$this->smsRow($ada)->status, $this->smsRow($ada)->error_code]);
    }

    public function test_nothing_is_sent_when_emergency_messages_are_switched_off(): void
    {
        config(['emergency.sms_enabled' => false, 'emergency.email_enabled' => false]);
        Http::fake();
        $this->arrive('Ada', '0412345678', 'ada@example.org');

        Livewire::actingAs($this->warden)->test(OnSitePage::class)
            ->assertDontSee(__('Übungsnachrichten an alle Angemeldeten senden'));

        $rollCall = $this->start(RollCall::KIND_EMERGENCY);

        $this->assertNull($rollCall->fresh()->broadcast_at);
        Http::assertNothingSent();
        Mail::assertNotSent(EmergencyBroadcastMail::class);
        $this->assertSame(0, EmergencyNotification::query()->count());
    }

    public function test_sms_only_sends_no_email(): void
    {
        config(['emergency.email_enabled' => false]);
        $this->fakeMessagePort();
        $this->arrive('Ada', '0412345678', 'ada@example.org');

        $this->start(RollCall::KIND_EMERGENCY);

        Http::assertSentCount(1);
        Mail::assertNotSent(EmergencyBroadcastMail::class);
    }

    public function test_delivery_receipts_update_the_status_idempotently(): void
    {
        $this->fakeMessagePort();
        $ada = $this->arrive('Ada', '0412345678');
        $rollCall = $this->start(RollCall::KIND_EMERGENCY);
        $reference = $this->smsRow($ada)->reference;
        $receipt = fn (string $status): array => ['event' => 'message_dlr', 'date' => now()->toIso8601String(), 'id' => 123456, 'to' => '61412345678', 'from' => 'TestSender', 'content' => 'x', 'parts' => 1, 'cost' => 1, 'reference' => $reference, 'metadata' => [], 'campaign_id' => 1, 'status' => $status];

        $this->webhook($receipt('sent'))->assertOk();
        $this->assertSame('sent', $this->smsRow($ada)->status);

        $this->webhook($receipt('delivered'))->assertOk();
        $this->webhook($receipt('delivered'))->assertOk(); // retry
        $this->webhook($receipt('sent'))->assertOk(); // late, out of order: no downgrade
        $this->assertSame('delivered', $this->smsRow($ada)->status);
        $this->assertSame('123456', $this->smsRow($ada)->provider_message_id);

        Livewire::actingAs($this->warden)->test(RollCallPage::class, ['rollCall' => $rollCall])
            ->assertSee(__('SMS zugestellt'))
            ->assertSee(__('SMS: :delivered zugestellt, :sending ausstehend, :failed fehlgeschlagen, :none ohne Handynummer', ['delivered' => 1, 'sending' => 0, 'failed' => 0, 'none' => 0]));
    }

    public function test_failed_receipts_are_stored_as_failed(): void
    {
        $this->fakeMessagePort();
        $ada = $this->arrive('Ada', '0412345678');
        $this->start(RollCall::KIND_EMERGENCY);

        $this->webhook(['event' => 'message_dlr', 'id' => 9, 'reference' => $this->smsRow($ada)->reference, 'status' => 'failed_expired'])->assertOk();

        $this->assertSame(['failed', 'FAILED_EXPIRED'], [$this->smsRow($ada)->status, $this->smsRow($ada)->error_code]);
    }

    public function test_webhooks_with_a_bad_signature_or_unknown_reference_change_nothing(): void
    {
        $this->fakeMessagePort();
        $ada = $this->arrive('Ada', '0412345678');
        $this->start(RollCall::KIND_EMERGENCY);
        $payload = ['event' => 'message_dlr', 'id' => 1, 'reference' => $this->smsRow($ada)->reference, 'status' => 'delivered'];

        $this->webhook($payload, 'wrong-secret')->assertStatus(401);
        $this->webhook($payload, null)->assertStatus(401);
        config(['messageport.webhook_secret' => '']);
        $this->webhook($payload, '')->assertStatus(401);
        config(['messageport.webhook_secret' => 'test-webhook-secret']);
        $this->assertSame('accepted', $this->smsRow($ada)->status);

        $this->webhook(['event' => 'message_dlr', 'id' => 2, 'reference' => 'rc999-e999', 'status' => 'delivered'])->assertOk()->assertJson(['ignored' => true]);
        $this->webhook(['event' => 'message_received', 'id' => 3, 'reference' => $payload['reference'], 'status' => 'delivered'])->assertOk()->assertJson(['ignored' => true]);
        $this->assertSame('accepted', $this->smsRow($ada)->status);
    }

    public function test_the_board_shows_sending_and_no_mobile(): void
    {
        $this->fakeMessagePort();
        $this->arrive('Ada', '0412345678');
        $this->arrive('Ben', null);
        $rollCall = $this->start(RollCall::KIND_EMERGENCY);

        Livewire::actingAs($this->warden)->test(RollCallPage::class, ['rollCall' => $rollCall])
            ->assertSee(__('SMS wird gesendet'))
            ->assertSee(__('Keine Handynummer'))
            ->assertSee(__('SMS: :delivered zugestellt, :sending ausstehend, :failed fehlgeschlagen, :none ohne Handynummer', ['delivered' => 0, 'sending' => 1, 'failed' => 0, 'none' => 1]));
    }

    public function test_the_board_shows_nothing_for_a_silent_drill(): void
    {
        $this->arrive('Ada', '0412345678');
        $rollCall = $this->start(RollCall::KIND_DRILL);

        Livewire::actingAs($this->warden)->test(RollCallPage::class, ['rollCall' => $rollCall])
            ->assertDontSee(__('SMS wird gesendet'))
            ->assertDontSee('data-test="message-summary"', false);
    }

    public function test_phone_numbers_are_normalised_to_e164_mobiles(): void
    {
        $cases = [
            '0412 345 678' => '+61412345678',
            '0412-345-678' => '+61412345678',
            '(04) 1234 5678' => '+61412345678',
            '412345678' => '+61412345678',
            '61412345678' => '+61412345678',
            '+61 412 345 678' => '+61412345678',
            '+61 (0)412 345 678' => '+61412345678',
            '0061412345678' => '+61412345678',
            '+64 21 123 4567' => '+64211234567',
            '+44 7700 900123' => '+447700900123',
            '08 9123 4567' => null,
            '+61 8 9123 4567' => null,
            '0412 345' => null,
            'call me' => null,
            '' => null,
            '+12' => null,
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, PhoneNumber::toMobileE164((string) $input), 'Input: '.$input);
        }

        $this->assertSame('********678', PhoneNumber::mask('+61412345678'));
    }

    public function test_sms_text_stays_in_one_segment_by_shortening_the_site_name(): void
    {
        $broadcast = app(EmergencyBroadcast::class);
        $long = str_repeat('Very Long Campus Name ', 6);

        foreach (['de', 'en', 'fr'] as $locale) {
            foreach ([true, false] as $drill) {
                $text = $broadcast->smsText($drill, $long, $locale);
                $this->assertTrue(SmsText::fitsOneSegment($text), $locale.': '.$text);
                $this->assertStringContainsString('Very Long Campus', $text);
            }
        }

        $this->assertSame(161, SmsText::gsmLength(str_repeat('a', 159).'€'));
        $this->assertNull(SmsText::gsmLength('Příliš'));
        $this->assertSame('DRILL - a', SmsText::sanitize('DRILL – a'));
    }

    public function test_admin_settings_show_whether_emergency_sms_is_configured(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        config(['messageport.sender_id' => 'SchoolAlert']);

        Livewire::actingAs($admin)->test(ManageOrganisationSettings::class)
            ->assertSee(__('Notfallnachrichten an Besucher'))
            ->assertSee(__('SMS: eingeschaltet über MessagePort, Absender :sender.', ['sender' => 'SchoolAlert']))
            ->assertDontSee('test-access-token')
            ->assertDontSee('test-webhook-secret');

        config(['messageport.token_id' => '']);
        $this->assertStringContainsString('MESSAGEPORT_TOKEN_ID', implode(' ', EmergencyBroadcast::statusLines()));
    }
}
