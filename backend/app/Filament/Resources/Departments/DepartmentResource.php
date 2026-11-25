<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\Departments;

use App\Filament\Resources\Departments\Pages\CreateDepartment;
use App\Filament\Resources\Departments\Pages\EditDepartment;
use App\Filament\Resources\Departments\Pages\ListDepartments;
use App\Filament\Resources\Departments\Pages\ViewDepartment;
use App\Filament\Resources\Departments\Schemas\DepartmentForm;
use App\Filament\Resources\Departments\Schemas\DepartmentInfolist;
use App\Filament\Resources\Departments\Tables\DepartmentsTable;
use App\Models\Department;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class DepartmentResource extends Resource
{
    protected static ?string $model = Department::class;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-building-office';

    /**
     * Fork: departments are hidden unless DEPARTMENTS_ENABLED (config visitorportal.departments).
     */
    public static function canAccess(): bool
    {
        return (bool) config('visitorportal.departments.enabled') && parent::canAccess();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return (bool) config('visitorportal.departments.enabled') && parent::shouldRegisterNavigation();
    }

    public static function getNavigationLabel(): string
    {
        return __('Abteilungen');
    }

    public static function getModelLabel(): string
    {
        return __('Abteilung');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Abteilungen');
    }

    public static function form(Schema $schema): Schema
    {
        return DepartmentForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DepartmentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DepartmentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDepartments::route('/'),
            'create' => CreateDepartment::route('/create'),
            'view' => ViewDepartment::route('/{record}'),
            'edit' => EditDepartment::route('/{record}/edit'),
        ];
    }
}
