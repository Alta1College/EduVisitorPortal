<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use App\Audit\AuditEvent;
use App\HostDirectory\Entra\DirectoryAccount;
use App\HostDirectory\Entra\EntraHostSync;
use App\HostDirectory\Entra\EntraSyncException;
use App\HostDirectory\LocalHostDirectory;
use App\Models\Site;
use App\Models\User;
use App\Settings\SettingsRegistry;
use App\Settings\SettingsRepository;
use App\Support\VisitorPortalPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class EntraHostSyncTest extends TestCase
{
    use RefreshDatabase;

    private const CENTRAL_GROUP = '11111111-1111-1111-1111-111111111111';

    private const CAMPUS_GROUP = '22222222-2222-2222-2222-222222222222';

    private Site $central;

    private Site $campus;

    /** @var array<string, list<array<string, mixed>>> group ID => users Graph returns */
    private array $groups = [];

    private bool $rejectCredentials = false;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        VisitorPortalPermissions::sync();
        config([
            'host_directory.entra.enabled' => true,
            'host_directory.entra.tenant_id' => 'tenant',
            'host_directory.entra.client_id' => 'client',
            'host_directory.entra.client_secret' => 'secret',
        ]);

        $this->central = Site::factory()->create(['name' => 'Central Office']);
        $this->campus = Site::factory()->create(['name' => 'Pilot Campus']);
        app(SettingsRepository::class)->saveSite($this->central, [EntraHostSync::SETTING => self::CENTRAL_GROUP], null);
        app(SettingsRepository::class)->saveSite($this->campus, [EntraHostSync::SETTING => self::CAMPUS_GROUP], null);

        $this->groups = [
            self::CENTRAL_GROUP => [$this->entraUser('a1', 'Ada', 'Lovelace', 'ada@school.test'), $this->entraUser('b2', 'Ben', 'Okafor', 'ben@school.test')],
            self::CAMPUS_GROUP => [$this->entraUser('b2', 'Ben', 'Okafor', 'ben@school.test'), $this->entraUser('c3', 'Cleo', 'Ng', 'cleo@school.test')],
        ];

        Http::fake(function (Request $request) {
            if ($this->rejectCredentials) {
                return Http::response(['error' => 'invalid_client'], 401);
            }

            if (str_contains($request->url(), '/oauth2/v2.0/token')) {
                return Http::response(['access_token' => 'graph-token', 'expires_in' => 3600]);
            }

            foreach ($this->groups as $groupId => $users) {
                if (str_contains($request->url(), "/groups/{$groupId}/transitiveMembers")) {
                    return Http::response(['value' => $users]);
                }
            }

            return Http::response(['error' => ['code' => 'Request_ResourceNotFound']], 404);
        });
    }

    public function test_group_members_become_hosts_at_their_sites(): void
    {
        $this->artisan('visitorportal:hosts:sync-entra')
            ->expectsOutputToContain('3 created, 0 linked')
            ->assertSuccessful();

        $ben = User::query()->where('email', 'ben@school.test')->firstOrFail();
        $this->assertSame(['Ben', 'Okafor'], [$ben->first_name, $ben->name]);
        $this->assertFalse((bool) $ben->local_login_allowed);
        $this->assertTrue($ben->hasRole('user'));
        $this->assertEqualsCanonicalizing([$this->central->id, $this->campus->id], $ben->assignedSiteIds()->all());

        $cleo = User::query()->where('email', 'cleo@school.test')->firstOrFail();
        $this->assertSame([$this->campus->id], $cleo->assignedSiteIds()->all());

        // Visitors at the campus can pick Cleo and Ben, not Ada.
        $names = collect(['Cle', 'Ben', 'Ada'])
            ->flatMap(fn (string $q) => app(LocalHostDirectory::class)->search($this->campus, $q))
            ->pluck('displayName');
        $this->assertSame(['Cleo Ng', 'Ben Okafor'], $names->all());

        $this->assertDatabaseHas('audit_events', ['action' => 'host_directory.synced']);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer graph-token') && str_contains($r->url(), 'transitiveMembers/microsoft.graph.user'));
    }

    public function test_an_existing_local_account_is_linked_not_duplicated_and_never_deactivated(): void
    {
        $admin = User::factory()->create(['email' => 'ada@school.test', 'first_name' => 'A', 'name' => 'Admin', 'site_id' => $this->campus->id, 'local_login_allowed' => true]);

        $this->artisan('visitorportal:hosts:sync-entra')->expectsOutputToContain('2 created, 1 linked')->assertSuccessful();

        $admin->refresh();
        $this->assertSame('Ada', $admin->first_name);
        $this->assertTrue((bool) $admin->local_login_allowed);
        $this->assertSame(1, User::query()->where('email', 'ada@school.test')->count());

        // Ada leaves the group: she keeps her account and her own primary site.
        $this->groups[self::CENTRAL_GROUP] = [$this->entraUser('b2', 'Ben', 'Okafor', 'ben@school.test')];
        $this->artisan('visitorportal:hosts:sync-entra')->assertSuccessful();

        $admin->refresh();
        $this->assertTrue((bool) $admin->is_active);
        $this->assertSame([$this->campus->id], $admin->assignedSiteIds()->all());
    }

    public function test_leavers_and_disabled_accounts_are_deactivated_and_come_back_when_re_added(): void
    {
        $this->artisan('visitorportal:hosts:sync-entra')->assertSuccessful();

        $this->groups[self::CAMPUS_GROUP] = [
            $this->entraUser('b2', 'Ben', 'Okafor', 'ben@school.test'),
            [...$this->entraUser('c3', 'Cleo', 'Ng', 'cleo@school.test'), 'accountEnabled' => false],
        ];
        $this->groups[self::CENTRAL_GROUP] = [$this->entraUser('a1', 'Ada', 'Lovelace', 'ada@school.test')];

        $this->artisan('visitorportal:hosts:sync-entra')->expectsOutputToContain('1 deactivated')->assertSuccessful();

        $cleo = User::query()->where('email', 'cleo@school.test')->firstOrFail();
        $this->assertFalse((bool) $cleo->is_active);
        $this->assertSame([], app(LocalHostDirectory::class)->search($this->campus, 'Cleo'));

        // Ben left Central only: still a host at the campus, now his primary site.
        $ben = User::query()->where('email', 'ben@school.test')->firstOrFail();
        $this->assertSame([$this->campus->id], $ben->assignedSiteIds()->all());

        $this->groups[self::CAMPUS_GROUP][1]['accountEnabled'] = true;
        $this->artisan('visitorportal:hosts:sync-entra')->assertSuccessful();
        $this->assertTrue((bool) $cleo->refresh()->is_active);
    }

    public function test_removing_a_sites_group_mapping_removes_access_to_that_site(): void
    {
        $this->artisan('visitorportal:hosts:sync-entra')->assertSuccessful();
        $ben = User::query()->where('email', 'ben@school.test')->firstOrFail();
        $this->assertEqualsCanonicalizing([$this->central->id, $this->campus->id], $ben->assignedSiteIds()->all());

        // The campus is no longer synced from Entra (its group setting is cleared).
        app(SettingsRepository::class)->saveSite($this->campus, [EntraHostSync::SETTING => null], null);
        $this->artisan('visitorportal:hosts:sync-entra')->assertSuccessful();

        $this->assertSame([$this->central->id], $ben->refresh()->assignedSiteIds()->all());
        $this->assertSame([], app(LocalHostDirectory::class)->search($this->campus, 'Ben'));
        // Cleo was only at the campus: no longer in any synced group.
        $this->assertFalse((bool) User::query()->where('email', 'cleo@school.test')->value('is_active'));
    }

    public function test_a_graph_failure_or_an_empty_group_changes_nothing(): void
    {
        $this->artisan('visitorportal:hosts:sync-entra')->assertSuccessful();
        $before = User::query()->where('is_active', true)->count();

        $this->groups[self::CAMPUS_GROUP] = [];
        $this->artisan('visitorportal:hosts:sync-entra')->expectsOutputToContain('returned no users')->assertFailed();

        unset($this->groups[self::CAMPUS_GROUP]);
        $this->artisan('visitorportal:hosts:sync-entra')->expectsOutputToContain('was not found')->assertFailed();

        $this->assertSame($before, User::query()->where('is_active', true)->count());
        $this->assertSame(3, DirectoryAccount::query()->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'host_directory.sync_failed', 'result' => AuditEvent::RESULT_FAILURE]);
    }

    public function test_bad_credentials_are_reported_without_the_secret(): void
    {
        $this->rejectCredentials = true;

        try {
            app(EntraHostSync::class)->run();
            $this->fail('Expected the sync to fail.');
        } catch (EntraSyncException $e) {
            $this->assertStringContainsString('invalid_client', $e->getMessage());
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }

        $this->assertSame(0, DirectoryAccount::query()->count());
    }

    public function test_the_sync_is_off_by_default_and_skips_sites_without_groups(): void
    {
        config(['host_directory.entra.enabled' => false]);
        $this->artisan('visitorportal:hosts:sync-entra')->expectsOutputToContain('is off')->assertSuccessful();
        Http::assertNothingSent();

        config(['host_directory.entra.enabled' => true]);
        app(SettingsRepository::class)->saveSite($this->central, [EntraHostSync::SETTING => null], null);
        app(SettingsRepository::class)->saveSite($this->campus, [EntraHostSync::SETTING => null], null);
        $this->artisan('visitorportal:hosts:sync-entra')->expectsOutputToContain('No site has Entra host groups')->assertSuccessful();
    }

    public function test_the_setting_only_accepts_group_object_ids(): void
    {
        $rules = SettingsRegistry::get(EntraHostSync::SETTING)->rules;
        $valid = fn (string $value): bool => Validator::make(['v' => $value], ['v' => $rules])->passes();

        $this->assertTrue($valid(self::CENTRAL_GROUP));
        $this->assertTrue($valid(self::CENTRAL_GROUP.', '.self::CAMPUS_GROUP));
        $this->assertFalse($valid('Staff group'));
        $this->assertFalse($valid(self::CENTRAL_GROUP.';'.self::CAMPUS_GROUP));
    }

    /**
     * @return array<string, mixed>
     */
    private function entraUser(string $id, string $first, string $last, string $mail): array
    {
        return [
            'id' => $id.'000000-0000-0000-0000-000000000000',
            'givenName' => $first,
            'surname' => $last,
            'displayName' => "{$first} {$last}",
            'mail' => $mail,
            'userPrincipalName' => $mail,
            'accountEnabled' => true,
        ];
    }
}
