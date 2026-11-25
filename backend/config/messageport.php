<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Edgility MessagePort SMS API (https://dev.messageport.com.au/), used for emergency messages to
 * visitors (docs/fork/emergency-broadcast.md). Authentication is HTTP Basic: the API key's
 * Access Token as user name and its Token ID as password. The sender ID must be approved in
 * MessagePort. Delivery receipts arrive at POST /webhooks/messageport, signed with the webhook
 * secret. Dry run uses /v2/sms/dry: everything is validated, nothing is sent.
 */
return [
    'access_token' => env('MESSAGEPORT_ACCESS_TOKEN'),
    'token_id' => env('MESSAGEPORT_TOKEN_ID'),
    'sender_id' => env('MESSAGEPORT_SENDER_ID', ''),
    'webhook_secret' => env('MESSAGEPORT_WEBHOOK_SECRET'),
    'base_url' => env('MESSAGEPORT_BASE_URL', 'https://app.messageport.com.au/api'),
    'dry_run' => filter_var(env('MESSAGEPORT_DRY_RUN', false), FILTER_VALIDATE_BOOL),
    'timeout' => (int) env('MESSAGEPORT_TIMEOUT', 20),
];
