<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Providers\Filament;

use App\Filament\LocalInitialsAvatarProvider;
use App\Filament\Pages\Dashboard;
use App\Http\Middleware\ApplyUserPreferences;
use App\Http\Middleware\EnsureAdminPanelMfa;
use App\Http\Middleware\LogOutInactiveUsers;
use App\Http\Middleware\SecurityHeaders;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Enums\GlobalSearchPosition;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $brandName = (string) config('branding.name', 'VisitorPortal');
        $logoLightPath = config('branding.logo_light');
        $logoDarkPath = config('branding.logo_dark');
        $hasLogoLight = is_string($logoLightPath) && $logoLightPath !== '' && file_exists(public_path($logoLightPath));
        $hasLogoDark = is_string($logoDarkPath) && $logoDarkPath !== '' && file_exists(public_path($logoDarkPath));
        $brandLogo = $hasLogoLight || $hasLogoDark
            ? new HtmlString(collect([
                $hasLogoLight ? '<img src="'.e(asset($logoLightPath)).'" class="fi-logo logo-light h-10 w-auto" alt="'.e($brandName).'">' : null,
                $hasLogoDark ? '<img src="'.e(asset($logoDarkPath)).'" class="fi-logo logo-dark h-10 w-auto" alt="'.e($brandName).'">' : null,
            ])->filter()->implode(''))
            : $brandName;

        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // Fork: saving an edit returns to the list (create pages keep their own redirects).
            ->resourceEditPageRedirect('index')
            ->globalSearch(position: GlobalSearchPosition::Sidebar)
            ->brandName($brandName)
            ->brandLogo($brandLogo)
            ->brandLogoHeight('4rem')
            ->colors([
                // 'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            // Fork: the start page lists its own tiles (App\Filament\Pages\Dashboard::getWidgets(),
            // docs/fork/admin-dashboard.md); no panel-wide widgets.
            // Fork: initials drawn locally, not fetched from ui-avatars.com (privacy, CSP).
            ->defaultAvatarProvider(LocalInitialsAvatarProvider::class)
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                ApplyUserPreferences::class,
                // Fork (Phase 11): same headers as the rest of the app; deactivated users signed out.
                SecurityHeaders::class,
                LogOutInactiveUsers::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsureAdminPanelMfa::class,
            ])
            ->renderHook(
                PanelsRenderHook::HEAD_START,
                fn (): string => Blade::render('<x-filament.admin-theme-head />')
            )
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => Blade::render(<<<'BLADE'
                    @if (config('visitorportal.easter_egg.enabled') && (! app()->environment('production') || config('visitorportal.easter_egg.show_in_production')))
                        @vite('resources/js/easter-egg.ts')
                    @endif
                    BLADE)
            )
            ->renderHook(
                PanelsRenderHook::SIDEBAR_NAV_END,
                fn (): string => Blade::render('filament.sidebar-footer')
            );
    }
}
