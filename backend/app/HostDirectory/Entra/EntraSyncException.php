<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\HostDirectory\Entra;

use RuntimeException;

/**
 * The Entra host sync could not complete. Nothing was changed: local hosts stay as they were.
 */
class EntraSyncException extends RuntimeException {}
