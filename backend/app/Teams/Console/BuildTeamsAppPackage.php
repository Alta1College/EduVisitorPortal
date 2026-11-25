<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Teams\Console;

use App\Teams\Package\TeamsAppIcons;
use App\Teams\TeamsAppManifest;
use Illuminate\Console\Command;
use InvalidArgumentException;
use ZipArchive;

/**
 * Builds the Teams app package an administrator uploads in the Teams admin centre
 * (docs/fork/teams-notifications.md).
 */
class BuildTeamsAppPackage extends Command
{
    protected $signature = 'visitorportal:teams:package
        {--output= : Where to write the zip (default storage/app/teams/teams-app.zip)}
        {--app-version=1.0.0 : Package version; raise it for each new upload}';

    protected $description = 'Build the Microsoft Teams app package (manifest and icons) for host notifications';

    public function handle(): int
    {
        try {
            $manifest = TeamsAppManifest::build((string) $this->option('app-version'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $path = (string) ($this->option('output') ?: storage_path('app/teams/teams-app.zip'));
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            $this->error("Cannot create {$directory}.");

            return self::FAILURE;
        }

        $name = (string) config('branding.name');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error("Cannot write {$path}.");

            return self::FAILURE;
        }

        $zip->addFromString('manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $zip->addFromString('color.png', TeamsAppIcons::color($name, TeamsAppIcons::primaryColour()));
        $zip->addFromString('outline.png', TeamsAppIcons::outline($name));
        $zip->close();

        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $this->warn('APP_URL is not https: the package uses https://'.parse_url((string) config('app.url'), PHP_URL_HOST).', which Teams requires.');
        }

        $this->info('Teams app package written to '.$path);
        $this->line('Upload it in the Teams admin centre (Teams apps > Manage apps > Upload new app).');

        return self::SUCCESS;
    }
}
