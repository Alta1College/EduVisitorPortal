<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Teams\Http;

use App\VisitorWorkflow\WorkflowRecorder;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * A visitor's sign-in photo for the Teams bot's "visitor arrived" card (TEAMS_BOT_INCLUDE_PHOTO).
 * Teams loads the image without the portal's sign-in, so the link is a temporary signed URL
 * (route middleware `signed`, expiry teams.bot.photo_link_hours) carrying the encrypted file
 * path; nothing else can be fetched. Same safeguards as the kiosk's returning-visitor photo:
 * evidence directory only, image MIME allowlist, nosniff, never cached.
 */
class TeamsVisitorPhotoController
{
    private const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __invoke(Request $request): Response
    {
        try {
            $path = Crypt::decryptString((string) $request->query('photo', ''));
        } catch (DecryptException) {
            abort(404);
        }

        abort_if(! str_starts_with($path, WorkflowRecorder::EVIDENCE_DIRECTORY.'/') || str_contains($path, '..'), 404);

        $disk = Storage::disk(WorkflowRecorder::EVIDENCE_DISK);
        abort_unless($disk->exists($path), 404);

        $mime = $disk->mimeType($path);
        abort_unless(in_array($mime, self::MIME_TYPES, true), 404);

        return $disk->response($path, null, [
            'Content-Type' => $mime,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
