<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\Visits\RelationManagers;

use App\Filament\Resources\Visitors\VisitorResource;
use App\VisitLog\VisitLock;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class VisitorsRelationManager extends RelationManager
{
    protected static string $relationship = 'Visitors';

    protected static ?string $relatedResource = VisitorResource::class;

    /**
     * Fork: no adding or removing visitors once anyone has signed in to the visit.
     */
    public function isReadOnly(): bool
    {
        return parent::isReadOnly() || VisitLock::hasAttendance((int) $this->getOwnerRecord()->getKey());
    }

    public function table(Table $table): Table
    {
        return $table
            ->headerActions([
                CreateAction::make(),
            ]);
    }
}
