<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the taps a kiosk saved while it had no internet and sent later
 * (shown as "· offline" in Attendance Records, so the OJT coordinator can check them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $t) {
            if (!Schema::hasColumn('attendance_records', 'offline_in'))  $t->boolean('offline_in')->default(false);
            if (!Schema::hasColumn('attendance_records', 'offline_out')) $t->boolean('offline_out')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $t) {
            foreach (['offline_in', 'offline_out'] as $c) {
                if (Schema::hasColumn('attendance_records', $c)) $t->dropColumn($c);
            }
        });
    }
};
