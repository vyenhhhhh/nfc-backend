<?php
//BackupController.php
namespace App\Http\Controllers;

use App\Services\Activity;
use App\Services\Backup;
use Illuminate\Http\Request;

/** Admin → Accounts → Backups */
class BackupController extends Controller
{
    // GET /api/admin/backups
    public function index()
    {
        return response()->json(['backups' => Backup::list()]);
    }

    // POST /api/admin/backups
    public function store(Request $request)
    {
        @set_time_limit(300);
        try {
            $b = Backup::make();
        } catch (\Throwable $e) {
            return response()->json(['message' => 'The backup failed: ' . $e->getMessage()], 500);
        }
        Activity::record($request->attributes->get('auth_user'), 'Database backup', $b['name'], $request);
        return response()->json(['message' => "Backup made ({$b['rows']} rows). Download it and keep a copy somewhere safe.", 'backup' => $b]);
    }

    // GET /api/admin/backups/{name}
    public function download(Request $request, string $name)
    {
        $path = Backup::path($name);
        if (!$path) return response()->json(['message' => 'Backup not found.'], 404);
        Activity::record($request->attributes->get('auth_user'), 'Database backup downloaded', $name, $request);
        return response()->download($path, $name, ['Content-Type' => 'application/gzip']);
    }
}