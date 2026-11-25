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
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditVisit extends EditRecord
{
    protected static string $resource = VisitResource::class;

    /**
     * Fork (immutable visitor log): no delete. Cancel and restore check VisitPolicy and are
     * audited; a visit with attendance cannot reach this page (VisitPolicy::update).
     */
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            VisitLogActions::addNote(),
            VisitLogActions::cancel(),
            VisitLogActions::restore(),
        ];
    }
}
