<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\Visitors\Pages;

use App\Filament\Resources\Visitors\VisitorResource;
use App\Reports\Exports;
use Filament\Resources\Pages\ListRecords;

class ListVisitors extends ListRecords
{
    protected static string $resource = VisitorResource::class;

    /** Fork: CSV export for audits (docs/fork/admin-dashboard.md). */
    protected function getHeaderActions(): array
    {
        return [...parent::getHeaderActions(), Exports::visitorsAction()];
    }
}
