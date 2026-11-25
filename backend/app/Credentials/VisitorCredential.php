<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Credentials;

use App\Models\User;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person's credential of one type. The number is stored encrypted; number_hash allows an
 * exact search without decrypting every record.
 *
 * @property int $id
 * @property int $visitor_id
 * @property int $credential_type_id
 * @property string $number
 * @property ?Carbon $expires_on
 * @property string $status unchecked|valid|invalid (as last recorded)
 * @property ?Carbon $checked_at
 * @property ?int $checked_by_user_id
 * @property ?string $check_note
 * @property string $source sign_in|import|staff
 */
class VisitorCredential extends Model
{
    public const UNCHECKED = 'unchecked';

    public const VALID = 'valid';

    public const INVALID = 'invalid';

    /** Effective states: recorded status combined with the expiry date and re-check interval. */
    public const EXPIRED = 'expired';

    public const EXPIRING = 'expiring';

    public const RECHECK_DUE = 'recheck_due';

    public const SOURCE_SIGN_IN = 'sign_in';

    public const SOURCE_IMPORT = 'import';

    public const SOURCE_STAFF = 'staff';

    protected $fillable = ['visitor_id', 'credential_type_id', 'number', 'number_hash', 'expires_on', 'status', 'checked_at', 'checked_by_user_id', 'check_note', 'source'];

    protected $hidden = ['number', 'number_hash'];

    protected function casts(): array
    {
        return ['number' => 'encrypted', 'expires_on' => 'date', 'checked_at' => 'datetime'];
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(CredentialType::class, 'credential_type_id');
    }

    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by_user_id');
    }

    /**
     * What reception needs to know now: invalid, expired, unchecked, re-check due, expiring
     * soon, or valid.
     */
    public function state(?Carbon $today = null): string
    {
        $today ??= today();
        $type = $this->type;

        if ($this->status === self::INVALID) {
            return self::INVALID;
        }

        if ($this->expires_on !== null && $this->expires_on->lt($today)) {
            return self::EXPIRED;
        }

        if ($this->status !== self::VALID || $this->checked_at === null) {
            return self::UNCHECKED;
        }

        if ($type?->recheck_days !== null && $this->checked_at->copy()->addDays($type->recheck_days)->lt($today)) {
            return self::RECHECK_DUE;
        }

        if ($this->expires_on !== null && $this->expires_on->lte($today->copy()->addDays($type?->expiry_warning_days ?? 30))) {
            return self::EXPIRING;
        }

        return self::VALID;
    }

    /**
     * Whether staff should act (anything but valid or merely expiring soon).
     */
    public function needsAttention(?Carbon $today = null): bool
    {
        return ! in_array($this->state($today), [self::VALID, self::EXPIRING], true);
    }
}
