<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

return [
    // Fork (Phase 11): Livewire temporary uploads per minute per IP (the public pages use them).
    'temporary_uploads_per_minute' => (int) env('UPLOAD_TEMPORARY_PER_MINUTE', 15),

    'images' => [
        'max_size_kb' => (int) env('UPLOAD_IMAGE_MAX_SIZE_KB', 20480),
        'max_width' => (int) env('UPLOAD_IMAGE_MAX_WIDTH', 20000),
        'max_height' => (int) env('UPLOAD_IMAGE_MAX_HEIGHT', 20000),
        'max_pixels' => (int) env('UPLOAD_IMAGE_MAX_PIXELS', 150000000),
        'allowed_mime_types' => [
            'image/jpeg',
            'image/png',
            'image/webp',
        ],
    ],
];
