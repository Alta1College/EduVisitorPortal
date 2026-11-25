<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\CredentialTypes;

use App\Credentials\CredentialType;
use App\Filament\Resources\CredentialTypes\Pages\CreateCredentialType;
use App\Filament\Resources\CredentialTypes\Pages\EditCredentialType;
use App\Filament\Resources\CredentialTypes\Pages\ListCredentialTypes;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Credentials visitors may need, e.g. a Working with Children Check. Link a question to a type
 * (Form fields → "Credential") so sign-in answers fill each person's record.
 */
class CredentialTypeResource extends Resource
{
    protected static ?string $model = CredentialType::class;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-identification';

    protected static ?int $navigationSort = 35;

    public static function getNavigationGroup(): ?string
    {
        return __('Besucherablauf');
    }

    public static function getNavigationLabel(): string
    {
        return __('Nachweise');
    }

    public static function getModelLabel(): string
    {
        return __('Nachweis');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Nachweise');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(120)
                    ->helperText(__('Z. B. „Working with Children Check (WA)“.')),
                TextInput::make('verification_url')
                    ->label(__('Prüfseite der ausstellenden Stelle'))
                    ->url()
                    ->maxLength(500)
                    ->helperText(__('Öffnet sich beim Prüfen am Empfang. Nummer und Nachname werden zum Kopieren angezeigt.')),
                Textarea::make('description')->label(__('Beschreibung'))->maxLength(500)->columnSpanFull(),
                TextInput::make('recheck_days')
                    ->label(__('Erneut prüfen nach (Tagen)'))
                    ->integer()->minValue(1)->maxValue(3650)
                    ->helperText(__('Leer lassen, um nur bei Ablauf erneut zu prüfen.')),
                TextInput::make('expiry_warning_days')
                    ->label(__('Vor Ablauf warnen (Tage)'))
                    ->integer()->minValue(0)->maxValue(365)->default(30)->required(),
                Toggle::make('is_active')->label(__('Aktiv'))->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable(),
                TextColumn::make('credentials_count')->counts('credentials')->label(__('Einträge')),
                TextColumn::make('recheck_days')->label(__('Erneut prüfen nach (Tagen)'))->placeholder('—'),
                IconColumn::make('is_active')->label(__('Aktiv'))->boolean(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([CreateAction::make()])
            ->emptyStateActions([CreateAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCredentialTypes::route('/'),
            'create' => CreateCredentialType::route('/create'),
            'edit' => EditCredentialType::route('/{record}/edit'),
        ];
    }
}
