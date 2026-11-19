<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the lookups the system makes most often, so they stay fast as the records grow:
 *  - the kiosk finds a card by UPPER(uid) on every tap (the plain uid index can't be used for that)
 *  - the bell asks for a user's notifications every few seconds
 *  - "today's taps" lists (kiosk screen, dashboards) look attendance up by date
 *  - requirement checks look submissions up by intern + requirement
 * Safe to run on the shared database: it only adds indexes (IF NOT EXISTS), no data changes.
 */
return new class extends Migration
{
    private array $indexes = [
        ['nfc_cards',          'nfc_cards_upper_uid_index',                 '(UPPER(uid))'],
        ['notifications',      'notifications_user_id_is_read_index',       '(user_id, is_read)'],
        ['attendance_records', 'attendance_records_date_index',             '(date)'],
        ['movs',               'movs_user_id_requirement_index',            '(user_id, requirement)'],
        ['users',              'users_role_index',                          '(role)'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;
        foreach ($this->indexes as [$table, $name, $cols]) {
            if (!Schema::hasTable($table)) continue;
            DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON {$table} {$cols}");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;
        foreach ($this->indexes as [, $name]) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
    }
};