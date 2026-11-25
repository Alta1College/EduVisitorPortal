<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\VisitLog;

use LogicException;

/**
 * A change that would rewrite or remove visitor-log history (a visit or attendance with a
 * recorded sign-in) was refused.
 */
class AttendedVisitLocked extends LogicException {}
