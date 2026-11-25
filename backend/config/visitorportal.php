<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

return [

    // Fork: departments (an upstream way of grouping staff for the "manager" role). Hidden by
    // default: sites and Entra host groups cover how schools are organised. The data and the
    // permission logic stay, so turning this on brings them back.
    'departments' => [
        'enabled' => (bool) env('DEPARTMENTS_ENABLED', false),
    ],

    'easter_egg' => [
        'enabled' => env('VISITORPORTAL_EASTER_EGG', env('APP_ENV', 'production') === 'local'),
        'show_in_production' => env('VISITORPORTAL_EASTER_EGG_PRODUCTION', false),
    ],

];
