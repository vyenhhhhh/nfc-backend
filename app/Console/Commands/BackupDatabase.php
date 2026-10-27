<?php

namespace App\Console\Commands;

use App\Services\Backup;
use Illuminate\Console\Command;

// php artisan mytrack:backup
// Saves every table into storage/app/backups/mytrack-backup-<date>.json.gz (the last 12 are kept).
// Windows Task Scheduler can run it every week (see the setup notes).
class BackupDatabase extends Command
{
    protected $signature = 'mytrack:backup';
    protected $description = 'Back up the MYTRACK database (every table) to storage/app/backups';

    public function handle(): int
    {
        try {
            $b = Backup::make();
            $this->info("Backup saved: storage/app/backups/{$b['name']} ({$b['rows']} rows, " . round($b['size'] / 1024) . ' KB)');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Backup failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}