<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * There is no output-based tracking: every intern renders the required hours of their program
 * (486 for BSIT and BSIS, 320 for BSCS). Interns saved as output-based become hours-based.
 * Safe to run on the shared database: it only changes that one value.
 */
class AllInternsHoursBased extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'tracking_type')) {
            DB::table('users')->where('tracking_type', 'output')->update(['tracking_type' => 'hours']);
        }
    }

    public function down(): void
    {
        // nothing to undo
    }
}