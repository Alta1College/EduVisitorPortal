<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Widgets;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\ManageOrganisationSettings;
use App\Filament\Pages\ManageSiteSettings;
use App\Filament\Resources\BadgePrinters\BadgePrinterResource;
use App\Filament\Resources\FormFields\FormFieldResource;
use App\Filament\Resources\Kiosks\KioskResource;
use App\Filament\Resources\Policies\PolicyResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\SiteQrLinks\SiteQrLinkResource;
use App\Filament\Resources\Sites\SiteResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\VisitorTypes\VisitorTypeResource;
use App\Models\User;
use Closure;
use Filament\Widgets\Widget;

/**
 * Getting-started panel at the top of the admin dashboard. Each user can hide it ("Don't show
 * again", users.dashboard_welcome_dismissed_at) and bring it back from the dashboard header.
 */
class DashboardWelcomeWidget extends Widget
{
    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.dashboard-welcome';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->getAttribute('dashboard_welcome_dismissed_at') === null;
    }

    public function dismiss(): void
    {
        $user = auth()->user();

        if ($user instanceof User) {
            $user->forceFill(['dashboard_welcome_dismissed_at' => now()])->save();
        }

        $this->redirect(Dashboard::getUrl());
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $link = fn (string $label, bool $allowed, Closure $url): ?array => $allowed ? ['label' => $label, 'url' => $url()] : null;

        $steps = [
            [
                'title' => __('Standorte und Erscheinungsbild'),
                'text' => __('Standorte mit Zeitzone anlegen, dann Name, Logos und Farben der Organisation festlegen.'),
                'links' => [
                    $link(__('Standorte'), SiteResource::canViewAny(), fn () => SiteResource::getUrl()),
                    $link(__('Organisationseinstellungen'), ManageOrganisationSettings::canAccess(), fn () => ManageOrganisationSettings::getUrl()),
                ],
            ],
            [
                'title' => __('Besuchertypen und Fragen'),
                'text' => __('Festlegen, wer kommt (z. B. Auftragnehmer, Eltern), welche Fragen gestellt und welche Richtlinien bestätigt werden.'),
                'links' => [
                    $link(__('Besuchertypen'), VisitorTypeResource::canViewAny(), fn () => VisitorTypeResource::getUrl()),
                    $link(__('Fragen'), FormFieldResource::canViewAny(), fn () => FormFieldResource::getUrl()),
                    $link(__('Richtlinien'), PolicyResource::canViewAny(), fn () => PolicyResource::getUrl()),
                ],
            ],
            [
                'title' => __('Kioske und Ausweisdrucker'),
                'text' => __('Tablets am Empfang als Kiosk einrichten, QR-Anmeldung freigeben und Etikettendrucker zuordnen.'),
                'links' => [
                    $link(__('Kioske'), KioskResource::canViewAny(), fn () => KioskResource::getUrl()),
                    $link(__('QR-Anmeldung'), SiteQrLinkResource::canViewAny(), fn () => SiteQrLinkResource::getUrl()),
                    $link(__('Ausweisdrucker'), BadgePrinterResource::canViewAny(), fn () => BadgePrinterResource::getUrl()),
                ],
            ],
            [
                'title' => __('Gastgeber und Mitarbeiterrollen'),
                'text' => __('Gastgeber aus Microsoft-Entra-Gruppen je Standort synchronisieren und Empfangsmitarbeitern ihre Rolle geben.'),
                'links' => [
                    $link(__('Benutzer'), UserResource::canViewAny(), fn () => UserResource::getUrl()),
                    $link(__('Rollen'), RoleResource::canViewAny(), fn () => RoleResource::getUrl()),
                    $link(__('Standorteinstellungen'), ManageSiteSettings::canAccess(), fn () => ManageSiteSettings::getUrl()),
                ],
            ],
            [
                'title' => __('Benachrichtigungen, Teams und Notfälle'),
                'text' => __('Wie Gastgeber von Ankünften erfahren (E-Mail, Teams), Notfallnachrichten an Besucher und die Warnung bei fehlenden Übungen.'),
                'links' => [
                    $link(__('Benachrichtigungen'), ManageOrganisationSettings::canAccess(), fn () => ManageOrganisationSettings::getUrl()),
                ],
            ],
        ];

        foreach ($steps as $index => $step) {
            $steps[$index]['links'] = array_values(array_filter($step['links']));
        }

        return [
            'brandName' => (string) config('branding.name'),
            'steps' => $steps,
        ];
    }
}
