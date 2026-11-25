<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Credentials;

use App\Models\User;
use App\Support\ForkPermissions;

/**
 * Credential types are part of the visitor workflow set-up. Registered explicitly: without a
 * policy Filament would allow every action.
 */
class CredentialTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(ForkPermissions::MANAGE_VISITOR_WORKFLOW);
    }

    public function view(User $user, CredentialType $type): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, CredentialType $type): bool
    {
        return $this->viewAny($user);
    }

    /** Switch a type off instead: records and audit history refer to it. */
    public function delete(User $user, CredentialType $type): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
