<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Uploaded files that must not be reachable by a plain link:
 * requirement uploads (movs/), signed evaluation forms (evaluations/), school-event proofs (excuse-proofs/),
 * staff signatures (signatures/) and old WFH letters (work_mode/).
 *
 * They are kept on a PRIVATE disk (config filesystems.private_uploads):
 *   local             -> storage/app/private on this computer (default)
 *   supabase_private  -> a private Supabase bucket (SUPABASE_PRIVATE_BUCKET)
 * To open one, the page asks GET /api/files/link?path=..., the server checks who is asking
 * (FileController) and answers with a link that works for a few minutes only.
 *
 * Files uploaded before this change are still in the public place until
 * `php artisan uploads:make-private` moves them; until then they are still found there.
 * Profile photos and the blank requirement forms stay public (the kiosk shows the photo).
 */
class PrivateFiles
{
    public const FOLDERS = ['movs', 'evaluations', 'excuse-proofs', 'correction-proofs', 'signatures', 'work_mode'];
    public const LINK_MINUTES = 10;

    public static function isPrivate(?string $path): bool
    {
        $first = explode('/', ltrim((string) $path, '/'))[0] ?? '';
        return in_array($first, self::FOLDERS, true);
    }

    public static function diskName(): string
    {
        return (string) config('filesystems.private_uploads', 'local');
    }

    public static function disk()
    {
        return Storage::disk(self::diskName());
    }

    /** the old public place (where files were saved before) */
    public static function publicDisk()
    {
        return Storage::disk(config('filesystems.uploads', 'public'));
    }

    public static function store(UploadedFile $file, string $folder): string
    {
        return $file->store($folder, self::diskName());
    }

    /** deletes the file wherever it is (private, or the old public place) */
    public static function delete(?string $path): void
    {
        if (!$path) return;
        try { self::disk()->delete($path); } catch (\Throwable $e) { /* keep going */ }
        try { self::publicDisk()->delete($path); } catch (\Throwable $e) { /* keep going */ }
    }

    /** a short-lived link to the file, or null when it can't be found */
    public static function link(string $path): ?string
    {
        $until = now()->addMinutes(self::LINK_MINUTES);
        try {
            $d = self::disk();
            if ($d->exists($path)) {
                // this computer's disk: our own signed route (GET /api/files/open/...), so PDFs also show inside a page
                if ((config('filesystems.disks.' . self::diskName() . '.driver')) === 'local') {
                    return URL::temporarySignedRoute('files.open', $until, ['path' => $path]);
                }
                return $d->temporaryUrl($path, $until);   // Supabase: a signed link from the bucket
            }
        } catch (\Throwable $e) {
            report($e);
        }
        // not moved yet: still in the old public place
        try {
            $p = self::publicDisk();
            if ($p->exists($path)) return $p->url($path);
        } catch (\Throwable $e) {
            report($e);
        }
        return null;
    }
}