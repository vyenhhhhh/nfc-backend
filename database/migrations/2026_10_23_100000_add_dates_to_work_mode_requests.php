<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Work arrangement for a period: work from home from a start date until an end date (or until changed back).
// applied_at = when the new work mode actually started; ended_at = when the intern went back onsite at the end date.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_mode_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('work_mode_requests', 'start_date')) $table->date('start_date')->nullable();
            if (!Schema::hasColumn('work_mode_requests', 'end_date'))   $table->date('end_date')->nullable();
            if (!Schema::hasColumn('work_mode_requests', 'applied_at')) $table->timestamp('applied_at')->nullable();
            if (!Schema::hasColumn('work_mode_requests', 'ended_at'))   $table->timestamp('ended_at')->nullable();
        });

        // requests approved before this update already changed the work mode: mark them as applied,
        // so they are never applied a second time
        DB::table('work_mode_requests')->where('status', 'approved')->whereNull('applied_at')
            ->update(['applied_at' => DB::raw('COALESCE(reviewed_at, updated_at, created_at)')]);
    }

    public function down(): void
    {
        Schema::table('work_mode_requests', function (Blueprint $table) {
            $table->dropColumn(['start_date', 'end_date', 'applied_at', 'ended_at']);
        });
    }
};