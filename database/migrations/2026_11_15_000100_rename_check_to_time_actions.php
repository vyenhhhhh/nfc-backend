<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Attendance actions are named like the rest of the system: TIME_IN / TIME_OUT (were CHECK_IN / CHECK_OUT).
// Renames the saved rows and the action at the end of every saved token (uuid:UID:date:ACTION).
return new class extends Migration
{
    private function rename(string $from, string $to): void
    {
        foreach (['attendance_records', 'tokens'] as $table) {
            foreach (['IN', 'OUT'] as $x) {
                DB::table($table)->where('action', "{$from}_{$x}")->update(['action' => "{$to}_{$x}"]);
                $suffix = ":{$from}_{$x}";
                DB::update(
                    "UPDATE {$table} SET token = LEFT(token, LENGTH(token) - ?) || ? WHERE token LIKE ?",
                    [strlen($suffix), ":{$to}_{$x}", '%' . $suffix]
                );
            }
        }
    }

    public function up(): void
    {
        $this->rename('CHECK', 'TIME');
    }

    public function down(): void
    {
        $this->rename('TIME', 'CHECK');
    }
};