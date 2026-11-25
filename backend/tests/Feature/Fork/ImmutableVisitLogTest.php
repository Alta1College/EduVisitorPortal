<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Emergency\AutoSignOut;
use App\Enums\VisitStatusEnum;
use App\Filament\Resources\Visitors\Pages\EditVisitor;
use App\Filament\Resources\Visits\Pages\EditVisit;
use App\Filament\Resources\Visits\Pages\ListVisits;
use App\Filament\Resources\Visits\Pages\ViewVisit;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\PublicVisit\Channel;
use App\PublicVisit\Commands\RecordDeparture;
use App\Services\VisitActionService;
use App\Settings\SettingsRepository;
use App\Tasks\CompleteFinishedVisits;
use App\VisitLog\AttendedVisitLocked;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\PermissionHelper;
use Tests\TestCase;

/**
 * Visits with attendance are visitor-log history: view-only apart from appended notes, never
 * deleted except by the retention purge. Visitor detail changes are audited; the audit log
 * itself is protected by database triggers.
 */
class ImmutableVisitLogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->admin = (new PermissionHelper)->getSuperAdminUser();
    }

    private function visit(bool $attended, array $attributes = []): Visit
    {
        $visit = Visit::factory()->create([
            'scheduled_from' => now()->addDay(),
            'scheduled_until' => now()->addDay()->addHour(),
            'notes' => 'Booked by phone',
            ...$attributes,
        ]);
        $visitor = Visitor::factory()->create(['first_name' => 'Pat', 'name' => 'Parent', 'email' => 'pat@example.org', 'phone' => '0412 345 678', 'company' => 'Old Co']);
        $visit->visitors()->attach($visitor->getKey(), $attended ? ['checked_in_at' => now()->subMinutes(30)] : []);

        return $visit;
    }

    private function checkIn(Visit $visit): void
    {
        DB::table('visit_visitor')->where('visit_id', $visit->getKey())->update(['checked_in_at' => now()]);
    }

    public function test_admin_cannot_open_or_save_the_edit_page_of_an_attended_visit(): void
    {
        $visit = $this->visit(attended: true);

        $this->assertFalse($this->admin->can('update', $visit));
        Livewire::actingAs($this->admin)->test(EditVisit::class, ['record' => $visit->getRouteKey()])->assertForbidden();
        Livewire::actingAs($this->admin)->test(ViewVisit::class, ['record' => $visit->getRouteKey()])
            ->assertOk()
            ->assertActionHidden('edit');

        // A page opened before the visitor arrived cannot save afterwards.
        $later = $this->visit(attended: false);
        $page = Livewire::actingAs($this->admin)->test(EditVisit::class, ['record' => $later->getRouteKey()])
            ->fillForm(['title' => 'Rewritten history']);
        $this->checkIn($later);
        $page->call('save')->assertForbidden();

        $this->assertSame('test', $later->fresh()->title);
    }

    public function test_attended_visits_cannot_be_deleted_singly_or_in_bulk(): void
    {
        $attended = $this->visit(attended: true);
        $future = $this->visit(attended: false);

        $this->assertFalse($this->admin->can('delete', $attended));
        $this->assertFalse($this->admin->can('cancel', $attended));

        Livewire::actingAs($this->admin)->test(EditVisit::class, ['record' => $future->getRouteKey()])
            ->assertActionDoesNotExist('delete');

        // Bulk "delete" is replaced by "cancel selected", which skips attended visits.
        Livewire::actingAs($this->admin)->test(ListVisits::class)
            ->assertTableBulkActionDoesNotExist('delete')
            ->callTableBulkAction('cancelSelected', [$attended, $future]);

        $this->assertSame(VisitStatusEnum::Planned->value, $attended->fresh()->status);
        $this->assertNull($attended->fresh()->canceled_at);
        $this->assertSame(VisitStatusEnum::Canceled->value, $future->fresh()->status);
        $this->assertDatabaseHas('audit_events', ['action' => 'visit.canceled', 'subject_id' => (string) $future->getKey()]);

        // The model refuses even without a policy check.
        $this->expectException(AttendedVisitLocked::class);
        $attended->delete();
    }

    public function test_an_attendance_cannot_be_detached_and_a_visitor_with_attendance_cannot_be_deleted(): void
    {
        $visit = $this->visit(attended: true);
        $visitor = $visit->visitors()->sole();

        $this->assertFalse($this->admin->can('delete', $visitor));

        try {
            $visit->visitors()->sync([]);
            $this->fail('Detaching a recorded attendance must be refused.');
        } catch (AttendedVisitLocked) {
        }

        try {
            $visitor->delete();
            $this->fail('Deleting a visitor with attendance must be refused.');
        } catch (AttendedVisitLocked) {
        }

        $this->assertDatabaseHas('visit_visitor', ['visit_id' => $visit->getKey(), 'visitor_id' => $visitor->getKey()]);
    }

    public function test_admin_can_append_a_note_to_an_attended_visit_and_it_is_audited(): void
    {
        $visit = $this->visit(attended: true);

        Livewire::actingAs($this->admin)->test(ViewVisit::class, ['record' => $visit->getRouteKey()])
            ->callAction('addNote', data: ['note' => 'Left an umbrella at reception'])
            ->assertHasNoActionErrors();

        $notes = (string) $visit->fresh()->notes;
        $this->assertStringStartsWith('Booked by phone', $notes);
        $this->assertStringContainsString(trim($this->admin->fullName).'] Left an umbrella at reception', $notes);

        $event = AuditEvent::query()->where('action', 'visit.note_added')->sole();
        $this->assertSame($this->admin->getKey(), $event->actor_user_id);
        $this->assertSame((string) $visit->getKey(), $event->subject_id);
        $this->assertSame('Left an umbrella at reception', $event->context['note']);

        // Existing note text cannot be rewritten.
        $this->expectException(AttendedVisitLocked::class);
        $visit->fresh()->update(['notes' => 'Nothing happened']);
    }

    public function test_an_unattended_future_visit_can_still_be_edited_and_cancelled(): void
    {
        $visit = $this->visit(attended: false);

        Livewire::actingAs($this->admin)->test(EditVisit::class, ['record' => $visit->getRouteKey()])
            ->fillForm(['title' => 'Corrected title'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('Corrected title', $visit->fresh()->title);

        Livewire::actingAs($this->admin)->test(EditVisit::class, ['record' => $visit->getRouteKey()])
            ->callAction('cancel')
            ->assertRedirect(VisitResource::getUrl('index'));

        $visit->refresh();
        $this->assertSame(VisitStatusEnum::Canceled->value, $visit->status);
        $this->assertSame($this->admin->getKey(), $visit->canceled_by_user_id);
        $this->assertDatabaseHas('audit_events', ['action' => 'visit.canceled', 'actor_user_id' => $this->admin->getKey()]);
    }

    public function test_portal_changes_to_an_attended_visit_are_rejected(): void
    {
        $host = (new PermissionHelper)->getUserUser();
        $visit = $this->visit(attended: true, attributes: ['host_user_id' => $host->getKey()]);
        $future = $this->visit(attended: false, attributes: ['host_user_id' => $host->getKey()]);
        $this->actingAs($host);

        $this->get(route('portal.visits.edit', $visit))->assertForbidden();
        $this->patch(route('portal.visits.update', $visit), ['title' => 'x'])->assertForbidden();
        $this->patch(route('portal.visits.reschedule', $visit), [
            'scheduled_from' => now()->addDays(2)->format('Y-m-d H:i'),
            'scheduled_until' => now()->addDays(2)->addHour()->format('Y-m-d H:i'),
        ])->assertForbidden();
        $this->post(route('portal.visits.cancel', $visit))->assertForbidden();
        $this->post(route('portal.visits.reopen', $visit))->assertForbidden();
        $this->assertSame(VisitStatusEnum::Planned->value, $visit->fresh()->status);

        // The host's unattended future visit can still be cancelled.
        $this->post(route('portal.visits.cancel', $future))->assertRedirect();
        $this->assertSame(VisitStatusEnum::Canceled->value, $future->fresh()->status);
    }

    public function test_live_workflow_still_works_on_an_attended_visit(): void
    {
        $visit = $this->visit(attended: true, attributes: ['scheduled_from' => now()->subHour(), 'scheduled_until' => now()->addHour()]);
        $first = $visit->visitors()->sole();
        $second = Visitor::factory()->create();
        $visit->visitors()->attach($second->getKey());
        $third = Visitor::factory()->create();
        $visit->visitors()->attach($third->getKey(), ['checked_in_at' => now()->subHours(3)]);
        $receptionist = (new PermissionHelper)->getReceptionistUser();
        $actions = app(VisitActionService::class);

        // Reception check-in and check-out.
        $actions->checkInParticipant($visit, $second, $receptionist);
        $actions->checkOutParticipant($visit, $first, $receptionist);
        $this->assertNotNull(DB::table('visit_visitor')->where('visitor_id', $second->getKey())->value('checked_in_at'));
        $this->assertNotNull(DB::table('visit_visitor')->where('visitor_id', $first->getKey())->value('checked_out_at'));

        // Self sign-out (kiosk/QR/e-mail link share this command).
        app(RecordDeparture::class)->handleAttendance($visit, $second, Channel::Kiosk);
        $this->assertSame('kiosk', DB::table('visit_visitor')->where('visitor_id', $second->getKey())->value('checked_out_via'));

        // Automatic sign-out.
        $site = Site::query()->findOrFail($visit->site_id);
        app(SettingsRepository::class)->saveSite($site, ['organisation.visitor_experience.auto_sign_out_time' => now($site->timezone)->subMinute()->format('H:i')], null);
        $this->assertSame(1, app(AutoSignOut::class)->runForSite($site));
        $this->assertSame('auto', DB::table('visit_visitor')->where('visitor_id', $third->getKey())->value('checked_out_via'));

        // Finishing the visit (status "completed") is allowed.
        $this->travelTo(now()->addHours(2));
        $this->assertSame(1, app(CompleteFinishedVisits::class)());
        $this->assertSame(VisitStatusEnum::Completed->value, $visit->fresh()->status);
    }

    public function test_model_guard_blocks_direct_changes_after_attendance(): void
    {
        $visit = $this->visit(attended: true);

        foreach ([
            ['title' => 'Changed'],
            ['scheduled_from' => now()->subYear()],
            ['host_user_id' => $this->admin->getKey()],
            ['is_confidential' => true],
            ['status' => VisitStatusEnum::Canceled->value],
        ] as $change) {
            try {
                $visit->fresh()->update($change);
                $this->fail('Change must be refused: '.implode(', ', array_keys($change)));
            } catch (AttendedVisitLocked) {
            }
        }

        $this->assertSame('test', $visit->fresh()->title);

        // Allowed: finishing the visit and legal-hold fields.
        $visit->fresh()->update(['status' => VisitStatusEnum::Completed->value, 'retention_hold_reason' => 'Incident review']);
        $this->assertSame('Incident review', $visit->fresh()->retention_hold_reason);

        // Before attendance, the visit can be changed freely.
        $open = $this->visit(attended: false);
        $open->update(['title' => 'Changed']);
        $this->assertSame('Changed', $open->fresh()->title);
    }

    public function test_retention_purge_still_removes_old_attended_visits(): void
    {
        $old = $this->visit(attended: true, attributes: ['scheduled_from' => now()->subDays(400), 'scheduled_until' => now()->subDays(400)->addHour()]);
        $recent = $this->visit(attended: true);

        $this->artisan('visits:purge-expired', ['--days' => 365])->assertSuccessful();

        $this->assertDatabaseMissing('visits', ['id' => $old->getKey()]);
        $this->assertDatabaseMissing('visit_visitor', ['visit_id' => $old->getKey()]);
        $this->assertDatabaseHas('visits', ['id' => $recent->getKey()]);
    }

    public function test_visitor_changes_are_audited_with_old_and_new_values(): void
    {
        $visit = $this->visit(attended: true);
        $visitor = $visit->visitors()->sole();

        Livewire::actingAs($this->admin)->test(EditVisitor::class, ['record' => $visitor->getRouteKey()])
            ->fillForm(['first_name' => 'Patricia', 'email' => 'patricia@example.net', 'company' => 'Acme', 'notes' => 'Private remark'])
            ->call('save')
            ->assertHasNoFormErrors();

        $event = AuditEvent::query()->where('action', 'visitor.updated')->sole();
        $changes = $event->context['changes'];

        $this->assertSame($this->admin->getKey(), $event->actor_user_id);
        $this->assertSame('Visitor', $event->subject_type);
        $this->assertSame(['old' => 'Pat', 'new' => 'Patricia'], $changes['first_name']);
        $this->assertSame(['old' => 'Old Co', 'new' => 'Acme'], $changes['company']);
        $this->assertSame(['old' => 'p***@example.org', 'new' => 'p***@example.net'], $changes['email']);
        $this->assertSame(['changed' => true], $changes['notes']);
        $this->assertArrayNotHasKey('phone', $changes);
        $this->assertStringNotContainsString('Private remark', json_encode($event->context));
        $this->assertStringNotContainsString('patricia@', json_encode($event->context));

        $visitor->refresh()->update(['phone' => '0499 111 222']);
        $phone = AuditEvent::query()->where('action', 'visitor.updated')->latest('id')->first()->context['changes']['phone'];
        $this->assertSame(['old' => '*******678', 'new' => '*******222'], $phone);
    }

    public function test_audit_log_rejects_raw_updates_and_deletes_except_for_the_purge(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Audit triggers are MariaDB/MySQL only.');
        }

        $this->assertSame(2, DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
            ->where('EVENT_OBJECT_TABLE', 'audit_events')
            ->count());

        $event = app(AuditLogger::class)->record('test.protected');

        foreach ([
            fn () => DB::table('audit_events')->where('id', $event->getKey())->update(['action' => 'tampered']),
            fn () => DB::table('audit_events')->where('id', $event->getKey())->delete(),
        ] as $tamper) {
            try {
                $tamper();
                $this->fail('The trigger must reject this statement.');
            } catch (QueryException $exception) {
                $this->assertSame('45000', $exception->errorInfo[0]);
            }
        }

        $this->assertDatabaseHas('audit_events', ['id' => $event->getKey(), 'action' => 'test.protected']);

        // Updates stay forbidden even with the purge variable; deletes are then allowed.
        DB::statement('SET @visitorportal_audit_purge = 1');

        try {
            DB::table('audit_events')->where('id', $event->getKey())->update(['action' => 'tampered']);
            $this->fail('UPDATE must always be rejected.');
        } catch (QueryException) {
        } finally {
            DB::statement('SET @visitorportal_audit_purge = NULL');
        }

        // The retention purge sets the variable for its own deletes and resets it.
        config(['organisation.audit_retention_days' => 30]);
        AuditEvent::query()->create(['occurred_at' => now()->subDays(31), 'action' => 'old', 'result' => 'success', 'channel' => 'web']);

        $this->artisan('visitorportal:audit:purge')->assertSuccessful();

        $this->assertDatabaseMissing('audit_events', ['action' => 'old']);
        $this->assertDatabaseHas('audit_events', ['action' => 'test.protected']);
        $this->assertNull(DB::selectOne('SELECT @visitorportal_audit_purge AS flag')->flag);
    }
}
