<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Reports;

use Illuminate\Support\Facades\Log;
use Spatie\LaravelPdf\Facades\Pdf;
use Throwable;

/**
 * A4 PDF of a report view, rendered by Gotenberg (the service that already renders badges).
 * Returns null when Gotenberg is unavailable, so the report still goes out with its CSV.
 */
class ReportPdf
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function render(string $view, array $data): ?string
    {
        try {
            return Pdf::view($view, $data)
                ->driver('gotenberg')
                ->format('a4')
                ->margins(12, 12, 12, 12)
                ->generatePdfContent();
        } catch (Throwable $e) {
            Log::warning('Report PDF could not be created.', ['view' => $view, 'error' => $e::class]);

            return null;
        }
    }
}
