<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\HostDirectory\Entra;

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Models\Site;
use App\Models\User;
use App\Settings\SettingsRepository;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Keeps local host accounts in line with each site's Microsoft Entra group(s):
 *
 * - every enabled group member gets a local account (created, or an existing account with the
 *   same email is linked) and is assigned to the sites whose groups they are in;
 * - accounts the sync created are deactivated when they leave every group;
 * - accounts it only linked (e.g. a local admin) are never deactivated, they just lose the
 *   synced sites.
 *
 * All groups are read before anything is written; any Graph error, or a group that comes back
 * empty, stops the run with nothing changed, so a directory outage never empties reception's
 * host list. Created accounts cannot sign in with a password: staff use Entra SSO.
 */
class EntraHostSync
{
    public const SETTING = 'organisation.entra_host_group_ids';

    /** @var (Closure(): EntraGraphClient)|null */
    private ?Closure $clientFactory = null;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * For tests: use this Graph client instead of the configured one.
     */
    public function usingClient(EntraGraphClient $client): self
    {
        $this->clientFactory = fn (): EntraGraphClient => $client;

        return $this;
    }

    /**
     * Site ID => Entra group IDs, for active sites that have groups configured.
     *
     * @return array<int, list<string>>
     */
    public function groupsBySite(): array
    {
        $groups = [];

        foreach (Site::query()->where('is_active', true)->orderBy('id')->get() as $site) {
            $value = (string) $this->settings->forSite($site)->get(self::SETTING);
            $ids = array_values(array_unique(array_filter(array_map(
                fn (string $id): string => Str::lower(trim($id)),
                explode(',', $value),
            ))));

            if ($ids !== []) {
                $groups[(int) $site->getKey()] = $ids;
            }
        }

        return $groups;
    }

    public function run(): EntraSyncResult
    {
        $groupsBySite = $this->groupsBySite();

        if ($groupsBySite === []) {
            return new EntraSyncResult(configured: false);
        }

        try {
            $members = $this->readMembers($groupsBySite);
        } catch (EntraSyncException $e) {
            $this->audit->record('host_directory.sync_failed', result: AuditEvent::RESULT_FAILURE, context: ['provider' => DirectoryAccount::PROVIDER_ENTRA, 'error' => $e->getMessage()]);

            throw $e;
        }

        $result = DB::transaction(fn (): EntraSyncResult => $this->apply($members, array_keys($groupsBySite)));

        $this->audit->record('host_directory.synced', context: ['provider' => DirectoryAccount::PROVIDER_ENTRA, ...$result->counts()]);

        return $result;
    }

    /**
     * Entra object ID => profile and the site IDs the person is a host at.
     *
     * @param  array<int, list<string>>  $groupsBySite
     * @return array<string, array{email: string, first_name: string, last_name: string, sites: list<int>}>
     */
    private function readMembers(array $groupsBySite): array
    {
        $client = $this->clientFactory !== null ? ($this->clientFactory)() : EntraGraphClient::fromConfig();
        $usersByGroup = [];

        foreach (array_unique(array_merge(...array_values($groupsBySite))) as $groupId) {
            $usersByGroup[$groupId] = $client->groupUsers($groupId);

            if ($usersByGroup[$groupId] === []) {
                throw new EntraSyncException("Entra group {$groupId} returned no users; nothing was changed. Check the group ID and the app's permissions.");
            }
        }

        $members = [];

        foreach ($groupsBySite as $siteId => $groupIds) {
            foreach ($groupIds as $groupId) {
                foreach ($usersByGroup[$groupId] as $user) {
                    $profile = $this->profile($user);

                    if ($profile === null) {
                        continue;
                    }

                    $id = Str::lower((string) $user['id']);
                    $members[$id] ??= [...$profile, 'sites' => []];
                    $members[$id]['sites'][] = (int) $siteId;
                }
            }
        }

        foreach ($members as &$member) {
            $member['sites'] = array_values(array_unique($member['sites']));
        }

        return $members;
    }

