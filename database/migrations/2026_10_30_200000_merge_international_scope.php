<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scope is now local | inbound | outbound (inbound and outbound are both international).
 * Interns saved as "international" become "outbound" (a CSU student with a foreign HTE).
 * Safe to run on the shared database: it only changes that one value.
 */
class MergeInternationalScope extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'scope')) {
            DB::table('users')->where('scope', 'international')->update(['scope' => 'outbound']);
        }
    }

    public function down(): void
    {
        // nothing to undo
    }
}