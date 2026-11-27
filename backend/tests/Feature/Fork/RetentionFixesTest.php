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
use App\Models\Site;
use App\Models\Visit;
use App\Models\Visitor;
use App\VisitorWorkflow\Models\VisitResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * v1.0.1: issue #3 (data retention). Legal holds without an end date protect evidence files too,
 * and people on the credential register are not purged as orphans.
 */
class RetentionFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_indefinite_legal_hold_keeps_the_visits_evidence(): void
    {
        Storage::fake('local');
        config(['organisation.evidence_retention_days' => 30]);
        $site = Site::factory()->create();
        $indefinite = Visit::factory()->create(['site_id' => $site->getKey(), 'retention_hold_until' => null, 'retention_hold_reason' => 'Police request']);
        $dated = Visit::factory()->create(['site_id' => $site->getKey(), 'retention_hold_until' => now()->addMonth(), 'retention_hold_reason' => 'Incident review']);
        $ended = Visit::factory()->create(['site_id' => $site->getKey(), 'retention_hold_until' => now()->subDay(), 'retention_hold_reason' => 'Closed matter']);
        $free = Visit::factory()->create(['site_id' => $site->getKey()]);
        $visitor = Visitor::factory()->create();

        foreach ([[$indefinite, 'indefinite.png'], [$dated, 'dated.png'], [$ended, 'ended.png'], [$free, 'free.png']] as [$visit, $file]) {
            Storage::disk('local')->put('evidence/1/'.$file, 'x');
            $response = VisitResponse::query()->create(['visit_id' => $visit->getKey(), 'visitor_id' => $visitor->getKey(), 'field_key' => $file, 'field_label' => 'Photo', 'field_type' => 'photo', 'file_path' => 'evidence/1/'.$file]);
            $response->forceFill(['created_at' => now()->subDays(31)])->save();
        }

        $this->artisan('visitorportal:evidence:purge')->assertSuccessful();

        Storage::disk('local')->assertExists('evidence/1/indefinite.png');
        Storage::disk('local')->assertExists('evidence/1/dated.png');
        Storage::disk('local')->assertMissing('evidence/1/ended.png');
        Storage::disk('local')->assertMissing('evidence/1/free.png');

        // Visits and evidence follow the same rule.
        $this->assertEqualsCanonicalizing([$indefinite->id, $dated->id], Visit::query()->onLegalHold()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$ended->id, $free->id], Visit::query()->notOnLegalHold()->pluck('id')->all());
    }

    public function test_people_on_the_credential_register_are_not_purged_as_orphans(): void
    {
        $type = CredentialType::query()->create(['name' => 'Screening check', 'expiry_warning_days' => 30]);
        $holder = Visitor::factory()->create(['first_name' => 'Imported']);
        $credential = VisitorCredential::query()->create([
            'visitor_id' => $holder->getKey(), 'credential_type_id' => $type->getKey(),
            'number' => 'N1', 'number_hash' => CredentialRegister::hash('N1'),
            'status' => VisitorCredential::UNCHECKED, 'source' => VisitorCredential::SOURCE_STAFF,
        ]);
        $orphan = Visitor::factory()->create(['first_name' => 'Orphan']);
        DB::table('visitors')->update(['updated_at' => now()->subDays(400)]);

        $this->artisan('visits:purge-expired', ['--days' => 365])->assertSuccessful();

        $this->assertModelExists($holder);
        $this->assertModelExists($credential);
        $this->assertModelMissing($orphan);

        // Without a credential any more, the person is an ordinary orphan again.
        $credential->delete();
        $this->artisan('visits:purge-expired', ['--days' => 365])->assertSuccessful();
        $this->assertModelMissing($holder);
    }
}
