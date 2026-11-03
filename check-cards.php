<?php
// one-time check: which intern each card belongs to, and their pre-internship requirements
use Illuminate\Support\Facades\DB;

foreach (DB::table('nfc_cards')->get() as $c) {
    $u = DB::table('users')->find($c->user_id);
    echo "\nCARD {$c->uid} -> " . ($u->name ?? 'no user') . " (user id {$c->user_id})\n";
    $movs = DB::table('movs')->where('user_id', $c->user_id)->orderBy('requirement')->get(['requirement', 'phase', 'status']);
    foreach ($movs as $m) {
        echo "   {$m->requirement} | phase: " . ($m->phase ?? 'null') . " | {$m->status}\n";
    }
    echo "   MISSING: " . implode(', ', App\Services\Supervision::missingPreRequirements($c->user_id)) . "\n";
}