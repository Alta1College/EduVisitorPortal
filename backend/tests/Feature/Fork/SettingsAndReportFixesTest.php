<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use App\Filament\Pages\ManageOrganisationSettings;
use App\Filament\Pages\ManageSiteSettings;
use App\Livewire\Portal\VisitShowPage;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Reports\Csv;
use App\Reports\ReportRecipients;
use App\Settings\SettingsRepository;
use App\Support\VisitorPortalPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\Support\PermissionHelper;
use Tests\TestCase;

/**
 * v1.0.1: issue #7 (settings, reports) and #3.4 (a site can switch a report off).
 */
class SettingsAndReportFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        VisitorPortalPermissions::sync();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_times_and_recipient_lists_are_validated_on_the_settings_pages(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ManageOrganisationSettings::class)
            ->fillForm([
                'organisation__visitor_experience__auto_sign_out_time' => '25:99',
                'organisation__reports__weekly_recipients' => 'Pat Smith',
            ])
            ->call('save')
            ->assertHasFormErrors([
                'organisation__visitor_experience__auto_sign_out_time',
                'organisation__reports__weekly_recipients',
            ]);

        $this->assertDatabaseCount('app_settings', 0);

        $site = Site::factory()->create();
        Livewire::actingAs($this->admin())
            ->withQueryParams(['site' => $site->getKey()])
            ->test(ManageSiteSettings::class)
            ->fillForm([
                'organisation__visitor_experience__auto_sign_out_time' => '7pm',
                'organisation__reports__weekly_recipients' => 'office@school.example; nobody',
            ])
            ->call('save')
            ->assertHasFormErrors([
                'organisation__visitor_experience__auto_sign_out_time',
                'organisation__reports__weekly_recipients',
            ]);
    }

    public function test_a_site_can_switch_a_report_off_with_none(): void
    {
        $north = Site::factory()->create(['name' => 'North', 'is_active' => true]);
        $south = Site::factory()->create(['name' => 'South', 'is_active' => true]);
        app(SettingsRepository::class)->saveOrganisation([ReportRecipients::ROLL_CALL => 'whs@school.example'], null);

        Livewire::actingAs($this->admin())
            ->withQueryParams(['site' => $south->getKey()])
            ->test(ManageSiteSettings::class)
            ->assertSee('none')
            ->fillForm(['organisation__reports__roll_call_recipients' => 'none'])
            ->call('save')
            ->assertHasNoFormErrors();

        $recipients = app(ReportRecipients::class);
        $this->assertSame(['whs@school.example'], $recipients->forSite($north, ReportRecipients::ROLL_CALL));
        $this->assertSame([], $recipients->forSite($south, ReportRecipients::ROLL_CALL));
        $grouped = collect($recipients->groups(ReportRecipients::ROLL_CALL))->flatMap(fn (array $group) => $group['sites']->pluck('name'));
        $this->assertContains('North', $grouped);
        $this->assertNotContains('South', $grouped);

        // Clearing the field again follows the organisation.
        app(SettingsRepository::class)->saveSite($south, [ReportRecipients::ROLL_CALL => ''], null);
        $this->assertSame(['whs@school.example'], $recipients->forSite($south->fresh(), ReportRecipients::ROLL_CALL));
    }

    public function test_csv_keeps_phone_numbers_and_still_guards_formulas(): void
    {
        $csv = Csv::build(['Value'], [['+61 412 345 678'], ['(08) 9123-4567'], ['+SUM(A1:A2)'], ['-cmd|calc'], ['=1+1']]);

        $this->assertStringContainsString('+61 412 345 678', $csv);
        $this->assertStringNotContainsString("'+61", $csv);
        $this->assertStringContainsString('(08) 9123-4567', $csv);
        $this->assertStringNotContainsString("'(08)", $csv);
        $this->assertStringContainsString("'+SUM(A1:A2)", $csv);
        $this->assertStringContainsString("'-cmd|calc", $csv);
        $this->assertStringContainsString("'=1+1", $csv);
    }

    public function test_the_visit_page_shows_the_time_range_without_german(): void
    {
        Carbon::setTestNow('2026-05-24 10:00:00');

        try {
            $user = (new PermissionHelper)->getUserUser();
            $visit = Visit::factory()->create([
                'host_user_id' => $user->id,
                'scheduled_from' => Carbon::parse('2026-05-25 09:00'),
                'scheduled_until' => Carbon::parse('2026-05-25 10:30'),
            ]);

            Livewire::actingAs($user)->test(VisitShowPage::class, ['visit' => $visit])
                ->assertSee('09:00–10:30')
                ->assertDontSee('09:00 bis 10:30');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_permissions_checked_by_policies_exist(): void
    {
        foreach (['ViewAny:Monitor', 'EditSite:Visit', 'DeleteSite:Visit'] as $name) {
            $this->assertTrue(Permission::query()->where('name', $name)->exists(), $name);
        }
    }
}
