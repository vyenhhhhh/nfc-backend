<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One university event per day (hte_key is null) and one event per HTE per day.
 * The old rule allowed only one event per date in the whole table.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE calendar_events DROP CONSTRAINT IF EXISTS calendar_events_date_unique');
        DB::statement('DROP INDEX IF EXISTS calendar_events_date_unique');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS calendar_events_university_day_unique ON calendar_events (date) WHERE hte_key IS NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS calendar_events_hte_day_unique ON calendar_events (date, hte_key) WHERE hte_key IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS calendar_events_university_day_unique');
        DB::statement('DROP INDEX IF EXISTS calendar_events_hte_day_unique');

        // the old single-calendar rule can't hold HTE rows, so they are removed
        DB::table('calendar_events')->whereNotNull('hte_key')->delete();
        DB::statement('ALTER TABLE calendar_events ADD CONSTRAINT calendar_events_date_unique UNIQUE (date)');
    }
};