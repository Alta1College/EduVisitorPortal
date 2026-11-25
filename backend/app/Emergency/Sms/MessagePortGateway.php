<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Emergency\Sms;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Edgility MessagePort SMS API v2 (https://dev.messageport.com.au/guides/sending-messages).
 * Up to 1000 messages per request; accepted messages come back in data.messages, per-message
 * rejections in data.errors, request-level failures (bad key, outage) as HTTP status codes.
 * Acceptance means queued, not delivered: delivery arrives later by webhook.
 */
class MessagePortGateway implements SmsGateway
{
    public const BATCH_SIZE = 1000;

    public static function configured(): bool
    {
        return self::missingSettings() === [];
    }

    /**
     * @return list<string> environment variable names that are not set
     */
    public static function missingSettings(): array
    {
        $missing = [];

        foreach (['access_token' => 'MESSAGEPORT_ACCESS_TOKEN', 'token_id' => 'MESSAGEPORT_TOKEN_ID', 'sender_id' => 'MESSAGEPORT_SENDER_ID', 'base_url' => 'MESSAGEPORT_BASE_URL'] as $key => $env) {
            if (blank(config('messageport.'.$key))) {
                $missing[] = $env;
            }
        }

        return $missing;
    }

    public function send(array $messages): array
    {
        $results = [];

        if (! self::configured()) {
            Log::warning('emergency_sms_not_configured', ['missing' => self::missingSettings()]);

            return $this->failAll($messages, 'NOT_CONFIGURED');
        }

        foreach (array_chunk($messages, self::BATCH_SIZE) as $batch) {
            $results += $this->sendBatch($batch);
        }

        return $results;
    }

    /**
     * @param  list<SmsMessage>  $batch
     * @return array<string, SmsResult>
     */
    private function sendBatch(array $batch): array
    {
        $sender = (string) config('messageport.sender_id');
        $url = rtrim((string) config('messageport.base_url'), '/').'/v2/sms'.(config('messageport.dry_run') ? '/dry' : '');

        try {
            $response = Http::withBasicAuth((string) config('messageport.access_token'), (string) config('messageport.token_id'))
                ->acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout(max(5, (int) config('messageport.timeout', 20)))
                ->post($url, ['messages' => array_map(fn (SmsMessage $message): array => array_filter([
                    'to' => $message->to,
                    'from' => $sender,
                    'body' => $message->body,
                    'reference' => $message->reference,
                    'metadata' => $message->metadata !== [] ? $message->metadata : null,
                ], fn ($value): bool => $value !== null), $batch)]);
        } catch (Throwable $exception) {
            Log::error('emergency_sms_request_failed', ['messages' => count($batch), 'error' => class_basename($exception)]);

            return $this->failAll($batch, 'CONNECTION_FAILED');
        }

        if (! $response->successful()) {
            Log::error('emergency_sms_request_rejected', [
                'status' => $response->status(),
                'messages' => count($batch),
                'hint' => $response->status() === 401 ? 'check MESSAGEPORT_ACCESS_TOKEN and MESSAGEPORT_TOKEN_ID' : null,
            ]);

            return $this->failAll($batch, 'HTTP_'.$response->status());
        }

        return $this->results($batch, $response);
    }

    /**
     * @param  list<SmsMessage>  $batch
     * @return array<string, SmsResult>
     */
    private function results(array $batch, Response $response): array
    {
        $references = [];
        $byNumber = [];

        foreach ($batch as $message) {
            $references[$message->reference] = true;
            $byNumber[ltrim($message->to, '+')][] = $message->reference;
        }

        $results = [];
        $errors = $response->json('data.errors');

        foreach (is_array($errors) ? $errors : [] as $error) {
            if (! is_array($error)) {
                continue;
            }

            $code = is_string($error['error_code'] ?? null) ? $error['error_code'] : 'REJECTED';
            $reference = is_string($error['reference'] ?? null) ? $error['reference'] : null;
            $targets = $reference !== null && isset($references[$reference])
                ? [$reference]
                : ($byNumber[ltrim((string) ($error['to'] ?? ''), '+')] ?? []);

            foreach ($targets as $target) {
                $results[$target] = SmsResult::failed($code);
            }

            Log::warning('emergency_sms_message_rejected', [
                'to' => PhoneNumber::mask((string) ($error['to'] ?? '')),
                'error_code' => $code,
                'help' => is_string($error['error_help'] ?? null) ? mb_substr($error['error_help'], 0, 300) : null,
            ]);
        }

        // Everything the API did not reject was queued for delivery.
        foreach ($batch as $message) {
            $results[$message->reference] ??= SmsResult::accepted();
        }

        return $results;
    }

    /**
     * @param  list<SmsMessage>  $messages
     * @return array<string, SmsResult>
     */
    private function failAll(array $messages, string $code): array
    {
        $results = [];

        foreach ($messages as $message) {
            $results[$message->reference] = SmsResult::failed($code);
        }

        return $results;
    }
}
