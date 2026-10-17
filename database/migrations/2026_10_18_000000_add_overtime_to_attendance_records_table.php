<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Overtime (time after 5:00 PM) only counts after the OJT coordinator approves it.
//   ot_minutes          overtime found when the intern checked out
//   ot_status           null (no overtime) | pending | approved | rejected
//   ot_approved_minutes minutes the coordinator credited (still capped at 8 h per day)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            if (!Schema::hasColumn('attendance_records', 'ot_minutes')) {
                $table->unsignedInteger('ot_minutes')->default(0);
            }
            if (!Schema::hasColumn('attendance_records', 'ot_status')) {
                $table->string('ot_status', 10)->nullable();
            }
            if (!Schema::hasColumn('attendance_records', 'ot_approved_minutes')) {
                $table->unsignedInteger('ot_approved_minutes')->default(0);
            }
            if (!Schema::hasColumn('attendance_records', 'ot_remarks')) {
                $table->string('ot_remarks', 300)->nullable();
            }
            if (!Schema::hasColumn('attendance_records', 'ot_reviewed_by')) {
                $table->unsignedBigInteger('ot_reviewed_by')->nullable();
            }
            if (!Schema::hasColumn('attendance_records', 'ot_reviewed_at')) {
                $table->timestamp('ot_reviewed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            foreach (['ot_minutes', 'ot_status', 'ot_approved_minutes', 'ot_remarks', 'ot_reviewed_by', 'ot_reviewed_at'] as $col) {
                if (Schema::hasColumn('attendance_records', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};