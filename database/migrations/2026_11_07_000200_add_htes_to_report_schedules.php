<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Weekly / monthly reports can also be asked "By HTE": the interns placed at the HTEs picked (users.placement).
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('report_schedules') && !Schema::hasColumn('report_schedules', 'htes')) {
            Schema::table('report_schedules', function (Blueprint $table) {
                $table->text('htes')->nullable();   // JSON, e.g. ["City Health Department","CCIS IT Faculty"]
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('report_schedules', 'htes')) {
            Schema::table('report_schedules', fn (Blueprint $table) => $table->dropColumn('htes'));
        }
    }
};