    /**
     * @param  array<string, mixed>  $user
     * @return array{email: string, first_name: string, last_name: string}|null null for disabled accounts or ones without a usable email
     */
    private function profile(array $user): ?array
    {
        if (($user['accountEnabled'] ?? true) === false) {
            return null;
        }

        $email = is_string($user['mail'] ?? null) && $user['mail'] !== '' ? $user['mail'] : ($user['userPrincipalName'] ?? null);

        // Guest accounts' UPNs (…#EXT#@tenant) are not real mailboxes.
        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL) || str_contains($email, '#EXT#')) {
            return null;
        }

        $display = trim((string) ($user['displayName'] ?? ''));
        $first = trim((string) ($user['givenName'] ?? '')) ?: (Str::contains($display, ' ') ? Str::beforeLast($display, ' ') : $display);
        $last = trim((string) ($user['surname'] ?? '')) ?: (Str::contains($display, ' ') ? Str::afterLast($display, ' ') : '');

        return [
            'email' => Str::lower($email),
            'first_name' => Str::limit($first !== '' ? $first : Str::before($email, '@'), 250, ''),
            'last_name' => Str::limit($last, 250, ''),
        ];
    }

    /**
     * @param  array<string, array{email: string, first_name: string, last_name: string, sites: list<int>}>  $members
     * @param  list<int>  $syncedSiteIds
     */
    private function apply(array $members, array $syncedSiteIds): EntraSyncResult
    {
        $result = new EntraSyncResult(configured: true);
        $accounts = DirectoryAccount::query()->where('provider', DirectoryAccount::PROVIDER_ENTRA)->with('user')->get()->keyBy('external_id');
        $role = (string) config('host_directory.entra.role', 'user');
        $roleExists = $role !== '' && Role::query()->where('name', $role)->where('guard_name', 'web')->exists();

        foreach ($members as $externalId => $member) {
            $account = $accounts->get($externalId);
            $user = $account?->user;

            if ($user === null) {
                $user = User::query()->where('email', $member['email'])->first();

                if ($user !== null) {
                    $account = $this->link($user, $externalId, createdUser: false);
                    $this->updateUser($user, $member, createdUser: false);
                    $result->linked++;
                } else {
                    $user = $this->createUser($member);
                    $account = $this->link($user, $externalId, createdUser: true);

                    if ($roleExists) {
                        $user->assignRole($role);
                    }

                    $result->created++;
                }
            } elseif ($this->updateUser($user, $member, $account->created_user)) {
                $result->updated++;
            }

            $this->assignSites($user, $member['sites'], $syncedSiteIds, $account);
            $account->forceFill(['synced_site_ids' => array_values($member['sites']), 'last_synced_at' => now()])->save();
        }

        // Accounts no longer in any group (or disabled in Entra).
        foreach ($accounts as $externalId => $account) {
            if (isset($members[$externalId]) || $account->user === null) {
                continue;
            }

            $user = $account->user;
            $user->sites()->detach(array_values(array_diff($this->sitesToReview($account, $syncedSiteIds), [(int) $user->site_id])));
            $account->forceFill(['synced_site_ids' => []])->save();

            if ($account->created_user && $user->is_active) {
                $user->forceFill(['is_active' => false])->save();
                $result->deactivated++;
            }
        }

        return $result;
    }

    /**
     * @param  array{email: string, first_name: string, last_name: string, sites: list<int>}  $member
     */
    private function createUser(array $member): User
    {
        $user = new User;
        $user->forceFill([
            'site_id' => $member['sites'][0],
            'first_name' => $member['first_name'],
            'name' => $member['last_name'],
            'email' => $member['email'],
            'email_verified_at' => now(),
            'password' => Hash::make(Str::random(64)),
            'is_active' => true,
            'local_login_allowed' => false,
        ])->save();

        return $user;
    }

    /**
     * @param  array{email: string, first_name: string, last_name: string, sites: list<int>}  $member
     * @return bool whether anything changed
     */
    private function updateUser(User $user, array $member, bool $createdUser): bool
    {
        $changes = ['first_name' => $member['first_name'], 'name' => $member['last_name']];

        if ($createdUser) {
            // Back in a group: active again. A changed address only if no other account has it.
            $changes['is_active'] = true;

            if ($member['email'] !== $user->email && ! User::query()->where('email', $member['email'])->exists()) {
                $changes['email'] = $member['email'];
            }

            // Primary site must be one of the person's sites.
            if (! in_array((int) $user->site_id, $member['sites'], true)) {
                $changes['site_id'] = $member['sites'][0];
                $changes['department_id'] = null;
            }
        }

        $user->forceFill($changes);

        if (! $user->isDirty()) {
            return false;
        }

        $user->save();

        return true;
    }

    /**
     * @param  list<int>  $memberSiteIds
     * @param  list<int>  $syncedSiteIds
     */
    private function assignSites(User $user, array $memberSiteIds, array $syncedSiteIds, DirectoryAccount $account): void
    {
        $user->sites()->syncWithoutDetaching($memberSiteIds);

        // Sites the sync manages for this person (still mapped, or given by an earlier sync,
        // e.g. a site whose group mapping was since removed) that they are no longer in. A linked
        // account keeps its own primary site.
        $remove = array_diff($this->sitesToReview($account, $syncedSiteIds), $memberSiteIds, $account->created_user ? [] : [(int) $user->site_id]);

        if ($remove !== []) {
            $user->sites()->detach(array_values($remove));
        }
    }

    /**
     * Fork: the sites currently mapped to groups plus the sites an earlier sync gave this account
     * (recorded from the first sync after this change; sites added by hand are never included).
     *
     * @param  list<int>  $syncedSiteIds
     * @return list<int>
     */
    private function sitesToReview(DirectoryAccount $account, array $syncedSiteIds): array
    {
        return array_values(array_unique(array_map('intval', [...$syncedSiteIds, ...($account->synced_site_ids ?? [])])));
    }

    private function link(User $user, string $externalId, bool $createdUser): DirectoryAccount
    {
        return DirectoryAccount::query()->create([
            'user_id' => $user->getKey(),
            'provider' => DirectoryAccount::PROVIDER_ENTRA,
            'external_id' => $externalId,
            'created_user' => $createdUser,
        ]);
    }
}
