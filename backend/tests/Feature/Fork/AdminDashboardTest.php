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
use App\Dashboard\DashboardScope;
use App\Dashboard\DashboardStats;
use App\Emergency\Broadcast\EmergencyNotification;
use App\Emergency\RollCall;
use App\Emergency\RollCallEntry;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\DashboardComplianceWidget;
use App\Filament\Widgets\DashboardNowWidget;
use App\Filament\Widgets\DashboardSafetyWidget;
use App\Filament\Widgets\DashboardTodayWidget;
use App\Filament\Widgets\DashboardWelcomeWidget;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\Settings\SettingsRepository;
use App\Support\VisitorPortalPermissions;
use App\VisitorWorkflow\Models\FormField;
use App\VisitorWorkflow\Models\FormFieldAssignment;
use App\VisitorWorkflow\Models\Policy;
use App\VisitorWorkflow\Models\PolicyVersion;
use App\VisitorWorkflow\Models\VisitorType;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Admin dashboard: getting-started panel, site filter and tiles (docs/fork/admin-dashboard.md).
 */
class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Site $north;

    private Site $south;

    private User $admin;

    private VisitorType $contractor;

    private VisitorType $parent;

    protected function setUp(): void
    {
        parent::setUp();

        VisitorPortalPermissions::sync();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        // A Wednesday morning.
        $this->travelTo(Carbon::parse('2026-11-18 10:00:00', (string) config('app.timezone')));

        $this->north = Site::factory()->create(['name' => 'North Campus', 'timezone' => config('app.timezone')]);
        $this->south = Site::factory()->create(['name' => 'South Campus', 'timezone' => config('app.timezone')]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->contractor = VisitorType::query()->create(['name' => 'Contractor', 'slug' => 'contractor', 'host_requirement' => VisitorType::HOST_NONE]);
        $this->parent = VisitorType::query()->create(['name' => 'Parent', 'slug' => 'parent', 'host_requirement' => VisitorType::HOST_NONE]);
    }

    /**
     * One visitor on one visit, with attendance written directly.
     */
    private function attend(Site $site, ?Carbon $checkedIn, ?Carbon $checkedOut = null, ?VisitorType $type = null, bool $walkIn = true, ?Carbon $scheduled = null, string $status = 'planned', ?Visit $visit = null, ?Visitor $visitor = null): Visitor
    {
        $scheduled ??= $checkedIn ?? now();
        $visit ??= Visit::factory()->create([
            'site_id' => $site->getKey(),
            'is_walk_in' => $walkIn,
            'scheduled_from' => $scheduled,
            'scheduled_until' => $scheduled->copy()->addHour(),
            'status' => $status,
        ]);
        $visitor ??= Visitor::factory()->create();

        DB::table('visit_visitor')->insert([
            'visit_id' => $visit->getKey(),
            'visitor_id' => $visitor->getKey(),
            'checked_in_at' => $checkedIn,
            'checked_out_at' => $checkedOut,
            'visitor_type_id' => $type?->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $visitor;
    }

    private function stats(): DashboardStats
    {
        return app(DashboardStats::class);
    }

    public function test_welcome_panel_shows_by_default_and_can_be_dismissed_and_shown_again(): void
    {
        $this->actingAs($this->admin);
        $this->assertTrue(DashboardWelcomeWidget::canView());

        Livewire::test(Dashboard::class)->assertSuccessful()->assertActionHidden('showWelcome');

        Livewire::test(DashboardWelcomeWidget::class)
            ->assertSee(__('Willkommen'))
            ->assertSee(__('Standorte und Erscheinungsbild'))
            ->assertSee(__('Benachrichtigungen, Teams und Notfälle'))
            ->assertSee(__('Nicht mehr anzeigen'))
            ->call('dismiss')
            ->assertRedirect(Dashboard::getUrl());

        $this->assertNotNull($this->admin->fresh()->dashboard_welcome_dismissed_at);
        $this->actingAs($this->admin->fresh());
        $this->assertFalse(DashboardWelcomeWidget::canView());

        Livewire::test(Dashboard::class)
            ->assertActionVisible('showWelcome')
            ->callAction('showWelcome')
            ->assertRedirect(Dashboard::getUrl());

        $this->assertNull($this->admin->fresh()->dashboard_welcome_dismissed_at);
    }

    public function test_dashboard_page_and_tiles_render_for_an_admin(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(Dashboard::class)->assertSuccessful()->assertSee(__('Standort'));

        Livewire::test(DashboardNowWidget::class)->assertSuccessful()
            ->assertSee(__('Jetzt vor Ort'))->assertSee(__('Seit früheren Tagen angemeldet'));
        Livewire::test(DashboardTodayWidget::class)->assertSuccessful()
            ->assertSee(__('Ankünfte heute'))->assertSee(__('Nächste 7 Tage'));
        Livewire::test(DashboardComplianceWidget::class)->assertSuccessful()
            ->assertSee(__('Nachweise ungeprüft'))->assertSee(__('Richtlinien kürzlich geändert'));
        Livewire::test(DashboardSafetyWidget::class)->assertSuccessful()
            ->assertSee('North Campus')->assertSee('South Campus');
    }

    public function test_site_filter_scopes_the_counts(): void
    {
        $this->attend($this->north, now()->subHour(), type: $this->contractor);
        $this->attend($this->north, now()->subMinutes(30), type: $this->contractor);
        $this->attend($this->south, now()->subMinutes(10), type: $this->parent);

        $this->assertSame(3, $this->stats()->now(DashboardScope::for($this->admin))['total']);
        $this->assertSame(2, $this->stats()->now(DashboardScope::for($this->admin, $this->north->getKey()))['total']);
        $this->assertSame(1, $this->stats()->now(DashboardScope::for($this->admin, (string) $this->south->getKey()))['total']);
        $options = DashboardScope::siteOptions($this->admin);
        $this->assertArrayHasKey($this->north->getKey(), $options);
        $this->assertArrayHasKey($this->south->getKey(), $options);

        $this->actingAs($this->admin);
        Livewire::test(DashboardNowWidget::class, ['pageFilters' => ['site_id' => $this->south->getKey()]])
            ->assertSee('Parent: 1')->assertDontSee('Contractor: 2');
        Livewire::test(DashboardNowWidget::class, ['pageFilters' => ['site_id' => null]])
            ->assertSee('Contractor: 2')->assertSee('Parent: 1');
    }

    public function test_users_without_access_to_every_site_only_see_their_assigned_sites(): void
    {
        $this->attend($this->north, now()->subHour());
        $this->attend($this->north, now()->subHour());
        $this->attend($this->south, now()->subHour());

        $receptionist = User::factory()->create(['site_id' => $this->north->getKey()]);
        $receptionist->assignRole('receptionist');

        $this->assertSame([$this->north->getKey()], DashboardScope::for($receptionist)->siteIds);
        // Asking for another site falls back to the assigned ones.
        $this->assertSame([$this->north->getKey()], DashboardScope::for($receptionist, $this->south->getKey())->siteIds);
        $this->assertSame([$this->north->getKey()], array_keys(DashboardScope::siteOptions($receptionist)));
        $this->assertSame(2, $this->stats()->now(DashboardScope::for($receptionist, $this->south->getKey()))['total']);

        // Receptionists cannot open the admin panel itself.
        $this->assertFalse($receptionist->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_now_and_today_counts(): void
    {
        // On site since this morning; left this morning; still signed in from yesterday.
        $this->attend($this->north, now()->setTime(9, 0), type: $this->contractor);
        $this->attend($this->north, now()->setTime(8, 0), now()->setTime(9, 30));
        $this->attend($this->north, now()->subDay()->setTime(15, 0), type: $this->parent);
        // Same weekday last week: before and after this time of day.
        $this->attend($this->north, now()->subWeek()->setTime(8, 30), now()->subWeek()->setTime(9, 0));
        $this->attend($this->north, now()->subWeek()->setTime(11, 0), now()->subWeek()->setTime(12, 0));

        // Pre-booked today with two visitors, one arrived; a cancelled booking does not count.
        $booked = Visit::factory()->create(['site_id' => $this->north->getKey(), 'is_walk_in' => false,
            'scheduled_from' => now()->setTime(9, 30), 'scheduled_until' => now()->setTime(11, 0)]);
        $this->attend($this->north, now()->setTime(9, 45), visit: $booked);
        $this->attend($this->north, null, visit: $booked);
        $this->attend($this->north, null, walkIn: false, scheduled: now()->setTime(13, 0), status: 'canceled');

        // Next 7 days: one booking in three days; not one in nine days, not a cancelled one.
        $this->attend($this->north, null, walkIn: false, scheduled: now()->addDays(3)->setTime(9, 0));
        $this->attend($this->north, null, walkIn: false, scheduled: now()->addDays(9)->setTime(9, 0));
        $this->attend($this->north, null, walkIn: false, scheduled: now()->addDays(2)->setTime(9, 0), status: 'canceled');
        // Another site is not counted when the filter picks North.
        $this->attend($this->south, now()->setTime(9, 0));

        $scope = DashboardScope::for($this->admin, $this->north->getKey());
        $now = $this->stats()->now($scope);
        $today = $this->stats()->today($scope);

        $this->assertSame(3, $now['total']);
        $byType = $now['by_type'];
        ksort($byType);
        $this->assertSame(['' => 1, 'Contractor' => 1, 'Parent' => 1], $byType);
        $this->assertSame(1, $now['earlier_days']);

        $this->assertSame(3, $today['arrivals']);
        $this->assertSame(1, $today['arrivals_last_week']);
        $this->assertSame(2, $today['expected']);
        $this->assertSame(1, $today['arrived']);
        $this->assertSame(1, $today['upcoming_visits']);
        $this->assertSame(1, $today['upcoming_visitors']);

        $this->assertSame(4, $this->stats()->today(DashboardScope::for($this->admin))['arrivals']);

        $this->actingAs($this->admin);
        Livewire::test(DashboardTodayWidget::class, ['pageFilters' => ['site_id' => $this->north->getKey()]])
            ->assertSee('1 / 2')
            ->assertSee(__('Letzte Woche bis zu dieser Uhrzeit: :count', ['count' => 1]));
    }

    public function test_credential_tiles_count_on_site_people_and_records(): void
    {
        $wwcc = CredentialType::query()->create(['name' => 'Screening check', 'expiry_warning_days' => 30]);
        $field = FormField::query()->create(['key' => 'check', 'label' => 'Check number', 'type' => FormField::TYPE_SHORT_TEXT, 'credential_type_id' => $wwcc->getKey()]);
        FormFieldAssignment::query()->create(['form_field_id' => $field->getKey(), 'visitor_type_id' => $this->contractor->getKey(), 'is_required' => true, 'sort_order' => 1]);

        $credential = function (Visitor $visitor, string $status, ?Carbon $expires = null, bool $checked = false) use ($wwcc): void {
            VisitorCredential::query()->create([
                'visitor_id' => $visitor->getKey(), 'credential_type_id' => $wwcc->getKey(),
                'number' => 'N'.$visitor->getKey(), 'number_hash' => CredentialRegister::hash('N'.$visitor->getKey()),
                'status' => $status, 'checked_at' => $checked ? now()->subDay() : null,
                'expires_on' => $expires, 'source' => VisitorCredential::SOURCE_STAFF,
            ]);
        };

        // On site: missing, unchecked, valid, expired.
        $this->attend($this->north, now()->subHour(), type: $this->contractor);
        $credential($this->attend($this->north, now()->subHour(), type: $this->contractor), VisitorCredential::UNCHECKED);
        $credential($this->attend($this->north, now()->subHour(), type: $this->contractor), VisitorCredential::VALID, now()->addYear(), true);
        $credential($this->attend($this->north, now()->subHour(), type: $this->contractor), VisitorCredential::VALID, now()->subDay(), true);
        // Signed out: not on site.
        $this->attend($this->north, now()->subHours(3), now()->subHours(2), type: $this->contractor);
        // Never visited: counted only without a site filter.
        $credential(Visitor::factory()->create(), VisitorCredential::VALID, now()->addDays(10), true);
        $credential(Visitor::factory()->create(), VisitorCredential::INVALID, null, true);

        $north = DashboardScope::for($this->admin, $this->north->getKey());
        $this->assertSame(3, $this->stats()->credentialsOnSite($north));
        $this->assertSame(0, $this->stats()->credentialsOnSite(DashboardScope::for($this->admin, $this->south->getKey())));

        $this->assertSame(['unchecked' => 1, 'expiring' => 0, 'not_valid' => 1, 'policies_updated' => 0], $this->stats()->compliance($north));
        $this->assertSame(['unchecked' => 1, 'expiring' => 1, 'not_valid' => 2, 'policies_updated' => 0], $this->stats()->compliance(DashboardScope::for($this->admin)));

        $this->actingAs($this->admin);
        Livewire::test(DashboardNowWidget::class, ['pageFilters' => ['site_id' => $this->north->getKey()]])
            ->assertSee(__('Nachweise vor Ort nicht geklärt'))
            ->assertSee(__('Nachweis fehlt, ist ungeprüft, abgelaufen oder ungültig'))
            ->assertSee(route('reception.credentials'));
    }

    public function test_policy_tile_counts_policies_with_a_recent_version(): void
    {
        $publish = function (string $name, Carbon $at): void {
            $policy = Policy::query()->create(['name' => $name]);
            PolicyVersion::query()->create(['policy_id' => $policy->getKey(), 'version' => 1, 'title' => $name, 'body' => 'Text',
                'acceptance_label' => 'I agree', 'content_hash' => PolicyVersion::hashContent($name, 'Text', 'I agree'), 'published_at' => $at]);
        };

        $publish('Site safety', now()->subDays(5));
        $publish('Privacy', now()->subDays(60));

        $this->assertSame(1, $this->stats()->compliance(DashboardScope::for($this->admin))['policies_updated']);
    }

    public function test_safety_tile_shows_the_last_drill_and_warns_after_the_threshold(): void
    {
        $drill = RollCall::query()->create(['site_id' => $this->north->getKey(), 'kind' => RollCall::KIND_DRILL, 'started_at' => now()->subDays(30)]);
        $drill->forceFill(['ended_at' => now()->subDays(30)->addMinutes(20)])->save();
        RollCallEntry::query()->create(['roll_call_id' => $drill->getKey(), 'source' => RollCallEntry::SOURCE_SNAPSHOT, 'first_name' => 'Ada', 'status' => RollCallEntry::ACCOUNTED]);
        RollCallEntry::query()->create(['roll_call_id' => $drill->getKey(), 'source' => RollCallEntry::SOURCE_SNAPSHOT, 'first_name' => 'Ben', 'status' => RollCallEntry::LEFT_SITE]);
        foreach ([EmergencyNotification::DELIVERED, EmergencyNotification::DELIVERED, EmergencyNotification::FAILED] as $status) {
            EmergencyNotification::query()->create(['roll_call_id' => $drill->getKey(), 'channel' => EmergencyNotification::CHANNEL_SMS, 'status' => $status]);
        }

        $safety = collect($this->stats()->safety(DashboardScope::for($this->admin)))->keyBy('site_name');
        $this->assertSame(90, $safety['North Campus']['warning_days']);
        $this->assertSame(['kind' => 'drill', 'started_at' => $drill->started_at->toIso8601String(), 'open' => false, 'unaccounted' => 0, 'sms_delivered' => 2, 'sms_failed' => 1], $safety['North Campus']['last']);
        $this->assertNull($safety['South Campus']['last']);

        $this->actingAs($this->admin);
        Livewire::test(DashboardSafetyWidget::class)
            ->assertSee(__('Alle erfasst'))
            ->assertSee(__('SMS: :delivered zugestellt, :failed fehlgeschlagen', ['delivered' => 2, 'failed' => 1]))
            ->assertSee(__('Keine Übung erfasst'))
            ->assertDontSee(__('Letzte Übung vor mehr als :days Tagen', ['days' => 90]));

        // North overrides the threshold to 20 days: its 30-day-old drill is now overdue.
        app(SettingsRepository::class)->saveSite($this->north, ['organisation.emergency.drill_warning_days' => 20], null);
        Cache::flush();

        $this->assertSame(20, collect($this->stats()->safety(DashboardScope::for($this->admin)))->keyBy('site_name')['North Campus']['warning_days']);

        Livewire::test(DashboardSafetyWidget::class, ['pageFilters' => ['site_id' => $this->north->getKey()]])
            ->assertSee(__('Letzte Übung vor mehr als :days Tagen', ['days' => 20]))
            ->assertDontSee('South Campus');
    }

    public function test_tiles_are_cached_briefly(): void
    {
        $scope = DashboardScope::for($this->admin, $this->north->getKey());
        $this->assertSame(0, $this->stats()->now($scope)['total']);

        $this->attend($this->north, now()->subMinutes(5));
        $this->assertSame(0, $this->stats()->now($scope)['total']);

        $this->travel(DashboardStats::TTL_NOW + 1)->seconds();
        $this->assertSame(1, $this->stats()->now(DashboardScope::for($this->admin, $this->north->getKey()))['total']);
    }
}
