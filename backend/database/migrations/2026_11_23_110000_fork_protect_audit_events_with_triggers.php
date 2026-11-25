<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Database-level protection of the audit log. The AuditEvent model already refuses updates
 * and deletes; these triggers also stop raw SQL from the application's database account:
 *
 * - UPDATE on audit_events is always rejected.
 * - DELETE is rejected unless the session variable @visitorportal_audit_purge = 1, which only
 *   the retention purge (visitorportal:audit:purge) sets on its own connection.
 *
 * MariaDB/MySQL only; other drivers are skipped. Creating triggers needs the TRIGGER privilege
 * on the database (the official MariaDB image grants ALL on the app database to MARIADB_USER).
 * With binary logging on, it also needs SUPER or log_bin_trust_function_creators=1
 * (docs/fork/phase-11-security-validation.md).
 */
return new class extends Migration
{
    private const UPDATE_TRIGGER = 'audit_events_block_update';

    private const DELETE_TRIGGER = 'audit_events_block_delete';

    public function up(): void
    {
        if (! $this->supported()) {
            return;
        }

        $this->dropTriggers();

        try {
            DB::unprepared(sprintf(<<<'SQL'
                CREATE TRIGGER %s BEFORE UPDATE ON audit_events FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_events is append-only: UPDATE is not allowed';
                END
                SQL, self::UPDATE_TRIGGER));

            DB::unprepared(sprintf(<<<'SQL'
                CREATE TRIGGER %s BEFORE DELETE ON audit_events FOR EACH ROW
                BEGIN
                    IF COALESCE(@visitorportal_audit_purge, 0) <> 1 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_events is append-only: only the retention purge may delete';
                    END IF;
                END
                SQL, self::DELETE_TRIGGER));
        } catch (QueryException $exception) {
            $code = (int) ($exception->errorInfo[1] ?? 0);

            // 1142: TRIGGER command denied; 1419: SUPER needed because binary logging is on;
            // 1227: access denied (SUPER/privilege).
            if (in_array($code, [1142, 1419, 1227], true)) {
                throw new RuntimeException(
                    'Cannot create the audit log protection triggers: the database user lacks the privilege. '
                    .'Grant it as the database root user, then run the migrations again: '
                    ."GRANT TRIGGER ON `<database>`.* TO '<app user>'@'%'; "
                    .'If binary logging is enabled, also SET GLOBAL log_bin_trust_function_creators = 1 '
                    .'(and add it to the server configuration). See docs/fork/phase-11-security-validation.md.',
                    previous: $exception,
                );
            }

            throw $exception;
        }
    }

    public function down(): void
    {
        if (! $this->supported()) {
            return;
        }

        $this->dropTriggers();
    }

    private function supported(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function dropTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::UPDATE_TRIGGER);
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::DELETE_TRIGGER);
    }
};
