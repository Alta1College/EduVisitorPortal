<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\CredentialTypes\Pages;

use App\Filament\Resources\CredentialTypes\CredentialTypeResource;
use Filament\Resources\Pages\ListRecords;

class ListCredentialTypes extends ListRecords
{
    protected static string $resource = CredentialTypeResource::class;
}
