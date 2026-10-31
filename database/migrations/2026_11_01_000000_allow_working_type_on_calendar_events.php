<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $this->rewrite(fn (array $types) => array_values(array_unique([...$types, 'working'])));
    }

    public function down(): void
    {
        DB::table('calendar_events')->where('type', 'working')->delete();
        $this->rewrite(fn (array $types) => array_values(array_diff($types, ['working'])));
    }

    private function rewrite(callable $change): void
    {
        if (DB::getDriverName() !== 'pgsql') return;   // the check constraint is a Postgres thing

        $def = DB::selectOne(
            "select pg_get_constraintdef(oid) as d from pg_constraint where conname = 'calendar_events_type_check'"
        )?->d ?? '';

        preg_match_all("/'([^']+)'::/", $def, $m);     // the values the constraint allows today
        $types = $change($m[1] ?: ['holiday', 'non_working', 'event']);

        $list = implode(',', array_map(fn ($t) => "'" . str_replace("'", "''", $t) . "'", $types));
        DB::statement('ALTER TABLE calendar_events DROP CONSTRAINT IF EXISTS calendar_events_type_check');
        DB::statement("ALTER TABLE calendar_events ADD CONSTRAINT calendar_events_type_check CHECK (type IN ($list))");
    }
};