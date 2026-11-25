<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Emergency messages to visitors (docs/fork/emergency-broadcast.md). When a roll call starts
 * for a real emergency (or a drill with "send drill messages" switched on), everyone signed in at
 * the site gets an SMS (needs MessagePort, config/messageport.php) and/or an e-mail. Both are
 * off until the organisation switches them on.
 */
return [
    'sms_enabled' => filter_var(env('EMERGENCY_SMS_ENABLED', false), FILTER_VALIDATE_BOOL),
    'email_enabled' => filter_var(env('EMERGENCY_EMAIL_ENABLED', false), FILTER_VALIDATE_BOOL),
];
