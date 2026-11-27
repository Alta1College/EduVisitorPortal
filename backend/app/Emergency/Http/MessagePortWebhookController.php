<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Emergency\Http;

use App\Emergency\Broadcast\EmergencyNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * MessagePort delivery receipts (event message_dlr). Anonymous, outside the session/CSRF stack;
 * trusted only through the Signature header: HMAC-SHA256 of the raw body with the webhook
 * secret. Only updates messages we sent (known reference). MessagePort retries up to 15 times,
 * so the same receipt may arrive again: handling is idempotent, and a final status (delivered,
 * failed) is never overwritten.
 */
class MessagePortWebhookController
{
    private const FAILED_STATUSES = ['failed', 'cancelled', 'opt_out', 'filtered', 'filtered_duplicate'];

    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('messageport.webhook_secret');
        $raw = $request->getContent();
        $signature = trim((string) $request->header('Signature'));
        // The docs give HMAC-SHA256 of the raw body without naming the encoding: accept hex or base64.
        $expected = $secret !== '' ? hash_hmac('sha256', $raw, $secret, true) : '';
        $valid = $secret !== '' && $signature !== '' && (
            hash_equals(bin2hex($expected), strtolower($signature))
            || hash_equals(base64_encode($expected), $signature)
        );

        if (! $valid) {
            Log::warning('messageport_webhook_rejected', ['reason' => $secret === '' ? 'no_secret_configured' : 'bad_signature']);

            return response()->json(['ok' => false], 401);
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload) || ($payload['event'] ?? null) !== 'message_dlr') {
            return response()->json(['ok' => true, 'ignored' => true]);
        }

        $reference = is_string($payload['reference'] ?? null) ? $payload['reference'] : '';
        $status = is_string($payload['status'] ?? null) ? strtolower($payload['status']) : '';

        $row = $reference !== '' && strlen($reference) <= 64
            ? EmergencyNotification::query()->where('reference', $reference)->where('channel', EmergencyNotification::CHANNEL_SMS)->first()
            : null;

        if ($row === null) {
            return response()->json(['ok' => true, 'ignored' => true]);
        }

        $this->apply($row, $status, $payload['id'] ?? null);

        return response()->json(['ok' => true]);
    }

    private function apply(EmergencyNotification $row, string $status, mixed $providerId): void
    {
        $new = match (true) {
            $status === 'delivered' => EmergencyNotification::DELIVERED,
            $status === 'sent' => EmergencyNotification::SENT,
            str_starts_with($status, 'failed') || in_array($status, self::FAILED_STATUSES, true) => EmergencyNotification::FAILED,
            default => null,
        };

        if (filled($providerId) && (is_string($providerId) || is_int($providerId)) && $row->provider_message_id === null) {
            EmergencyNotification::query()->whereKey($row->getKey())->whereNull('provider_message_id')
                ->update(['provider_message_id' => mb_substr((string) $providerId, 0, 64)]);
        }

        // Final statuses stay; 'sent' arriving after 'delivered' (retries, ordering) is ignored.
        // Conditional in the database, so a send worker or another receipt writing at the same
        // moment cannot be overwritten with an older status (and vice versa).
        if ($new !== null && ! $row->isFinal() && $row->status !== $new) {
            $changed = EmergencyNotification::query()->whereKey($row->getKey())
                ->whereNotIn('status', [...EmergencyNotification::FINAL, $new])
                ->update([
                    'status' => $new,
                    'error_code' => $new === EmergencyNotification::FAILED ? strtoupper(mb_substr($status, 0, 64)) : null,
                ]);

            if ($changed > 0) {
                Log::info('emergency_sms_receipt', ['reference' => $row->reference, 'to' => $row->to_masked, 'status' => $status]);
            }
        }
    }
}
