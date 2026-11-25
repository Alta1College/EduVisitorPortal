<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use App\Kiosk\Kiosk;
use App\Kiosk\KioskDevices;
use App\Kiosk\Livewire\KioskScreen;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\PublicVisit\ReturningVisitorCodeMail;
use App\PublicVisit\ReturningVisitorCodes;
use App\PublicVisit\ReturningVisitors;
use App\QrCheckIn\Livewire\MobileSignIn;
use App\QrCheckIn\SiteQrLinks;
use App\Settings\SettingsRepository;
use App\VisitorWorkflow\Models\FormField;
use App\VisitorWorkflow\Models\FormFieldAssignment;
use App\VisitorWorkflow\Models\VisitorType;
use App\VisitorWorkflow\Models\VisitResponse;
use App\VisitorWorkflow\WorkflowRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ReturningVisitorTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private Site $site;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();
        Storage::fake(WorkflowRecorder::EVIDENCE_DISK);
        $this->site = Site::factory()->create();
        $reception = User::factory()->create(['site_id' => $this->site->getKey(), 'is_active' => true]);
        app(SettingsRepository::class)->saveSite($this->site, ['organisation.reception_host_user_id' => $reception->getKey()], null);
        $type = VisitorType::query()->create(['name' => 'Contractor', 'slug' => 'contractor', 'host_requirement' => VisitorType::HOST_NONE,
            'detail_fields' => ['email' => ['mode' => 'optional'], 'phone' => ['mode' => 'required'], 'company' => ['mode' => 'required']]]);

        // Remembered: WWCC number and photo. Asked every time: reason for visit.
        foreach ([
            ['key' => 'wwcc', 'label' => 'WWCC number', 'type' => FormField::TYPE_SHORT_TEXT, 'remember_for_returning' => true],
            ['key' => 'reason', 'label' => 'Reason for visit', 'type' => FormField::TYPE_SHORT_TEXT],
            ['key' => 'photo', 'label' => 'Photo', 'type' => FormField::TYPE_PHOTO, 'remember_for_returning' => true],
        ] as $order => $attributes) {
            $field = FormField::query()->create($attributes);
            FormFieldAssignment::query()->create(['form_field_id' => $field->getKey(), 'visitor_type_id' => $type->getKey(), 'is_required' => true, 'sort_order' => $order]);
        }

        $kiosk = Kiosk::query()->create(['site_id' => $this->site->getKey(), 'name' => 'Front']);
        [, $this->secret] = app(KioskDevices::class)->enrol(app(KioskDevices::class)->issueEnrolmentCode($kiosk));
    }

    private function kiosk()
    {
        return Livewire::withCookie(KioskDevices::COOKIE, $this->secret)->test(KioskScreen::class);
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('me.png', base64_decode(self::PNG_1X1));
    }

    /** First visit at the kiosk: everything typed, photo taken. */
    private function firstVisit(): Visitor
    {
        $this->kiosk()->call('chooseType', 'contractor')->assertSet('step', 'identify')
            ->set('lookupIdentifier', 'casey@spark.example')->call('lookupReturning')
            ->assertSet('step', 'details')->assertSet('details.email', 'casey@spark.example')
            ->set('details.first_name', 'Casey')->set('details.name', 'Contractor')
            ->set('details.phone', '0412 345 678')->set('details.company', 'Spark Electrical')
            ->call('submitDetails')->assertHasNoErrors()->assertSet('step', 'questions')
            ->set('answers.wwcc', 'WWC-123456')->set('answers.reason', 'Wiring')->set('answers.photo', $this->photo())
            ->call('submitQuestions')->assertHasNoErrors()->assertSet('step', 'confirm')
            ->call('finish')->assertSet('step', 'done');

        return Visitor::query()->sole();
    }

    public function test_the_kiosk_shows_a_returning_record_masked_and_reuses_it_unchanged(): void
    {
        $visitor = $this->firstVisit();

        $screen = $this->kiosk()->call('chooseType', 'contractor')
            // Mobile number written another way: an exact match after normalising.
            ->set('lookupIdentifier', '+61 412 345 678')->call('lookupReturning')
            ->assertSet('step', 'details')
            ->assertSee(__('Willkommen zurück, :name!', ['name' => 'Casey']))
            ->assertSee('c•••@s•••.example')->assertSee('•••• 678')
            ->assertDontSee('casey@spark.example')->assertDontSee('Spark Electrical')
            ->assertDontSee('kiosk/returning-photo', false)
            ->call('confirmReturning')
            ->assertSet('details.phone', null)->assertSet('savedFields', ['first_name', 'name', 'email', 'phone', 'company'])
            ->assertSee(__('Angaben ändern'))
            // Without the emailed code, typing cannot change the saved record or answers.
            ->set('details.company', 'Somebody Else Pty Ltd')
            ->call('submitDetails')->assertHasNoErrors()->assertSet('step', 'questions')
            ->assertSet('answers', [])->assertSet('savedAnswerKeys', ['wwcc', 'photo'])
            ->set('answers.wwcc', 'FAKE-999')
            ->call('submitQuestions')->assertHasErrors('answers.reason')
            ->set('answers.reason', 'Testing alarms')
            ->call('submitQuestions')->assertHasNoErrors()->assertSet('step', 'confirm')
            ->call('finish')->assertSet('step', 'done');

        $snapshot = json_encode($screen->snapshot['data']);
        foreach (['casey@spark.example', '345 678', 'Spark Electrical', 'WWC-123456'] as $secret) {
            $this->assertStringNotContainsString($secret, $snapshot);
        }

        $this->assertSame(1, Visitor::query()->count());
        $this->assertSame('Spark Electrical', $visitor->fresh()->company);
        $this->assertSame(2, DB::table('visit_visitor')->where('visitor_id', $visitor->getKey())->count());
        $this->assertSame(['WWC-123456', 'WWC-123456'], VisitResponse::query()->where('field_key', 'wwcc')->orderBy('id')->get()->map->plainValue()->all());

        // The second visit keeps its own copy of the photo (evidence is purged per visit).
        $photos = VisitResponse::query()->where('field_key', 'photo')->orderBy('id')->pluck('file_path');
        $this->assertCount(2, $photos);
        $this->assertNotSame($photos[0], $photos[1]);
        Storage::disk(WorkflowRecorder::EVIDENCE_DISK)->assertExists($photos[1]);
        $this->assertSame('Testing alarms', VisitResponse::query()->where('field_key', 'reason')->latest('id')->value('value'));
    }

    public function test_the_lookup_accepts_only_an_email_address_or_a_mobile_number(): void
    {
        $this->firstVisit();

        foreach (['Casey Contractor', '345 678', 'casey@spark'] as $notAnIdentifier) {
            $this->kiosk()->call('chooseType', 'contractor')
                ->set('lookupIdentifier', $notAnIdentifier)->call('lookupReturning')
                ->assertHasErrors('lookup')->assertSet('step', 'identify')->assertDontSee('Casey');
        }

        // Valid but unknown: carried into the form, nothing revealed.
        $this->kiosk()->call('chooseType', 'contractor')
            ->set('lookupIdentifier', '0499 000 111')->call('lookupReturning')
            ->assertHasNoErrors()->assertSet('step', 'details')->assertSet('pendingReturningToken', null)
            ->assertSet('details.phone', '0499 000 111');
        $this->kiosk()->call('chooseType', 'contractor')
            ->set('lookupIdentifier', 'nobody@spark.example')->call('lookupReturning')
            ->assertSet('pendingReturningToken', null)->assertSet('details.email', 'nobody@spark.example');
    }

    public function test_changing_saved_details_needs_the_code_sent_to_the_saved_email(): void
    {
        $visitor = $this->firstVisit();

        $screen = $this->kiosk()->call('chooseType', 'contractor')
            ->set('lookupIdentifier', 'Casey@Spark.example')->call('lookupReturning')->call('confirmReturning')
            ->call('sendReturningCode')->assertHasNoErrors()
            ->assertSee('c•••@s•••.example');

        Mail::assertSent(ReturningVisitorCodeMail::class, fn (ReturningVisitorCodeMail $mail): bool => $mail->hasTo('casey@spark.example'));
        $code = Mail::sent(ReturningVisitorCodeMail::class)->last()->code;
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $code);

        $wrong = $code === '000000' ? '111111' : '000000';
        $screen->set('verificationCode', $wrong)->call('verifyReturningCode')
            ->assertHasErrors('verificationCode')->assertSet('returningVerified', false)
            ->set('verificationCode', $code)->call('verifyReturningCode')
            ->assertHasNoErrors()->assertSet('returningVerified', true)
            ->assertSet('details.company', 'Spark Electrical')->assertSee('kiosk/returning-photo', false)
            ->set('details.company', 'Bright Sparks')
            ->call('submitDetails')->assertSet('answers.reason', null)
            ->set('answers.reason', 'Again')->set('answers.wwcc', 'WWC-777777')->call('submitQuestions')
            ->call('finish')->assertSet('step', 'done');

        $this->assertSame('Bright Sparks', $visitor->fresh()->company);
        $this->assertSame('WWC-777777', VisitResponse::query()->where('field_key', 'wwcc')->latest('id')->first()->plainValue());
        $this->assertDatabaseHas('audit_events', ['action' => 'returning_visitor.code_sent']);
        $this->assertDatabaseHas('audit_events', ['action' => 'returning_visitor.code_failed']);
        $this->assertDatabaseHas('audit_events', ['action' => 'returning_visitor.verified']);

        // The earlier, forgotten sign-in is closed.
        $rows = DB::table('visit_visitor')->orderBy('checked_in_at')->orderBy('visit_id')->get();
        $this->assertCount(2, $rows);
        $this->assertNotNull($rows[0]->checked_out_at);
        $this->assertNull($rows[1]->checked_out_at);
        $this->assertDatabaseHas('audit_events', ['action' => 'public.arrival.closed_previous']);
    }

    public function test_codes_cannot_be_guessed_or_reused(): void
    {
        $this->firstVisit();

        $screen = $this->kiosk()->call('chooseType', 'contractor')
            ->set('lookupIdentifier', 'casey@spark.example')->call('lookupReturning')->call('confirmReturning')
            ->call('sendReturningCode');
        $code = Mail::sent(ReturningVisitorCodeMail::class)->last()->code;
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < ReturningVisitorCodes::MAX_ATTEMPTS; $i++) {
            $screen->set('verificationCode', $wrong)->call('verifyReturningCode');
        }

        // Void after too many tries: even the right code no longer works.
        $screen->assertSet('codeChallenge', null)
            ->set('verificationCode', $code)->call('verifyReturningCode')->assertSet('returningVerified', false);

        // A forged verified flag without a verified token changes nothing.
        $this->assertFalse(app(ReturningVisitors::class)->isVerified($screen->get('returningToken')));

        // At most three codes per person in a quarter of an hour.
        $screen->call('sendReturningCode')->call('sendReturningCode')->call('sendReturningCode')
            ->assertHasErrors('verificationCode');
        Mail::assertSent(ReturningVisitorCodeMail::class, 3);
    }

    public function test_without_a_saved_email_details_cannot_be_changed_on_screen(): void
    {
        $visitor = $this->firstVisit();
        $visitor->forceFill(['email' => null])->save();

        $this->kiosk()->call('chooseType', 'contractor')
            ->set('lookupIdentifier', '0412345678')->call('lookupReturning')->call('confirmReturning')
            ->assertSee(__('Um Ihre gespeicherten Angaben zu ändern, wenden Sie sich bitte an den Empfang.'))
            ->call('sendReturningCode')->assertHasErrors('verificationCode');

        Mail::assertNotSent(ReturningVisitorCodeMail::class);
    }

    public function test_declining_starts_fresh_and_the_feature_can_be_turned_off(): void
    {
        $this->firstVisit();

        $this->kiosk()->call('chooseType', 'contractor')
            ->set('lookupIdentifier', 'casey@spark.example')->call('lookupReturning')->call('declineReturning')
            ->assertSet('pendingReturningToken', null)->assertSet('returningToken', null)
            ->assertSet('details.email', null)
            ->assertDontSee(__('Willkommen zurück, :name!', ['name' => 'Casey']));

        app(SettingsRepository::class)->saveOrganisation(['organisation.visitor_experience.returning_visitors' => '0'], null);

        $this->kiosk()->call('chooseType', 'contractor')->assertSet('step', 'details')
            ->call('lookupReturning')->assertNotFound();
    }

    public function test_a_phone_uses_the_record_without_showing_it(): void
    {
        $this->firstVisit();
        $link = app(SiteQrLinks::class)->forSite($this->site);

        $screen = Livewire::test(MobileSignIn::class, ['token' => $link->token])
            ->call('chooseType', 'contractor')->assertSet('step', 'identify')
            ->set('lookupIdentifier', 'casey@spark.example')->call('lookupReturning')
            ->assertSee(__('Willkommen zurück, :name!', ['name' => 'Casey']))
            ->assertSee('c•••@s•••.example')->assertSee('•••• 678')
            ->assertDontSee('Contractor')->assertDontSee('returning-photo')
            ->assertSee(__('Das bin ich nicht'))
            ->call('confirmReturning')
            ->call('submitDetails')->assertHasNoErrors()->assertSet('step', 'questions')
            ->assertSet('answers', [])->assertSet('savedAnswerKeys', ['wwcc', 'photo']);

        $snapshot = json_encode($screen->snapshot['data']);
        // Whole values only: a short fragment such as '345' can turn up by chance in the
        // snapshot's random tokens and checksums.
        foreach (['casey@spark.example', '0412 345 678', '0412345678', 'Spark', 'WWC-123456', 'Contractor'] as $secret) {
            $this->assertStringNotContainsString($secret, $snapshot);
        }

        $screen->set('answers.reason', 'Phone visit')->call('submitQuestions')->assertHasNoErrors()
            ->call('finish')->assertSet('step', 'done');

        $this->assertSame(1, Visitor::query()->count());
        $this->assertSame(2, VisitResponse::query()->where('field_key', 'wwcc')->count());
    }

    public function test_the_phone_keeps_nothing_and_always_looks_up_the_database(): void
    {
        $link = app(SiteQrLinks::class)->forSite($this->site);

        Livewire::test(MobileSignIn::class, ['token' => $link->token])
            ->call('chooseType', 'contractor')->call('lookupReturning')
            ->set('details.first_name', 'Robin')->set('details.name', 'Phone')
            ->set('details.phone', '0499 111 222')->set('details.company', 'Acme')
            ->call('submitDetails')
            ->set('answers.wwcc', 'W-1')->set('answers.reason', 'R')->set('answers.photo', $this->photo())
            ->call('submitQuestions')->call('finish')->assertSet('step', 'done');

        // Only the sign-out cookie of the open visit: no saved details on the phone.
        $this->assertSame([MobileSignIn::DEPARTURE_COOKIE], array_values(array_map(fn ($cookie) => $cookie->getName(), Cookie::getQueuedCookies())));

        $departure = Cookie::queued(MobileSignIn::DEPARTURE_COOKIE)->getValue();
        Livewire::withCookie(MobileSignIn::DEPARTURE_COOKIE, $departure)->test(MobileSignIn::class, ['token' => $link->token])
            ->call('chooseType', 'contractor')->assertSet('step', 'identify')->assertSet('pendingReturningToken', null)
            ->set('lookupIdentifier', '0499111222')->call('lookupReturning')
            ->assertSee(__('Willkommen zurück, :name!', ['name' => 'Robin']))->assertSee('•••• 222')
            // Someone else on a borrowed phone: back to the lookup, nothing kept.
            ->call('declineReturning')->assertSet('pendingReturningToken', null)->assertSet('step', 'identify');
    }

    public function test_the_saved_photo_is_served_only_to_kiosks_of_the_site(): void
    {
        $this->firstVisit();
        $path = VisitResponse::query()->where('field_key', 'photo')->value('file_path');
        $returning = app(ReturningVisitors::class);
        $url = route('kiosk.returning-photo', ['token' => $returning->photoToken($path, $this->site->getKey())]);

        $this->get($url)->assertRedirect(route('kiosk.enrol'));
        $this->withCookie(KioskDevices::COOKIE, $this->secret)->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $otherSite = route('kiosk.returning-photo', ['token' => $returning->photoToken($path, $this->site->getKey() + 1)]);
        $this->withCookie(KioskDevices::COOKIE, $this->secret)->get($otherSite)->assertNotFound();
        $this->withCookie(KioskDevices::COOKIE, $this->secret)->get(route('kiosk.returning-photo', ['token' => 'nope']))->assertNotFound();
    }

    public function test_a_camera_snapshot_is_stored_as_the_photo(): void
    {
        // What the in-page camera sends: a JPEG data URL (a 1×1 image here).
        $snapshot = 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==';

        $this->kiosk()->call('chooseType', 'contractor')->call('lookupReturning')
            ->set('details.first_name', 'Cam')->set('details.name', 'Era')
            ->set('details.phone', '0400 000 000')->set('details.company', 'Lens Co')
            ->call('submitDetails')->assertSee(__('Kamera öffnen'))
            ->set('answers.wwcc', 'W-9')->set('answers.reason', 'Testing')->set('answers.photo', $snapshot)
            ->call('submitQuestions')->assertHasNoErrors()->assertSet('step', 'confirm')
            ->call('finish')->assertSet('step', 'done');

        $path = VisitResponse::query()->where('field_key', 'photo')->value('file_path');
        $this->assertStringEndsWith('.jpg', $path);
        Storage::disk(WorkflowRecorder::EVIDENCE_DISK)->assertExists($path);

        // Anything that is not a real image is refused.
        $this->kiosk()->call('chooseType', 'contractor')->call('lookupReturning')
            ->set('details.first_name', 'Bad')->set('details.name', 'Data')
            ->set('details.phone', '0400 000 001')->set('details.company', 'X')
            ->call('submitDetails')
            ->set('answers.wwcc', 'W-9')->set('answers.reason', 'Testing')->set('answers.photo', 'data:image/jpeg;base64,'.base64_encode('<script>no</script>'))
            ->call('submitQuestions')->assertHasErrors('answers.photo');
    }

    public function test_masks(): void
    {
        $this->assertSame('c•••@s•••.example', ReturningVisitors::maskEmail('casey@spark.example'));
        $this->assertSame('•••• 678', ReturningVisitors::maskPhone('0412 345 678'));
        $this->assertNull(ReturningVisitors::maskPhone('123'));
    }
}
