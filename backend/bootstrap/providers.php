<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use App\Audit\AuditServiceProvider;
use App\Badges\BadgesServiceProvider;
use App\Credentials\CredentialsServiceProvider;
use App\Emergency\EmergencyServiceProvider;
use App\Messaging\MessagingServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\FortifyServiceProvider;
use App\PublicVisit\PublicVisitServiceProvider;
use App\Reports\ReportsServiceProvider;
use App\Settings\SettingsServiceProvider;
use App\Teams\TeamsServiceProvider;
use App\VisitLog\VisitLogServiceProvider;
use App\VisitorWorkflow\VisitorWorkflowServiceProvider;

return [
    // Fork providers (see docs/fork/upstream-patches.md). Settings must precede the admin panel.
    AuditServiceProvider::class,
    SettingsServiceProvider::class,
    VisitorWorkflowServiceProvider::class,
    PublicVisitServiceProvider::class,
    MessagingServiceProvider::class,
    BadgesServiceProvider::class,
    EmergencyServiceProvider::class,
    CredentialsServiceProvider::class,
    TeamsServiceProvider::class,
    VisitLogServiceProvider::class,
    ReportsServiceProvider::class,
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    AdminPanelProvider::class,
];
