<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\VisitLog\Filament;

use App\Enums\VisitStatusEnum;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\User;
use App\Models\Visit;
use App\VisitLog\VisitCancellation;
use App\VisitLog\VisitNotes;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Admin panel actions for the immutable visitor log: append a note, and cancel instead of
 * delete. Each action checks VisitPolicy on the server for every record.
 */
final class VisitLogActions
{
    public static function addNote(): Action
    {
        return Action::make('addNote')
            ->label(__('Notiz hinzufügen'))
            ->icon('heroicon-o-chat-bubble-bottom-center-text')
            ->modalHeading(__('Notiz hinzufügen'))
            ->modalDescription(__('Die Notiz wird mit Datum und Ihrem Namen angehängt. Bestehende Notizen können nicht geändert werden.'))
            ->schema([
                Textarea::make('note')
                    ->label(__('Notiz'))
                    ->required()
                    ->maxLength(VisitNotes::MAX_LENGTH)
                    ->rows(4),
            ])
            ->authorize('addNote')
            ->action(function (Visit $record, array $data): void {
                $user = auth()->user();
                abort_unless($user instanceof User, 403);

                app(VisitNotes::class)->append($record, (string) $data['note'], $user);

                Notification::make()->success()->title(__('Die Notiz wurde hinzugefügt.'))->send();
            });
    }

    public static function cancel(): Action
    {
        return Action::make('cancel')
            ->label(__('Cancel Visit'))
            ->color('danger')
            ->icon('heroicon-o-x-circle')
            ->requiresConfirmation()
            ->modalHeading(__('Cancel Visit'))
            ->modalDescription(__('Are you sure you want to cancel this visit?'))
            ->modalSubmitActionLabel(__('Yes, cancel it'))
            ->visible(fn (Visit $record): bool => app(VisitCancellation::class)->canBeCancelled($record))
            ->authorize('cancel')
            ->action(function (Visit $record, Action $action): void {
                $user = auth()->user();
                abort_unless($user instanceof User, 403);

                app(VisitCancellation::class)->cancel($record, $user);
                $action->redirect(VisitResource::getUrl('index'));
            });
    }

    public static function restore(): Action
    {
        return Action::make('Restore')
            ->label(__('Restore Visit'))
            ->color('danger')
            ->icon('heroicon-o-x-circle')
            ->requiresConfirmation()
            ->modalHeading(__('Restore Visit'))
            ->modalDescription(__('Are you sure you want to restore this visit?'))
            ->modalSubmitActionLabel(__('Yes, restore it'))
            ->visible(fn (Visit $record): bool => $record->status === VisitStatusEnum::Canceled->value)
            ->authorize('update')
            ->action(function (Visit $record, Action $action): void {
                $user = auth()->user();
                abort_unless($user instanceof User, 403);

                app(VisitCancellation::class)->restore($record, $user);
                $action->redirect(VisitResource::getUrl('index'));
            });
    }

    /**
     * Replaces "delete selected": cancels the selected visits that may be cancelled and skips
     * the rest (attended, finished, already cancelled, or not permitted).
     */
    public static function cancelSelected(): BulkAction
    {
        return BulkAction::make('cancelSelected')
            ->label(__('Ausgewählte Besuche absagen'))
            ->color('danger')
            ->icon('heroicon-o-x-circle')
            ->requiresConfirmation()
            ->modalDescription(__('Besuche mit Anwesenheit, abgeschlossene und bereits abgesagte Besuche werden übersprungen.'))
            ->action(function (Collection $records): void {
                $user = auth()->user();
                abort_unless($user instanceof User, 403);

                $cancellation = app(VisitCancellation::class);
                $cancelled = 0;

                foreach ($records as $visit) {
                    if ($visit instanceof Visit && $cancellation->canBeCancelled($visit) && Gate::forUser($user)->allows('cancel', $visit)) {
                        $cancellation->cancel($visit, $user);
                        $cancelled++;
                    }
                }

                Notification::make()
                    ->title(__('Abgesagt: :cancelled, übersprungen: :skipped', [
                        'cancelled' => $cancelled,
                        'skipped' => $records->count() - $cancelled,
                    ]))
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
