<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

// php artisan uploads:copy-to-supabase
// Copies the files already on THIS laptop (storage/app/public) into the
// Supabase bucket, keeping the same paths, so the database rows still match.
// Safe to run more than once: files already in the bucket are skipped.
class CopyUploadsToSupabase extends Command
{
    protected $signature = 'uploads:copy-to-supabase';
    protected $description = 'Copy local uploaded files (profiles, movs, work_mode) to Supabase Storage';

    public function handle(): int
    {
        $local = Storage::disk('public');
        $cloud = Storage::disk('supabase');
        $copied = 0; $skipped = 0; $failed = 0;

        foreach (['profiles', 'movs', 'work_mode'] as $folder) {
            foreach ($local->allFiles($folder) as $path) {
                try {
                    if ($cloud->exists($path)) { $skipped++; continue; }
                    $cloud->writeStream($path, $local->readStream($path));
                    $this->line("copied  $path");
                    $copied++;
                } catch (\Throwable $e) {
                    $this->error("failed  $path  ({$e->getMessage()})");
                    $failed++;
                }
            }
        }

        $this->info("Done. Copied $copied, already there $skipped, failed $failed.");
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}