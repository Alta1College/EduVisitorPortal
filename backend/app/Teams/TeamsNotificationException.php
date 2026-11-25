<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Teams;

use RuntimeException;

/**
 * A temporary Graph failure (throttling, server error); the queued notification is retried.
 */
class TeamsNotificationException extends RuntimeException {}
