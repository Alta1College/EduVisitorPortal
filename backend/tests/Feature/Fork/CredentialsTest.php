<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use App\Audit\AuditEvent;
use App\Credentials\CredentialImportFile;
use App\Credentials\CredentialRegister;
use App\Credentials\CredentialType;
use App\Credentials\Livewire\CredentialsPage;
use App\Credentials\VisitorCredential;
use App\Kiosk\Kiosk;
use App\Kiosk\KioskDevices;
use App\Kiosk\Livewire\KioskScreen;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\PublicVisit\ReturningVisitorCodeMail;
use App\Settings\SettingsRepository;
use App\Support\VisitorPortalPermissions;
use App\VisitorWorkflow\Models\FormField;
use App\VisitorWorkflow\Models\FormFieldAssignment;
use App\VisitorWorkflow\Models\VisitorType;
use App\VisitorWorkflow\Models\VisitResponse;
use App\VisitorWorkflow\WorkflowRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class CredentialsTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private CredentialType $wwcc;

    private string $secret;

    private User $receptionist;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();
        Storage::fake(WorkflowRecorder::EVIDENCE_DISK);
        VisitorPortalPermissions::sync();

        $this->site = Site::factory()->create();
        $this->receptionist = User::factory()->create(['site_id' => $this->site->getKey(), 'is_active' => true]);
        $this->receptionist->assignRole('receptionist');
        app(SettingsRepository::class)->saveSite($this->site, ['organisation.reception_host_user_id' => $this->receptionist->getKey()], null);

        $this->wwcc = CredentialType::query()->create(['name' => 'Working with Children Check', 'verification_url' => 'https://checks.example/validate', 'expiry_warning_days' => 30]);
        $type = VisitorType::query()->create(['name' => 'Relief Staff', 'slug' => 'relief', 'host_requirement' => VisitorType::HOST_NONE,
            'detail_fields' => ['email' => ['mode' => 'optional'], 'phone' => ['mode' => 'optional'], 'company' => ['mode' => 'hidden'], 'email_or_phone' => true]]);
        $field = FormField::query()->create(['key' => 'wwcc', 'label' => 'WWCC number', 'type' => FormField::TYPE_SHORT_TEXT,
            'is_sensitive' => true, 'remember_for_returning' => true, 'credential_type_id' => $this->wwcc->getKey()]);
        FormFieldAssignment::query()->create(['form_field_id' => $field->getKey(), 'visitor_type_id' => $type->getKey(), 'is_required' => true, 'sort_order' => 1]);

        $kiosk = Kiosk::query()->create(['site_id' => $this->site->getKey(), 'name' => 'Front']);
        [, $this->secret] = app(KioskDevices::class)->enrol(app(KioskDevices::class)->issueEnrolmentCode($kiosk));
    }

    private function signIn(string $email, string $number, string $first = 'Robin', string $last = 'Relief'): void
    {
        $screen = Livewire::withCookie(KioskDevices::COOKIE, $this->secret)->test(KioskScreen::class)
            ->call('chooseType', 'relief')
            ->set('lookupIdentifier', $email)->call('lookupReturning');

        if ($screen->get('step') === 'identify' || $screen->get('pendingReturningToken') !== null) {
            // A returning visitor proves the record with the emailed code before changing it.
            $screen->call('confirmReturning')->call('sendReturningCode')
                ->set('verificationCode', Mail::sent(ReturningVisitorCodeMail::class)->last()->code)
                ->call('verifyReturningCode')->assertSet('returningVerified', true);
        }

        $screen->set('details.first_name', $first)->set('details.name', $last)->set('details.email', $email)
            ->call('submitDetails')->assertHasNoErrors()->assertSet('step', 'questions')
            ->set('answers.wwcc', $number)
            ->call('submitQuestions')->assertHasNoErrors()
            ->call('finish')->assertSet('step', 'done');
    }

    private function page()
    {
        return Livewire::actingAs($this->receptionist)->test(CredentialsPage::class);
    }

    public function test_a_sign_in_answer_fills_the_credential_record_encrypted(): void
    {
        $this->signIn('robin@relief.example', '0123 456A');

        $credential = VisitorCredential::query()->sole();
        $this->assertSame('0123 456A', $credential->number);
        $this->assertSame(VisitorCredential::UNCHECKED, $credential->state());
        $this->assertSame(VisitorCredential::SOURCE_SIGN_IN, $credential->source);
        $this->assertStringNotContainsString('0123', (string) DB::table('visitor_credentials')->value('number'));
        $this->assertSame(CredentialRegister::hash('0123456a'), $credential->number_hash);

        // Never in the audit log.
        $this->assertDatabaseHas('audit_events', ['action' => 'credential.recorded']);
        $this->assertStringNotContainsString('456A', (string) DB::table('audit_events')->pluck('context')->implode(' '));
    }

    public function test_reception_records_a_check_and_a_new_number_needs_checking_again(): void
    {
        $this->signIn('robin@relief.example', '0123456A');
        $credential = VisitorCredential::query()->sole();

        $this->page()
            ->assertSee('Robin Relief')->assertSee('0123456A')->assertSee('https://checks.example/validate')
            ->set("expires.{$credential->id}", now()->addYears(2)->toDateString())
            ->call('markValid', $credential->id)->assertHasNoErrors();

        $credential->refresh();
        $this->assertSame(VisitorCredential::VALID, $credential->state());
        $this->assertSame($this->receptionist->getKey(), $credential->checked_by_user_id);
        $this->assertDatabaseHas('audit_events', ['action' => 'credential.checked']);

        // Same number again (written differently): the check stands.
        $this->signIn('robin@relief.example', '0123 456a');
        $this->assertSame(VisitorCredential::VALID, $credential->refresh()->state());

        // A new number: unchecked again.
        $this->signIn('robin@relief.example', '9999999B');
        $this->assertSame(VisitorCredential::UNCHECKED, $credential->refresh()->state());
        $this->assertNull($credential->checked_at);
    }

    public function test_people_on_site_without_a_checked_credential_are_listed(): void
    {
        $this->signIn('robin@relief.example', '0123456A');
        $register = app(CredentialRegister::class);

        $attention = $register->onSiteAttention($this->site);
        $this->assertCount(1, $attention);
        $this->assertSame('unchecked', $attention[0]['state']);

        $register->markChecked(VisitorCredential::query()->sole(), $this->receptionist, true, now()->addYear());
        $this->assertSame([], $register->onSiteAttention($this->site));

        // Expired cards come back.
        VisitorCredential::query()->update(['expires_on' => now()->subDay()->toDateString()]);
        $this->assertSame('expired', $register->onSiteAttention($this->site)[0]['state']);
    }

    public function test_an_on_site_credential_is_not_listed_twice_on_the_page(): void
    {
        $this->signIn('robin@relief.example', '0123456A');
        $credential = VisitorCredential::query()->sole();

        $page = $this->page()->set('siteId', $this->site->getKey());

        $this->assertCount(1, $page->viewData('onSite'));
        $this->assertFalse($page->viewData('attention')->contains(fn ($c) => $c->is($credential)));
    }

    public function test_an_hr_import_prefills_the_first_sign_in(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, "\xEF\xBB\xBFGiven Name,Surname,Email,Mobile,WWCC Number,WWCC Expiry\n"
            ."Sam,Supply,sam@relief.example,0400 111 222,7777777C,31/12/2028\n"
            ."Nina,,nina@relief.example,,1234,\n"
            ."Lee,Locum,lee@relief.example,,5555555D,not a date\n");

        $rows = CredentialImportFile::rows($path);
        $result = app(CredentialRegister::class)->import($this->wwcc, $rows, $this->receptionist);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['created_people']);
        $this->assertCount(2, $result['skipped']);
        $credential = VisitorCredential::query()->sole();
        $this->assertSame('2028-12-31', $credential->expires_on->toDateString());
        $this->assertSame(VisitorCredential::SOURCE_IMPORT, $credential->source);

        // First visit: found by mobile; the WWCC number is already on file (sensitive, so marked
        // "saved" rather than shown) and nothing has to be typed.
        $screen = Livewire::withCookie(KioskDevices::COOKIE, $this->secret)->test(KioskScreen::class)
            ->call('chooseType', 'relief')
            ->set('lookupIdentifier', '0400111222')->call('lookupReturning')
            ->call('confirmReturning')
            ->call('submitDetails')->assertHasNoErrors()->assertSet('step', 'questions')
            ->assertSet('savedAnswerKeys', ['wwcc']);
        $this->assertStringNotContainsString('7777777C', json_encode($screen->snapshot['data']));
        $screen->call('submitQuestions')->assertHasNoErrors()
            ->call('finish')->assertSet('step', 'done');

        $this->assertSame(1, Visitor::query()->count());
        $this->assertSame(1, VisitorCredential::query()->count());
        $this->assertSame('7777777C', VisitResponse::query()->where('field_key', 'wwcc')->sole()->plainValue());
        // The same number: still the imported record, expiry kept.
        $this->assertSame('2028-12-31', VisitorCredential::query()->sole()->expires_on->toDateString());
    }

    public function test_search_by_number_name_or_email_is_logged_without_the_query(): void
    {
        $this->signIn('robin@relief.example', '0123456A');

        $this->page()->set('query', '0123 456 a')->assertSee('Robin Relief')
            ->set('query', 'Robin')->assertSee('Robin Relief')
            ->set('query', 'robin@relief')->assertSee('Robin Relief')
            ->set('query', '9999999')->assertDontSee('Robin Relief');

        $searches = AuditEvent::query()->where('action', 'credential.searched')->get();
        $this->assertGreaterThanOrEqual(4, $searches->count());
        $this->assertStringNotContainsString('0123', $searches->pluck('context')->toJson());
        $this->assertStringNotContainsString('Robin', $searches->pluck('context')->toJson());
    }

    public function test_access_needs_the_credentials_permission_and_import_needs_admin(): void
    {
        $plain = User::factory()->create(['site_id' => $this->site->getKey()]);
        $plain->assignRole('user');
        Livewire::actingAs($plain)->test(CredentialsPage::class)->assertForbidden();

        $file = UploadedFile::fake()->createWithContent('wwcc.csv', "First name,Last name,Number\nA,B,123\n");
        $this->page()->assertDontSee(__('Nachweise importieren'))
            ->set('importFile', $file)->call('import')->assertForbidden();
    }

    public function test_states(): void
    {
        $this->wwcc->update(['recheck_days' => 90]);
        $credential = new VisitorCredential(['status' => VisitorCredential::VALID, 'checked_at' => now()->subDays(10), 'expires_on' => now()->addYear()]);
        $credential->setRelation('type', $this->wwcc->refresh());

        $this->assertSame(VisitorCredential::VALID, $credential->state());
        $credential->expires_on = now()->addDays(10);
        $this->assertSame(VisitorCredential::EXPIRING, $credential->state());
        $this->assertFalse($credential->needsAttention());
        $credential->checked_at = now()->subDays(100);
        $this->assertSame(VisitorCredential::RECHECK_DUE, $credential->state());
        $credential->status = VisitorCredential::INVALID;
        $this->assertSame(VisitorCredential::INVALID, $credential->state());
        $this->assertSame('2027-12-31', CredentialRegister::parseDate('31/12/2027')?->toDateString());
        $this->assertNull(CredentialRegister::parseDate('31/31/2027'));
    }
}
