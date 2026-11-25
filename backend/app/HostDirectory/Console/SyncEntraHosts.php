<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\HostDirectory\Console;

use App\HostDirectory\Entra\EntraHostSync;
use App\HostDirectory\Entra\EntraSyncException;
use Illuminate\Console\Command;

class SyncEntraHosts extends Command
{
    protected $signature = 'visitorportal:hosts:sync-entra';

    protected $description = 'Sync host accounts from each site\'s Microsoft Entra group(s).';

    public function handle(EntraHostSync $sync): int
    {
        if (! config('host_directory.entra.enabled')) {
            $this->warn('Entra host sync is off (ENTRA_HOST_SYNC_ENABLED=false).');

            return self::SUCCESS;
        }

        try {
            $result = $sync->run();
        } catch (EntraSyncException $e) {
            $this->error($e->getMessage().' Hosts were left unchanged.');

            return self::FAILURE;
        }

        if (! $result->configured) {
            $this->warn('No site has Entra host groups set (setting "Entra host groups").');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Entra hosts synced: %d created, %d linked to existing accounts, %d updated, %d deactivated.',
            $result->created, $result->linked, $result->updated, $result->deactivated,
        ));

        return self::SUCCESS;
    }
}
