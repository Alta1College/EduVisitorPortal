<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\HostDirectory\Entra;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Link between a local user and an external directory account (e.g. an Entra object ID).
 *
 * @property int $user_id
 * @property string $provider
 * @property string $external_id
 * @property bool $created_user
 */
class DirectoryAccount extends Model
{
    public const PROVIDER_ENTRA = 'entra';

    protected $fillable = ['user_id', 'provider', 'external_id', 'created_user', 'synced_site_ids', 'last_synced_at'];

    protected function casts(): array
    {
        return ['created_user' => 'boolean', 'synced_site_ids' => 'array', 'last_synced_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
