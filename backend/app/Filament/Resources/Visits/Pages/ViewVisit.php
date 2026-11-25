<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\Visits\Pages;

use App\Filament\Resources\Visits\VisitResource;
use App\VisitLog\Filament\VisitLogActions;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewVisit extends ViewRecord
{
    protected static string $resource = VisitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            // Fork: the one change left on a visit with attendance.
            VisitLogActions::addNote(),
        ];
    }
}
