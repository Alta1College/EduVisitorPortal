<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use App\Credentials\CredentialRegister;
use App\Credentials\CredentialType;
use App\Credentials\VisitorCredential;
use App\Emergency\AutoSignOut;
use App\Emergency\RollCall;
use App\Emergency\RollCallEntry;
use App\Emergency\RollCalls;
use App\Filament\Resources\Visits\Pages\ListVisits;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\Reports\Csv;
use App\Reports\Exports;
use App\Reports\ReportData;
use App\Reports\ReportMail;
use App\Reports\ReportPdf;
use App\Reports\ReportRecipients;
use App\Reports\Reports;
use App\Reports\SendRollCallReport;
use App\Settings\SettingsRepository;
use App\Support\VisitorPortalPermissions;
use App\VisitorWorkflow\Models\VisitorType;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Emailed reports (weekly, monthly, roll-call record) and the CSV exports.
 */
class ScheduledReportsTest extends TestCase
{
    use RefreshDatabase;

    private Site $north;

    private Site $south;

    private User $admin;

    private VisitorType $contractor;

    protected function setUp(): void
    {
        parent::setUp();

        VisitorPortalPermissions::sync();
        app()->setLocale('en');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        // Wednesday 18 November 2026: last week is Mon 9 – Sun 15 November.
        $this->travelTo(Carbon::parse('2026-11-18 10:00:00', (string) config('app.timezone')));

        $this->north = Site::factory()->create(['name' => 'North Campus', 'timezone' => config('app.timezone')]);
        $this->south = Site::factory()->create(['name' => 'South Campus', 'timezone' => config('app.timezone')]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->contractor = VisitorType::query()->create(['name' => 'Contractor', 'slug' => 'contractor', 'host_requirement' => VisitorType::HOST_NONE]);

        $this->app->instance(ReportPdf::class, new class extends ReportPdf
        {
            public ?string $result = '%PDF-fake';

            public function render(string $view, array $data): ?string
            {
                view($view, $data)->render();

                return $this->result;
            }
        });
    }

    private function attend(Site $site, Carbon $checkedIn, ?Carbon $checkedOut = null, ?Visitor $visitor = null, ?string $outVia = null): Visitor
    {
        $visit = Visit::factory()->create([
            'site_id' => $site->getKey(),
            'is_walk_in' => true,
            'scheduled_from' => $checkedIn,
            'scheduled_until' => $checkedIn->copy()->addHour(),
        ]);
        $visitor ??= Visitor::factory()->create();

        DB::table('visit_visitor')->insert([
            'visit_id' => $visit->getKey(),
            'visitor_id' => $visitor->getKey(),
            'checked_in_at' => $checkedIn,
            'checked_out_at' => $checkedOut,
            'checked_out_via' => $outVia,
            'visitor_type_id' => $this->contractor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $visitor;
    }

    private function recipients(string $key, string $value, ?Site $site = null): void
    {
        $repository = app(SettingsRepository::class);
        $site === null ? $repository->saveOrganisation([$key => $value], null) : $repository->saveSite($site, [$key => $value], null);
    }

    public function test_nothing_is_sent_without_recipients(): void
    {
        Mail::fake();
        $this->attend($this->north, Carbon::parse('2026-11-10 09:00'));

        $this->artisan('visitorportal:reports:send weekly')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_the_weekly_summary_counts_last_week_and_goes_once_to_each_recipient_list(): void
    {
        Mail::fake();
        $this->recipients(ReportRecipients::WEEKLY, 'principal@school.example, Business@School.example');
        $this->recipients(ReportRecipients::WEEKLY, 'south-office@school.example', $this->south);

        $this->attend($this->north, Carbon::parse('2026-11-09 08:15'), Carbon::parse('2026-11-09 09:15'));
        $this->attend($this->north, Carbon::parse('2026-11-10 08:30'), Carbon::parse('2026-11-10 18:00'), outVia: AutoSignOut::CHANNEL);
        $this->attend($this->south, Carbon::parse('2026-11-15 13:00'));
        // Outside the week: Sunday 8th and Monday 16th.
        $this->attend($this->north, Carbon::parse('2026-11-08 23:00'));
        $this->attend($this->north, Carbon::parse('2026-11-16 08:00'));

        $this->artisan('visitorportal:reports:send weekly')->assertSuccessful();

        Mail::assertSent(ReportMail::class, 2);
        Mail::assertSent(ReportMail::class, function (ReportMail $mail): bool {
            if (! $mail->hasTo('principal@school.example') || ! $mail->hasTo('business@school.example')) {
                return false;
            }

            $csv = $mail->files['weekly-2026-11-09.csv'][0] ?? '';

            return str_contains($mail->period, '9 Nov 2026')
                && str_contains($mail->period, '15 Nov 2026')
                && isset($mail->files['weekly-2026-11-09.pdf'])
                && str_contains($csv, '2026-11-09,"North Campus",Contractor,1')
                && ! str_contains($csv, '2026-11-16')
                && ! str_contains($csv, 'South Campus');
        });
        Mail::assertSent(ReportMail::class, fn (ReportMail $mail): bool => $mail->hasTo('south-office@school.example')
            && str_contains($mail->files['weekly-2026-11-09.csv'][0], 'South Campus'));

        $this->assertSame(2, DB::table('audit_events')->where('action', 'report.sent')->count());
    }

    public function test_weekly_figures(): void
    {
        $this->attend($this->north, Carbon::parse('2026-11-09 08:15'), Carbon::parse('2026-11-09 09:15'));
        $visitor = $this->attend($this->north, Carbon::parse('2026-11-10 08:30'), Carbon::parse('2026-11-10 18:00'), outVia: AutoSignOut::CHANNEL);
        $this->attend($this->north, Carbon::parse('2026-11-11 08:45'), Carbon::parse('2026-11-11 09:15'), visitor: $visitor);
        $this->attend($this->north, Carbon::parse('2026-11-12 08:10'));

        [$from, $until] = ReportData::lastWeek((string) config('app.timezone'));
        $data = app(ReportData::class)->weekly(collect([$this->north]), $from, $until, (string) config('app.timezone'));

        $this->assertSame(4, $data['arrivals']);
        $this->assertSame(3, $data['visitors']);
        $this->assertSame(1, $data['auto_sign_outs']);
        $this->assertSame(1, $data['still_signed_in']);
        // One hour and 30 minutes; the automatic sign-out is not a visit length.
        $this->assertSame(2700, $data['avg_visit_seconds']);
        $this->assertCount(3, $data['busiest']);
        $this->assertSame([8, 8, 8], array_column($data['busiest'], 'hour'));
        $this->assertSame(7, count($data['by_day']));
    }

    public function test_the_monthly_report_lists_people_whose_credential_needs_action(): void
    {
        Mail::fake();
        $this->recipients(ReportRecipients::MONTHLY, 'whs@school.example');
        $type = CredentialType::query()->create(['name' => 'Screening check', 'expiry_warning_days' => 30]);
        $credential = function (Visitor $visitor, string $status, ?Carbon $expires = null) use ($type): void {
            VisitorCredential::query()->create([
                'visitor_id' => $visitor->getKey(), 'credential_type_id' => $type->getKey(),
                'number' => 'N'.$visitor->getKey(), 'number_hash' => CredentialRegister::hash('N'.$visitor->getKey()),
                'status' => $status, 'checked_at' => $status === VisitorCredential::VALID ? now()->subDays(3) : null,
                'expires_on' => $expires, 'source' => VisitorCredential::SOURCE_STAFF,
            ]);
        };

        $credential($this->attend($this->north, Carbon::parse('2026-10-05 09:00'), visitor: Visitor::factory()->create(['first_name' => 'Una', 'name' => 'Checked'])), VisitorCredential::UNCHECKED);
        $credential($this->attend($this->north, Carbon::parse('2026-10-06 09:00'), visitor: Visitor::factory()->create(['first_name' => 'Vera', 'name' => 'Valid'])), VisitorCredential::VALID, now()->addYear());
        $credential($this->attend($this->north, Carbon::parse('2026-10-07 09:00'), visitor: Visitor::factory()->create(['first_name' => 'Ed', 'name' => 'Expired'])), VisitorCredential::VALID, now()->subDay());

        RollCall::query()->create(['site_id' => $this->north->getKey(), 'kind' => RollCall::KIND_DRILL, 'started_at' => Carbon::parse('2026-10-20 10:00')]);

        $this->artisan('visitorportal:reports:send monthly')->assertSuccessful();

        Mail::assertSent(ReportMail::class, function (ReportMail $mail): bool {
            $csv = $mail->files['monthly-2026-10-01.csv'][0] ?? '';

            return $mail->hasTo('whs@school.example')
                && str_contains($mail->period, '1 Oct 2026')
                && str_contains($mail->period, '31 Oct 2026')
                && str_contains($csv, 'Una')
                && str_contains($csv, 'Ed')
                && ! str_contains($csv, 'Vera')
                // Most urgent first: expired before unchecked.
                && strpos($csv, 'Ed') < strpos($csv, 'Una');
        });
    }

    public function test_a_report_still_goes_out_with_the_csv_when_the_pdf_fails(): void
    {
        Mail::fake();
        app(ReportPdf::class)->result = null;
        $this->recipients(ReportRecipients::WEEKLY, 'principal@school.example');

        $this->artisan('visitorportal:reports:send weekly')->assertSuccessful();

        Mail::assertSent(ReportMail::class, fn (ReportMail $mail): bool => $mail->pdfMissing
            && array_keys($mail->files) === ['weekly-2026-11-09.csv']);
    }

    public function test_ending_a_roll_call_emails_its_record_to_the_site_recipients(): void
    {
        Mail::fake();
        $this->recipients(ReportRecipients::ROLL_CALL, 'whs@school.example', $this->north);
        $warden = User::factory()->create(['site_id' => $this->north->getKey()]);
        $warden->assignRole('receptionist');

        $rollCall = app(RollCalls::class)->start($this->north, $warden, RollCall::KIND_DRILL);
        $rollCall->entries()->create(['source' => RollCallEntry::SOURCE_MANUAL, 'first_name' => 'Ada', 'last_name' => 'Avery', 'safety_notes' => ['Medical' => 'Asthma']]);
        Mail::assertNothingSent();

        app(RollCalls::class)->end($rollCall, $warden);

        Mail::assertSent(ReportMail::class, function (ReportMail $mail): bool {
            $files = array_keys($mail->files);
            $csv = $mail->files[$files[1]][0];

            return $mail->hasTo('whs@school.example')
                && str_ends_with($files[0], '.pdf')
                && str_contains($csv, 'Ada Avery')
                && ! str_contains($csv, 'Asthma');
        });
    }

    public function test_the_roll_call_record_is_queued_after_the_roll_call_ends(): void
    {
        Queue::fake();
        $warden = User::factory()->create(['site_id' => $this->north->getKey()]);
        $warden->assignRole('receptionist');
        $rollCall = app(RollCalls::class)->start($this->north, $warden, RollCall::KIND_EMERGENCY);

        app(RollCalls::class)->end($rollCall, $warden);

        Queue::assertPushed(SendRollCallReport::class, fn (SendRollCallReport $job): bool => $job->rollCallId === $rollCall->getKey());
    }

    public function test_durations_use_the_two_largest_units(): void
    {
        $start = Carbon::parse('2026-11-20 20:15');

        $this->assertSame('< 1m', Reports::duration($start, $start->copy()->addSeconds(30)));
        $this->assertSame('45m', Reports::duration($start, $start->copy()->addMinutes(45)));
        $this->assertSame('3h 5m', Reports::duration($start, $start->copy()->addMinutes(185)));
        $this->assertSame('2d 13h', Reports::duration($start, $start->copy()->addMinutes(3716)));
    }

    public function test_recipient_lists_are_validated_and_parsed(): void
    {
        $this->assertSame(['a@school.example', 'b@school.example'], ReportRecipients::parse(' B@school.example; a@school.example,,a@school.example '));
        $this->assertSame(1, preg_match(ReportRecipients::LIST_PATTERN, 'a@school.example, b@school.example'));
        $this->assertSame(0, preg_match(ReportRecipients::LIST_PATTERN, 'Pat Smith'));
    }

    public function test_csv_cells_cannot_become_formulas(): void
    {
        $csv = Csv::build(['Name'], [['=HYPERLINK("x")'], ['-1+2'], ['Normal'], [42]]);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("\"'=HYPERLINK(\"\"x\"\")\"", $csv);
        $this->assertStringContainsString("'-1+2", $csv);
        $this->assertStringContainsString("\n42\n", $csv);
    }

    public function test_the_visits_export_downloads_sign_ins_for_the_range_and_is_audited(): void
    {
        $visitor = Visitor::factory()->create(['first_name' => 'Ada', 'name' => 'Avery', 'company' => 'Acme']);
        $this->attend($this->north, Carbon::parse('2026-11-17 09:00'), Carbon::parse('2026-11-17 10:00'), visitor: $visitor);
        $this->attend($this->south, Carbon::parse('2026-11-17 09:30'), visitor: Visitor::factory()->create(['first_name' => 'Zarnow', 'name' => 'South']));
        $this->attend($this->north, Carbon::parse('2026-10-01 09:00'), visitor: Visitor::factory()->create(['first_name' => 'Vexley', 'name' => 'Visit']));

        $csv = app(Exports::class)->visits(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-19'), [$this->north->getKey()]);
        $this->assertStringContainsString('Ada', $csv);
        $this->assertStringNotContainsString('Zarnow', $csv);
        $this->assertStringNotContainsString('Vexley', $csv);

        $visitors = app(Exports::class)->visitors(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-19'), null);
        $this->assertStringContainsString('Ada', $visitors);
        $this->assertStringContainsString('Zarnow', $visitors);

        Livewire::actingAs($this->admin)->test(ListVisits::class)
            ->assertActionVisible('exportVisits')
            ->callAction('exportVisits', ['from' => '2026-11-01', 'until' => '2026-11-18', 'site_id' => $this->north->getKey()])
            ->assertFileDownloaded('sign-ins-2026-11-01-2026-11-18.csv');

        $this->assertDatabaseHas('audit_events', ['action' => 'report.exported', 'site_id' => $this->north->getKey()]);
    }

    public function test_only_users_who_see_every_visit_may_export(): void
    {
        $receptionist = User::factory()->create(['site_id' => $this->north->getKey()]);
        $receptionist->assignRole('receptionist');

        $this->assertTrue(Exports::allowed($this->admin));
        $this->assertFalse(Exports::allowed($receptionist));
        $this->assertFalse(Exports::allowed(null));
    }
}
