<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Fork: admin panel avatars drawn locally as an inline SVG with the user's initials.
 * Filament's default loads them from ui-avatars.com, which sends staff names to a third party
 * and is blocked by our Content-Security-Policy (img-src 'self' data:).
 */
class LocalInitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $initials = collect(preg_split('/\s+/u', trim((string) Filament::getNameForDefaultAvatar($record))) ?: [])
            ->map(fn (string $part): string => (string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $part))
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->join('');

        $text = htmlspecialchars($initials !== '' ? $initials : '?', ENT_QUOTES | ENT_XML1, 'UTF-8');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" rx="32" fill="#4b5563"/>'
            .'<text x="50%" y="50%" dy=".35em" text-anchor="middle" fill="#ffffff" '
            .'font-family="Arial, Helvetica, sans-serif" font-size="26" font-weight="600">'.$text.'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
