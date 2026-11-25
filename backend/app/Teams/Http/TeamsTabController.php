<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Teams\Http;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The Teams app's personal "Visits" tab. Teams shows it in an iframe, where the portal's own
 * sign-in does not work reliably, so it only offers a button that opens the portal (the visit
 * from the alert, if any) in the browser. Anonymous and data-free: the portal checks access.
 */
class TeamsTabController
{
    public const FRAME_ANCESTORS = "'self' https://teams.microsoft.com https://*.teams.microsoft.com https://*.cloud.microsoft https://*.office.com https://*.microsoft365.com";

    public function __invoke(Request $request): View
    {
        $request->attributes->set(SecurityHeaders::FRAME_ANCESTORS, self::FRAME_ANCESTORS);

        $visit = $request->query('visit');
        $url = is_string($visit) && preg_match('/^[1-9][0-9]{0,17}$/', $visit) === 1
            ? route('portal.visits.show', ['visit' => (int) $visit])
            : route('overview');

        return view('teams.tab', [
            'url' => $url,
            'logo' => config('branding.logo_light'),
        ]);
    }
}
