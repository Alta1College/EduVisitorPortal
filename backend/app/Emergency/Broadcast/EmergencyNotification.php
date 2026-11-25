<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Emergency\Broadcast;

use App\Emergency\RollCall;
use App\Emergency\RollCallEntry;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One emergency message (SMS or e-mail) to one person on a roll call, with its outcome.
 *
 * @property int $roll_call_id
 * @property int|null $roll_call_entry_id
 * @property int|null $visitor_id
 * @property string $channel
 * @property string|null $to_masked
 * @property string|null $reference
 * @property string|null $provider_message_id
 * @property string $status
 * @property string|null $error_code
 */
class EmergencyNotification extends Model
{
    public const CHANNEL_SMS = 'sms';

    public const CHANNEL_EMAIL = 'email';

    public const PENDING = 'pending';

    /** Accepted by the SMS provider, not yet sent to the network. */
    public const ACCEPTED = 'accepted';

    public const SENT = 'sent';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    public const NO_MOBILE = 'no_mobile';

    /** Statuses a delivery receipt may not change any more. */
    public const FINAL = [self::DELIVERED, self::FAILED, self::NO_MOBILE];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'roll_call_id', 'roll_call_entry_id', 'visitor_id', 'channel', 'to_masked', 'reference',
        'provider_message_id', 'status', 'error_code',
    ];

    public function rollCall(): BelongsTo
    {
        return $this->belongsTo(RollCall::class);
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(RollCallEntry::class, 'roll_call_entry_id');
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL, true);
    }

    public static function smsReference(int $rollCallId, int $entryId): string
    {
        return 'rc'.$rollCallId.'-e'.$entryId;
    }
}
