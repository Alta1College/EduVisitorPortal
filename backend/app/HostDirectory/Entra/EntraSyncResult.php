<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\HostDirectory\Entra;

final class EntraSyncResult
{
    public function __construct(
        public readonly bool $configured,
        public int $created = 0,
        public int $linked = 0,
        public int $updated = 0,
        public int $deactivated = 0,
    ) {}

    /**
     * @return array{created: int, linked: int, updated: int, deactivated: int}
     */
    public function counts(): array
    {
        return ['created' => $this->created, 'linked' => $this->linked, 'updated' => $this->updated, 'deactivated' => $this->deactivated];
    }
}
