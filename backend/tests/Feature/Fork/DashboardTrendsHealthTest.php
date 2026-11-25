<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use App\Audit\AuditLogger;
use App\Dashboard\DashboardScope;
use App\Dashboard\DashboardTrends;
use App\Dashboard\SystemHealth;
use App\Emergency\AutoSignOut;
use App\Emergency\Broadcast\EmergencyNotification;
use App\Emergency\RollCall;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\DashboardArrivalsChartWidget;
use App\Filament\Widgets\DashboardBusiestHoursWidget;
use App\Filament\Widgets\DashboardHealthWidget;
use App\Filament\Widgets\DashboardTopCompaniesWidget;
use App\Filament\Widgets\DashboardVisitorTrendsWidget;
use App\Kiosk\Kiosk;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\Settings\SettingsRepository;
use App\Support\OperationalHeartbeat;
use App\Support\VisitorPortalPermissions;
use App\VisitorWorkflow\Models\VisitorType;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Admin dashboard trends and system health (docs/fork/admin-dashboard.md).
 */
class DashboardTrendsHealthTest extends TestCase
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
    private function attend(Site $site, Carbon $checkedIn, ?Carbon $checkedOut = null, ?VisitorType $type = null, ?Visitor $visitor = null, ?Visit $visit = null, ?string $outVia = null): Visitor
    {
        $visit ??= Visit::factory()->create([
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
            'visitor_type_id' => $type?->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $visitor;
    }

    private function trends(): DashboardTrends
    {
        return app(DashboardTrends::class);
    }

    private function health(): SystemHealth
    {
        return app(SystemHealth::class);
    }

    public function test_arrivals_per_day_are_bucketed_by_day_and_stacked_by_type(): void
    {
        $this->attend($this->north, now()->setTime(8, 30), type: $this->contractor);
        $this->attend($this->north, now()->setTime(9, 15), type: $this->contractor);
        $this->attend($this->north, now()->setTime(9, 45), type: $this->parent);
        $this->attend($this->north, now()->subDay()->setTime(14, 0), type: $this->contractor);
        $this->attend($this->north, now()->subDay()->setTime(15, 0));
        // First day of the 30-day window; the day before it only counts in 90 days.
        $this->attend($this->north, now()->subDays(29)->setTime(0, 5), type: $this->parent);
        $this->attend($this->north, now()->subDays(30)->setTime(23, 55), type: $this->parent);
        // Another site.
        $this->attend($this->south, now()->setTime(9, 0), type: $this->parent);

        $north = DashboardScope::for($this->admin, $this->north->getKey());
        $data = $this->trends()->arrivalsPerDay($north, 30);

        $this->assertCount(30, $data['days']);
        $this->assertSame(now()->subDays(29)->toDateString(), $data['days'][0]);
        $this->assertSame(now()->toDateString(), $data['days'][29]);

        $series = collect($data['series'])->keyBy(fn (array $s): string => $s['type'] ?? '');
        $this->assertSame(['Contractor', 'Parent', ''], $series->keys()->all());
        $this->assertSame(2, $series['Contractor']['counts'][29]);
        $this->assertSame(1, $series['Contractor']['counts'][28]);
        $this->assertSame(1, $series['Parent']['counts'][29]);
        $this->assertSame(1, $series['Parent']['counts'][0]);
        $this->assertSame(1, $series['']['counts'][28]);
        $this->assertSame(6, collect($data['series'])->sum(fn (array $s): int => array_sum($s['counts'])));

        $ninety = $this->trends()->arrivalsPerDay($north, 90);
        $this->assertCount(90, $ninety['days']);
        $this->assertSame(7, collect($ninety['series'])->sum(fn (array $s): int => array_sum($s['counts'])));

        // All sites: South's parent too. Unknown periods fall back to 30 days.
        $all = $this->trends()->arrivalsPerDay(DashboardScope::for($this->admin), 45);
        $this->assertCount(30, $all['days']);
        $this->assertSame(2, collect($all['series'])->firstWhere('type', 'Parent')['counts'][29]);

        $this->actingAs($this->admin);
        Livewire::test(DashboardArrivalsChartWidget::class, ['pageFilters' => ['site_id' => $this->north->getKey()]])
            ->assertSuccessful()
            ->assertSee(__('Ankünfte pro Tag'))
            ->assertSee('Contractor')
            ->assertSee(__('Ohne Besuchertyp'))
            ->assertSee(__('Letzte :days Tage', ['days' => 90]))
            ->set('filter', '90')
            ->assertSuccessful();
    }

    public function test_days_and_hours_follow_the_site_timezone(): void
    {
        // A site far from the installation's timezone: local day and hour differ from storage.
        $east = Site::factory()->create(['name' => 'East', 'timezone' => 'Pacific/Kiritimati']);
        $checkIn = now()->subHours(3);
        $this->attend($east, $checkIn, type: $this->contractor);

        $local = $checkIn->copy()->setTimezone('Pacific/Kiritimati');
        $scope = DashboardScope::for($this->admin, $east->getKey());
        $data = $this->trends()->arrivalsPerDay($scope, 30);

        $index = array_search($local->toDateString(), $data['days'], true);
        $this->assertNotFalse($index);
        $this->assertSame(1, $data['series'][0]['counts'][$index]);

        $this->assertSame([$local->dayOfWeekIso => [$local->hour => 1]], $this->trends()->busiestHours($scope, 30));
    }

    public function test_busiest_hours_count_arrivals_by_weekday_and_hour(): void
    {
        // Today is Wednesday.
        $this->attend($this->north, now()->setTime(9, 15));
        $this->attend($this->north, now()->setTime(9, 45));
        $this->attend($this->north, now()->subWeek()->setTime(9, 5));
        $this->attend($this->north, now()->subDays(2)->setTime(14, 0)); // Monday
        $this->attend($this->north, now()->subDays(4)->setTime(10, 30)); // Saturday
        $this->attend($this->south, now()->setTime(9, 30));

        $grid = $this->trends()->busiestHours(DashboardScope::for($this->admin, $this->north->getKey()), 30);

        $this->assertSame([1 => [14 => 1], 3 => [9 => 3], 6 => [10 => 1]], $grid);
        $this->assertSame(4, $this->trends()->busiestHours(DashboardScope::for($this->admin), 30)[3][9]);

        $this->actingAs($this->admin);
        Livewire::test(DashboardBusiestHoursWidget::class, ['pageFilters' => ['site_id' => $this->north->getKey()]])
            ->assertSuccessful()
            ->assertSee(__('Stoßzeiten'))
            ->assertSeeHtml('data-count="3"')
            ->assertSeeHtml('busiest-hours-step-5')
            ->assertSee(now()->startOfWeek()->addDays(5)->locale(app()->getLocale())->isoFormat('dd'))
            ->set('filter', '90')
            ->assertSuccessful();

        Livewire::test(DashboardBusiestHoursWidget::class, ['pageFilters' => ['site_id' => $this->south->getKey()]])
            ->assertDontSeeHtml('data-count="3"');
    }

    public function test_returning_visitors_and_average_visit_length(): void
    {
        $returning = Visitor::factory()->create();
        $newcomer = Visitor::factory()->create();
        $recent = Visitor::factory()->create();

        // First-ever visit 45 days ago (previous period), back today for an hour.
        $this->attend($this->north, now()->subDays(45)->setTime(9, 0), now()->subDays(45)->setTime(9, 20), visitor: $returning);
        $this->attend($this->north, now()->setTime(8, 0), now()->setTime(9, 0), visitor: $returning);
        // New today, 30 minutes.
        $this->attend($this->north, now()->setTime(8, 30), now()->setTime(9, 0), visitor: $newcomer);
        // First visit ten days ago (inside the period), back today: not returning.
        $this->attend($this->north, now()->subDays(10)->setTime(9, 0), visitor: $recent);
        // Automatically signed out after nine hours: not visit length.
        $this->attend($this->north, now()->subDays(2)->setTime(9, 0), now()->subDays(2)->setTime(18, 0), visitor: $recent, outVia: AutoSignOut::CHANNEL);
        // Forgot to sign out: 30 hours is an outlier.
        $this->attend($this->north, now()->subDays(3)->setTime(9, 0), now()->subDays(2)->setTime(15, 0));
        // Another site: not in North's figures.
        $this->attend($this->south, now()->setTime(8, 0), now()->setTime(12, 0), visitor: $returning);

        $figures = $this->trends()->visitors(DashboardScope::for($this->admin, $this->north->getKey()), 30);

        $this->assertSame(['visitors' => 4, 'returning' => 1, 'visits' => 2, 'avg_seconds' => 2700], $figures['current']);
        $this->assertSame(['visitors' => 1, 'returning' => 0, 'visits' => 1, 'avg_seconds' => 1200], $figures['previous']);

        $this->assertSame(25, DashboardVisitorTrendsWidget::percentage(1, 4));
        $this->assertSame('45 min', DashboardVisitorTrendsWidget::duration(2700));
        $this->assertSame('1 h 5 min', DashboardVisitorTrendsWidget::duration(3900));

        $this->actingAs($this->admin);
        Livewire::test(DashboardVisitorTrendsWidget::class, ['pageFilters' => ['site_id' => $this->north->getKey()]])
            ->assertSuccessful()
            ->assertSee(__('Wiederkehrende Besucher'))
            ->assertSee('25 %')
            ->assertSee(DashboardVisitorTrendsWidget::duration(2700));
    }

    public function test_top_companies_are_normalised_and_counted_by_visit(): void
    {
        $company = fn (?string $name): Visitor => Visitor::factory()->create(['company' => $name]);

        $this->attend($this->north, now()->subHour(), visitor: $company(' Acme Pty '));
        $this->attend($this->north, now()->subDays(3), visitor: $company('acme pty'));
        // Two Acme people on one visit: one visit.
        $shared = Visit::factory()->create(['site_id' => $this->north->getKey(), 'is_walk_in' => true, 'scheduled_from' => now()->subDays(5), 'scheduled_until' => now()->subDays(5)->addHour()]);
        $this->attend($this->north, now()->subDays(5), visitor: $company('ACME PTY'), visit: $shared);
        $this->attend($this->north, now()->subDays(5), visitor: $company('Acme Pty'), visit: $shared);
        $this->attend($this->north, now()->subDays(2), visitor: $company('Beta Builders'));
        $this->attend($this->north, now()->subDays(2), visitor: $company(null));
        $this->attend($this->north, now()->subDays(2), visitor: $company('   '));
        // Outside 30 days, and another site.
        $this->attend($this->north, now()->subDays(40), visitor: $company('Beta Builders'));
        $this->attend($this->north, now()->subDays(41), visitor: $company('Beta Builders'));
        $this->attend($this->north, now()->subDays(42), visitor: $company('Beta Builders'));
        $this->attend($this->south, now()->subDays(1), visitor: $company('Gamma'));

        $north = DashboardScope::for($this->admin, $this->north->getKey());
        $top = $this->trends()->topCompanies($north, 30);

        $this->assertCount(2, $top);
        $this->assertSame('acme pty', Str::lower($top[0]['company']));
        $this->assertSame(3, $top[0]['visits']);
        $this->assertSame(['company' => 'Beta Builders', 'visits' => 1], $top[1]);

        $ninety = $this->trends()->topCompanies($north, 90);
        $this->assertSame(['company' => 'Beta Builders', 'visits' => 4], $ninety[0]);

        $this->actingAs($this->admin);
        Livewire::test(DashboardTopCompaniesWidget::class, ['pageFilters' => ['site_id' => $this->north->getKey()]])
            ->assertSuccessful()
            ->assertSee(__('Häufigste Firmen'))
            ->assertSee('Beta Builders')
            ->assertDontSee('Gamma')
            ->assertSee(trans_choice(':count Besuch|:count Besuche', 3, ['count' => 3]))
            ->set('filter', '90')
            ->assertSee(trans_choice(':count Besuch|:count Besuche', 4, ['count' => 4]));
    }

    public function test_trends_are_cached_per_scope_and_period(): void
    {
        $north = DashboardScope::for($this->admin, $this->north->getKey());
        $this->assertSame([], $this->trends()->busiestHours($north, 30));

        $this->attend($this->north, now()->subHour());
        $this->assertSame([], $this->trends()->busiestHours($north, 30));
        // Another period is its own entry.
        $this->assertNotSame([], $this->trends()->busiestHours($north, 90));

        $this->travel(DashboardTrends::TTL + 1)->seconds();
        $this->assertNotSame([], $this->trends()->busiestHours(DashboardScope::for($this->admin, $this->north->getKey()), 30));
    }

    public function test_health_is_only_shown_to_administrators(): void
    {
        $this->actingAs($this->admin);
        $this->assertTrue(DashboardHealthWidget::canView());

        $receptionist = User::factory()->create(['site_id' => $this->north->getKey()]);
        $receptionist->assignRole('receptionist');
        $this->actingAs($receptionist);
        $this->assertFalse(DashboardHealthWidget::canView());

        $plain = User::factory()->create();
        $this->actingAs($plain);
        $this->assertFalse(DashboardHealthWidget::canView());
    }

    public function test_kiosks_offline_during_opening_hours_are_counted(): void
    {
        $kiosk = function (Site $site, string $name, ?Carbon $lastSeen, bool $enrolled = true, bool $revoked = false): Kiosk {
            $kiosk = Kiosk::query()->create(['site_id' => $site->getKey(), 'name' => $name]);
            $kiosk->forceFill([
                'enrolled_at' => $enrolled ? now()->subMonth() : null,
                'last_seen_at' => $lastSeen,
                'revoked_at' => $revoked ? now()->subDay() : null,
            ])->save();

            return $kiosk;
        };

        $kiosk($this->north, 'Front desk', now()->subMinutes(5));
        $kiosk($this->north, 'Gym', now()->subMinutes(20));
        $kiosk($this->north, 'Library', null);
        $kiosk($this->north, 'Old kiosk', now()->subDays(3), revoked: true);
        $kiosk($this->north, 'New kiosk', null, enrolled: false);
        // South switches the report off.
        app(SettingsRepository::class)->saveSite($this->south, ['organisation.visitor_experience.kiosk_hours' => 'off'], null);
        $kiosk($this->south, 'South gate', now()->subHour());

        $kiosks = $this->health()->kiosks(DashboardScope::for($this->admin));
        $this->assertSame(4, $kiosks['total']);
        $this->assertSame(2, $kiosks['offline']);
        $this->assertSame(SystemHealth::PROBLEM, $kiosks['status']);
        $this->assertSame(['Front desk', 'Gym', 'Library', 'South gate'], array_column($kiosks['devices'], 'name'));

        $south = $this->health()->kiosks(DashboardScope::for($this->admin, $this->south->getKey()));
        $this->assertSame(['status' => SystemHealth::OK, 'total' => 1, 'offline' => 0], array_intersect_key($south, array_flip(['status', 'total', 'offline'])));

        // Saturday: outside opening hours.
        $this->travelTo(Carbon::parse('2026-11-21 10:00:00', (string) config('app.timezone')));
        $this->assertSame(0, $this->health()->kiosks(DashboardScope::for($this->admin))['offline']);

        // Weekday evening, after the default 07:00-18:00.
        $this->travelTo(Carbon::parse('2026-11-18 19:00:00', (string) config('app.timezone')));
        $this->assertSame(0, $this->health()->kiosks(DashboardScope::for($this->admin))['offline']);

        $this->assertSame(SystemHealth::OFF, $this->health()->kiosks(DashboardScope::for($this->admin, Site::factory()->create()->getKey()))['status']);
    }

    public function test_failed_print_jobs_and_notifications_are_counted(): void
    {
        $audit = app(AuditLogger::class);
        $audit->record('badge.print_failed', site: $this->north, result: 'failure');
        $audit->record('badge.print_failed', site: $this->north, result: 'failure');
        $audit->record('badge.print_failed', site: $this->south, result: 'failure');
        $this->travel(-8)->days();
        $audit->record('badge.print_failed', site: $this->north, result: 'failure');
        $this->travelBack();
        $this->travelTo(Carbon::parse('2026-11-18 10:00:00', (string) config('app.timezone')));

        DB::table('badge_printers')->insert([
            ['site_id' => $this->north->getKey(), 'name' => 'Front', 'host' => 'printer.test', 'driver' => 'zpl', 'label' => '62x100', 'is_enabled' => true,
                'last_printed_at' => now()->subHour(), 'last_failed_at' => now()->subMinutes(5), 'created_at' => now(), 'updated_at' => now()],
            ['site_id' => $this->north->getKey(), 'name' => 'Back', 'host' => 'printer2.test', 'driver' => 'zpl', 'label' => '62x100', 'is_enabled' => true,
                'last_printed_at' => now()->subMinutes(5), 'last_failed_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $printing = $this->health()->printing(DashboardScope::for($this->admin, $this->north->getKey()));
        $this->assertSame(['status' => SystemHealth::PROBLEM, 'failed' => 2, 'failing_printers' => 1, 'printers' => 2], $printing);
        $this->assertSame(3, $this->health()->printing(DashboardScope::for($this->admin))['failed']);
        $this->assertSame(SystemHealth::WARNING, $this->health()->printing(DashboardScope::for($this->admin, $this->south->getKey()))['status']);

        $failedJob = function (string $displayName, Carbon $at): void {
            DB::table('failed_jobs')->insert([
                'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
                'payload' => json_encode(['displayName' => $displayName, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call']),
                'exception' => 'RuntimeException: failed', 'failed_at' => $at,
            ]);
        };

        $this->assertSame(['status' => SystemHealth::OK, 'failed' => 0, 'other_failed' => 0], $this->health()->notifications());

        $failedJob('App\\Notifications\\Host\\VisitCreated', now()->subDay());
        $failedJob('App\\Notifications\\Host\\GuestCheckedInMailNotification', now()->subDays(6));
        $failedJob('App\\Emergency\\Broadcast\\SendEmergencyEmail', now()->subHours(2));
        $failedJob('App\\Badges\\Jobs\\PrintBadge', now()->subHour());
        $failedJob('App\\Notifications\\Host\\VisitCreated', now()->subDays(10));

        $this->assertSame(['status' => SystemHealth::WARNING, 'failed' => 3, 'other_failed' => 1], $this->health()->notifications());
    }

    public function test_queue_heartbeat_entra_sync_and_messageport_status(): void
    {
        config()->set('health.cache_store', 'array');
        app(OperationalHeartbeat::class)->markQueueLoop();
        app(OperationalHeartbeat::class)->markSchedulerRun();
        $this->assertSame(['status' => SystemHealth::OK, 'queue' => true, 'scheduler' => true], $this->health()->queue());

        $this->travel((int) config('health.queue_stale_after_seconds') + 1)->seconds();
        app(OperationalHeartbeat::class)->markSchedulerRun();
        $this->assertSame(['status' => SystemHealth::PROBLEM, 'queue' => false, 'scheduler' => true], $this->health()->queue());

        // Entra: off, never run, succeeded, failed.
        config()->set('host_directory.entra.enabled', false);
        $this->assertSame(SystemHealth::OFF, $this->health()->entra()['status']);
        config()->set('host_directory.entra.enabled', true);
        $this->assertSame(SystemHealth::WARNING, $this->health()->entra()['status']);

        $audit = app(AuditLogger::class);
        $audit->record('host_directory.synced', context: ['provider' => 'entra', 'created' => 2, 'linked' => 1, 'updated' => 3, 'deactivated' => 0]);
        $entra = $this->health()->entra();
        $this->assertSame(SystemHealth::OK, $entra['status']);
        $this->assertTrue($entra['succeeded']);
        $this->assertSame(['created' => 2, 'linked' => 1, 'updated' => 3, 'deactivated' => 0], $entra['counts']);

        $this->travel(5)->minutes();
        $audit->record('host_directory.sync_failed', result: 'failure', context: ['provider' => 'entra', 'error' => 'Group 1234 returned no users']);
        $this->assertSame(SystemHealth::PROBLEM, $this->health()->entra()['status']);
        $this->assertSame([], $this->health()->entra()['counts']);

        // MessagePort: off, switched on without settings, configured without and with a webhook secret.
        config()->set('emergency.sms_enabled', false);
        $this->assertSame(SystemHealth::OFF, $this->health()->sms()['status']);

        config()->set('emergency.sms_enabled', true);
        config()->set('messageport.access_token', null);
        config()->set('messageport.webhook_secret', null);
        $this->assertSame(['status' => SystemHealth::PROBLEM, 'enabled' => true, 'configured' => false, 'webhook' => false, 'last_receipt_at' => null], $this->health()->sms());

        config()->set('messageport.access_token', 'secret-access-token-value');
        config()->set('messageport.token_id', 'secret-token-id');
        config()->set('messageport.sender_id', 'SCHOOL');
        $this->assertSame(SystemHealth::WARNING, $this->health()->sms()['status']);
        config()->set('messageport.webhook_secret', 'secret-webhook-value');

        $rollCall = RollCall::query()->create(['site_id' => $this->north->getKey(), 'kind' => RollCall::KIND_DRILL, 'started_at' => now()->subHour()]);
        $delivered = EmergencyNotification::query()->create(['roll_call_id' => $rollCall->getKey(), 'channel' => EmergencyNotification::CHANNEL_SMS, 'status' => EmergencyNotification::DELIVERED, 'to_masked' => '04•• ••• 123']);
        $delivered->forceFill(['updated_at' => now()->subMinutes(30)])->saveQuietly();
        // Accepted by MessagePort but no receipt yet; e-mail rows do not count.
        EmergencyNotification::query()->create(['roll_call_id' => $rollCall->getKey(), 'channel' => EmergencyNotification::CHANNEL_SMS, 'status' => EmergencyNotification::ACCEPTED]);
        EmergencyNotification::query()->create(['roll_call_id' => $rollCall->getKey(), 'channel' => EmergencyNotification::CHANNEL_EMAIL, 'status' => EmergencyNotification::SENT]);

        $sms = $this->health()->sms();
        $this->assertSame(SystemHealth::OK, $sms['status']);
        $this->assertTrue($sms['configured']);
        $this->assertSame(now()->subMinutes(30)->toIso8601String(), $sms['last_receipt_at']);

        // The tile shows statuses, never secrets, numbers or error texts.
        Cache::flush();
        $this->actingAs($this->admin);
        Livewire::test(DashboardHealthWidget::class)
            ->assertSuccessful()
            ->assertSee(__('Systemzustand'))
            ->assertSee(__('Warteschlange läuft nicht'))
            ->assertSee(__('Notfall-SMS (MessagePort)'))
            ->assertSee(__('Eingerichtet'))
            ->assertDontSee('secret-access-token-value')
            ->assertDontSee('secret-webhook-value')
            ->assertDontSee('SCHOOL')
            ->assertDontSee('Group 1234');
    }

    public function test_dashboard_renders_trends_and_health_for_an_admin(): void
    {
        $this->attend($this->north, now()->subHour(), type: $this->contractor, visitor: Visitor::factory()->create(['company' => 'Acme']));

        $this->actingAs($this->admin);
        Livewire::test(Dashboard::class)->assertSuccessful()
            ->assertSeeLivewire(DashboardArrivalsChartWidget::class)
            ->assertSeeLivewire(DashboardBusiestHoursWidget::class)
            ->assertSeeLivewire(DashboardVisitorTrendsWidget::class)
            ->assertSeeLivewire(DashboardTopCompaniesWidget::class)
            ->assertSeeLivewire(DashboardHealthWidget::class);

        Livewire::test(DashboardHealthWidget::class)->assertSuccessful()
            ->assertSee(__('Kiosks'))->assertSee(__('Ausweisdruck'))->assertSee(__('Benachrichtigungen'))
            ->assertSee(__('Entra-Gastgebersynchronisierung'))->assertSee(__('Warteschlange'));
    }
}
