<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Credentials;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of credential visitors may need, e.g. "Working with Children Check (WA)". Configured
 * by each organisation; questions are linked to it so sign-in answers fill the record.
 *
 * @property int $id
 * @property string $name
 * @property ?string $verification_url
 * @property ?int $recheck_days
 * @property int $expiry_warning_days
 * @property bool $is_active
 */
class CredentialType extends Model
{
    protected $fillable = ['name', 'description', 'verification_url', 'recheck_days', 'expiry_warning_days', 'is_active'];

    protected $attributes = ['expiry_warning_days' => 30, 'is_active' => true];

    protected function casts(): array
    {
        return ['recheck_days' => 'integer', 'expiry_warning_days' => 'integer', 'is_active' => 'boolean'];
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(VisitorCredential::class);
    }
}
