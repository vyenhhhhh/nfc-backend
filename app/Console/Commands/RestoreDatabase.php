<?php

namespace App\Console\Commands;

use App\Services\Backup;
use Illuminate\Console\Command;

// php artisan mytrack:restore storage/app/backups/mytrack-backup-2026-11-02-080000.json.gz
// php artisan mytrack:restore <file> --table=attendance_records     (one table only)
// Puts back rows that are MISSING (same id). It never deletes or changes rows that are there,
// so it can't make things worse on the live database.
class RestoreDatabase extends Command
{
    protected $signature = 'mytrack:restore {file} {--table=}';
    protected $description = 'Put back missing rows from a MYTRACK backup (never deletes or overwrites)';

    public function handle(): int
    {
        $file = $this->argument('file');
        if (!is_file($file)) { $this->error("File not found: {$file}"); return self::FAILURE; }
        if (!$this->confirm('Add the missing rows from this backup to the database?', true)) return self::SUCCESS;
        try {
            foreach (Backup::restore($file, $this->option('table')) as $t => $n) {
                $this->line(str_pad($t, 32) . ($n ? "{$n} row(s) put back" : 'nothing missing'));
            }
            $this->info('Done.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Restore failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}