<?php
// 2026_11_24_000100_allow_resubmitting_rejected_online_days.php
// An intern may submit online attendance again for a date whose earlier submission was REJECTED
// (same as time corrections and authorized absences). The rejected one stays as history.
// Before: one row per intern per date. Now: one row per intern per date that is not rejected.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE online_submissions DROP CONSTRAINT IF EXISTS online_submissions_user_id_date_unique');
        DB::statement('DROP INDEX IF EXISTS online_submissions_user_id_date_unique');
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS online_submissions_one_active_per_day ON online_submissions (user_id, date) WHERE status <> 'rejected'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS online_submissions_one_active_per_day');
        // only works again if no date has a rejected row plus a newer one
        DB::statement('ALTER TABLE online_submissions ADD CONSTRAINT online_submissions_user_id_date_unique UNIQUE (user_id, date)');
    }
};