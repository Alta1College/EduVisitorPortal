<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Fork: request attribute a route may set to widen CSP frame-ancestors for its own response
     * (e.g. the Teams tab, which Teams shows in an iframe). Default: 'self' only.
     */
    public const FRAME_ANCESTORS = 'security_headers.frame_ancestors';

    /** Fork: route names whose pages may use the camera (visitor photo capture). */
    public const CAMERA_ROUTES = ['kiosk', 'visit'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $scriptSrc = "'self' 'unsafe-inline' 'unsafe-eval'";
        $styleSrc = "'self' 'unsafe-inline'";
        $connectSrc = "'self'";

        if (app()->environment('local')) {
            $scriptSrc .= ' http://localhost:5173';
            $styleSrc .= ' http://localhost:5173';
            $connectSrc .= ' http://localhost:5173 ws://localhost:5173';
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // Fork: the visitor sign-in pages (kiosk and QR/mobile) take the visitor's photo with the
        // in-page camera, so they allow the camera for this site only. Everywhere else it stays
        // blocked. iPad home-screen web apps enforce camera=() strictly (no live preview).
        $camera = $request->routeIs(self::CAMERA_ROUTES) ? 'camera=(self)' : 'camera=()';
        $response->headers->set('Permissions-Policy', $camera.', microphone=(), geolocation=()');
        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            'frame-ancestors '.$request->attributes->get(self::FRAME_ANCESTORS, "'self'"),
            "form-action 'self'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "script-src {$scriptSrc}",
            "style-src {$styleSrc}",
            "connect-src {$connectSrc}",
        ]));

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
