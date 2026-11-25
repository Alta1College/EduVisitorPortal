<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Microsoft;

use RuntimeException;

/**
 * Missing credentials or a refused token request. Never carries the secret or the token.
 */
class GraphTokenException extends RuntimeException {}
