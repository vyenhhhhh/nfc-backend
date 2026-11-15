<?php

namespace App\Console\Commands;

use App\Services\PrivateFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | php artisan eval:cleanup
 | Deletes every user evaluation account (…@eval.test), everything that belongs to them
 | (attendance, uploads and their files, cards, grades, notifications, ...) and the account list file.
 */
class EvaluationCleanup extends Command
{
    protected $signature = 'eval:cleanup {--force : Do not ask to confirm}';
    protected $description = 'Delete the user evaluation accounts and all their data';

    public function handle(): int
    {
        $ids = DB::table('users')->where('email', 'like', '%@' . EvaluationSetup::DOMAIN)->pluck('id')->all();
        if (!$ids) {
            $this->info('There are no evaluation accounts.');
            @unlink(storage_path('app/evaluation-accounts.csv'));
            return self::SUCCESS;
        }
        $db = config('database.default');
        $this->warn(count($ids) . ' evaluation accounts and all their data will be deleted (host: ' . (config("database.connections.$db.host") ?? '-') . ').');
        if (!$this->option('force') && !$this->confirm('Delete them?', false)) {
            $this->info('Nothing was changed.');
            return self::SUCCESS;
        }

        self::purge($ids);
        @unlink(storage_path('app/evaluation-accounts.csv'));

        $this->info(count($ids) . ' evaluation accounts and their data were deleted.');
        return self::SUCCESS;
    }

    /**
     * Deletes these users and everything that belongs to them or that they made while testing:
     *   - every row of every table that points at them (user_id, intern_id)
     *   - their uploads, profile photos and signatures (the files too)
     *   - the announcements, report requests, due dates and quarterly reports they made
     * Kept: kiosks, the requirement list, the blank forms and templates, and the calendar.
     */
    public static function purge(array $ids): void
    {
        if (!$ids) return;

        // files to delete once the rows are gone
        $files = [];
        if (Schema::hasTable('movs')) $files = DB::table('movs')->whereIn('user_id', $ids)->pluck('file_path')->all();
        $people = DB::table('users')->whereIn('id', $ids)->get();
        $photos = $people->pluck('photo')->filter()->all();
        if (Schema::hasColumn('users', 'signature')) $files = array_merge($files, $people->pluck('signature')->filter()->all());

        DB::transaction(function () use ($ids) {
            // what they made while testing
            $made = [
                'announcements'         => ['created_by', 'posted_by', 'user_id'],
                'announcement_history'  => ['created_by'],
                'quarterly_reports'     => ['updated_by'],
                'requirement_deadlines' => ['set_by'],
            ];
            foreach ($made as $table => $cols) {
                if (!Schema::hasTable($table)) continue;
                foreach ($cols as $col) if (Schema::hasColumn($table, $col)) DB::table($table)->whereIn($col, $ids)->delete();
            }
            // report requests they made, and the reports uploaded for them
            if (Schema::hasTable('report_schedules') && Schema::hasColumn('report_schedules', 'created_by')) {
                $reports = DB::table('report_schedules')->whereIn('created_by', $ids)->pluck('id')->all();
                if ($reports && Schema::hasColumn('movs', 'report_id')) DB::table('movs')->whereIn('report_id', $reports)->delete();
                DB::table('report_schedules')->whereIn('id', $reports)->delete();
            }

            // every table with a column pointing at these users
            foreach (Schema::getTables() as $t) {
                $name = $t['name'];
                if (in_array($name, ['users', 'migrations'], true)) continue;
                foreach (['user_id', 'intern_id'] as $col) {
                    if (Schema::hasColumn($name, $col)) DB::table($name)->whereIn($col, $ids)->delete();
                }
            }
            if (Schema::hasColumn('users', 'supervisor_id')) DB::table('users')->whereIn('supervisor_id', $ids)->update(['supervisor_id' => null]);
            DB::table('users')->whereIn('id', $ids)->delete();
        });

        // the files (a file still used by someone else is kept)
        foreach (array_unique($files) as $f) {
            if (!$f) continue;
            if (Schema::hasTable('movs') && DB::table('movs')->where('file_path', $f)->exists()) continue;
            PrivateFiles::delete($f);
        }
        foreach (array_unique($photos) as $p) {
            if (DB::table('users')->where('photo', $p)->exists()) continue;
            try { \Illuminate\Support\Facades\Storage::disk(config('filesystems.uploads', 'public'))->delete($p); } catch (\Throwable $e) { /* keep going */ }
        }
    }
}