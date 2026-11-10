<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which kiosk recorded each time in and time out ("main" = the CCIS kiosk key in .env, else the kiosk's id).
 * The kiosk screen lists today's taps from this after a reload, and the admin sees each kiosk's tap history.
 * Safe on the shared database: it only adds two empty columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            if (!Schema::hasColumn('attendance_records', 'kiosk_in'))  $table->string('kiosk_in', 40)->nullable();
            if (!Schema::hasColumn('attendance_records', 'kiosk_out')) $table->string('kiosk_out', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            if (Schema::hasColumn('attendance_records', 'kiosk_in'))  $table->dropColumn('kiosk_in');
            if (Schema::hasColumn('attendance_records', 'kiosk_out')) $table->dropColumn('kiosk_out');
        });
    }
};