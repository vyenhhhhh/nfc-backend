<?php
// st04-cleanup.php: removes the 34 ST-04 test accounts and everything they made (files, tokens, notices).
// Run in the nfc-backend folder:   php st04-cleanup.php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Services\SchemaCache;

$ids = DB::table('users')->where('email', 'like', 'st04-%@demo.test')->pluck('id')->all();
if (!$ids) { echo "No ST-04 accounts found.\n"; exit(0); }

$files = 0;
foreach (DB::table('movs')->whereIn('user_id', $ids)->pluck('file_path') as $p) {
    foreach (['local', 'public'] as $disk) {
        try { if ($p && Storage::disk($disk)->exists($p)) { Storage::disk($disk)->delete($p); $files++; } } catch (\Throwable $e) { /* skip */ }
    }
}
foreach (['movs' => 'user_id', 'api_tokens' => 'user_id', 'notifications' => 'user_id', 'activity_logs' => 'user_id'] as $t => $col) {
    if (SchemaCache::has($t, $col)) DB::table($t)->whereIn($col, $ids)->delete();
}
DB::table('users')->whereIn('id', $ids)->delete();
echo "Removed " . count($ids) . " ST-04 accounts and {$files} uploaded files." . PHP_EOL;