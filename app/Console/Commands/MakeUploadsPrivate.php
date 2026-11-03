<?php

namespace App\Console\Commands;

use App\Services\PrivateFiles;
use Illuminate\Console\Command;

// php artisan uploads:make-private            moves the files
// php artisan uploads:make-private --dry-run  only shows what would move
//
// Moves the private uploads that were saved before (requirements, evaluation forms, school-event proofs,
// signatures, old WFH letters) from the public place (UPLOADS_DISK) to the private one
// (PRIVATE_UPLOADS_DISK), keeping the same paths so the database rows still match.
// A file is deleted from the public place only after its copy is confirmed.
// Safe to run more than once.
class MakeUploadsPrivate extends Command
{
    protected $signature = 'uploads:make-private {--dry-run : only list the files}';
    protected $description = 'Move requirement files, proofs and signatures to the private storage';

    public function handle(): int
    {
        $from = PrivateFiles::publicDisk();
        $to   = PrivateFiles::disk();
        $this->info('From: ' . config('filesystems.uploads') . '   To: ' . PrivateFiles::diskName());
        if (config('filesystems.uploads') === PrivateFiles::diskName()) {
            $this->error('UPLOADS_DISK and PRIVATE_UPLOADS_DISK are the same. Set PRIVATE_UPLOADS_DISK first.');
            return self::FAILURE;
        }

        $moved = 0; $failed = 0;
        foreach (PrivateFiles::FOLDERS as $folder) {
            foreach ($from->allFiles($folder) as $path) {
                if ($this->option('dry-run')) { $this->line("would move  $path"); $moved++; continue; }
                try {
                    if (!$to->exists($path)) {
                        $to->writeStream($path, $from->readStream($path));
                    }
                    if ($to->exists($path) && $to->size($path) === $from->size($path)) {
                        $from->delete($path);
                        $this->line("moved  $path");
                        $moved++;
                    } else {
                        $this->error("check  $path  (the copy doesn't match, the original was kept)");
                        $failed++;
                    }
                } catch (\Throwable $e) {
                    $this->error("failed  $path  ({$e->getMessage()})");
                    $failed++;
                }
            }
        }
        $this->info(($this->option('dry-run') ? 'Would move' : 'Moved') . " $moved file(s). Failed $failed.");
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
