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
use App\Models\RecurringVisitSeries;
use App\Models\Site;
use App\Models\Visit;
use App\Models\Visitor;
use App\VisitorWorkflow\Models\VisitResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PermissionHelper;
use Tests\TestCase;

/**
 * Review findings 6, 7 and 9 (finding 5, Entra site removal, is in EntraHostSyncTest).
 */
class ReviewMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_series_keep_their_day_after_a_short_month(): void
    {
        $user = (new PermissionHelper)->getUser();
        $participant = Visitor::factory()->create(['created_by_user_id' => $user->id]);

        $this->actingAs($user)->post(route('portal.visits.store'), [
            'title' => 'Month-end stocktake',
            'host_user_id' => $user->id,
            'scheduled_from' => '2027-01-31 09:00:00',
            'scheduled_until' => '2027-01-31 10:00:00',
            'status' => 'planned',
            'recurrence_enabled' => '1',
            'recurrence_frequency' => RecurringVisitSeries::FREQUENCY_MONTHLY,
            'recurrence_end_type' => RecurringVisitSeries::END_COUNT,
            'recurrence_occurrence_count' => 4,
            'participants' => [['visitor_id' => $participant->id]],
        ])->assertRedirect();

        $this->assertSame(['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30'], Visit::query()->orderBy('scheduled_from')->get()
            ->map(fn (Visit $visit) => $visit->scheduled_from->format('Y-m-d'))->all());
    }

    public function test_evidence_of_a_visit_under_a_legal_hold_is_kept(): void
    {
        Storage::fake('local');
        config(['organisation.evidence_retention_days' => 30]);
        $site = Site::factory()->create();
        $held = Visit::factory()->create(['site_id' => $site->getKey(), 'retention_hold_until' => now()->addMonth(), 'retention_hold_reason' => 'Incident review']);
        $free = Visit::factory()->create(['site_id' => $site->getKey()]);
        $visitor = Visitor::factory()->create();

        foreach ([[$held, 'held.png'], [$free, 'free.png']] as [$visit, $file]) {
            Storage::disk('local')->put('evidence/1/'.$file, 'x');
            $response = VisitResponse::query()->create(['visit_id' => $visit->getKey(), 'visitor_id' => $visitor->getKey(), 'field_key' => $file, 'field_label' => 'Photo', 'field_type' => 'photo', 'file_path' => 'evidence/1/'.$file]);
            $response->forceFill(['created_at' => now()->subDays(31)])->save();
        }

        $this->artisan('visitorportal:evidence:purge')->assertSuccessful();

        Storage::disk('local')->assertExists('evidence/1/held.png');
        Storage::disk('local')->assertMissing('evidence/1/free.png');

        // Once the hold has ended, the next purge removes it.
        $held->forceFill(['retention_hold_until' => now()->subDay()])->save();
        $this->artisan('visitorportal:evidence:purge')->assertSuccessful();
        Storage::disk('local')->assertMissing('evidence/1/held.png');
    }

    public function test_the_credential_review_list_finds_every_record_needing_action(): void
    {
        $type = CredentialType::query()->create(['name' => 'Screening check', 'expiry_warning_days' => 30, 'recheck_days' => 365]);
        $make = function (string $status, ?string $checkedAt, ?string $expires) use ($type): VisitorCredential {
            $visitor = Visitor::factory()->create();

            return VisitorCredential::query()->create([
                'visitor_id' => $visitor->getKey(), 'credential_type_id' => $type->getKey(),
                'number' => 'N'.$visitor->getKey(), 'number_hash' => CredentialRegister::hash('N'.$visitor->getKey()),
                'status' => $status, 'checked_at' => $checkedAt, 'expires_on' => $expires, 'source' => VisitorCredential::SOURCE_STAFF,
            ]);
        };

        $unchecked = $make(VisitorCredential::UNCHECKED, null, null);
        $expired = $make(VisitorCredential::VALID, now()->subMonth()->toDateTimeString(), now()->subDay()->toDateString());
        $recheck = $make(VisitorCredential::VALID, now()->subDays(400)->toDateTimeString(), now()->addYears(2)->toDateString());
        $invalid = $make(VisitorCredential::INVALID, now()->subDay()->toDateTimeString(), null);
        DB::table('visitor_credentials')->whereIn('id', [$unchecked->id, $expired->id, $recheck->id, $invalid->id])->update(['updated_at' => now()->subYear()]);

        // Many newer records that are fine (valid, or merely expiring soon) do not push them out.
        for ($i = 0; $i < 5; $i++) {
            $make(VisitorCredential::VALID, now()->subDay()->toDateTimeString(), now()->addYear()->toDateString());
        }
        $make(VisitorCredential::VALID, now()->subDay()->toDateTimeString(), now()->addDays(10)->toDateString());

        $ids = app(CredentialRegister::class)->needingAttention(limit: 10)->pluck('id')->sort()->values()->all();

        $this->assertSame(collect([$unchecked->id, $expired->id, $recheck->id, $invalid->id])->sort()->values()->all(), $ids);
        $this->assertCount(2, app(CredentialRegister::class)->needingAttention(limit: 2));
    }
}
