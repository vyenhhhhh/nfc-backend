<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * From the OJT coordinator's answers:
 *  - each intern has an HTE supervisor (assigned by the admin) who sees only their own interns
 *  - overtime and forgotten time-outs: the supervisor reviews first, then the OJT coordinator decides
 *  - the supervisor certifies the intern's DTR per period
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'supervisor_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('supervisor_id')->nullable()->index();   // the intern's HTE supervisor
            });
        }

        // overtime: the supervisor's step (status "endorsed" = supervisor approved, waiting for the coordinator)
        Schema::table('attendance_records', function (Blueprint $table) {
            if (!Schema::hasColumn('attendance_records', 'ot_endorsed_minutes')) $table->unsignedInteger('ot_endorsed_minutes')->nullable();
            if (!Schema::hasColumn('attendance_records', 'ot_endorsed_by'))      $table->unsignedBigInteger('ot_endorsed_by')->nullable();
            if (!Schema::hasColumn('attendance_records', 'ot_endorsed_at'))      $table->timestamp('ot_endorsed_at')->nullable();
            if (!Schema::hasColumn('attendance_records', 'ot_endorse_remarks'))  $table->string('ot_endorse_remarks', 300)->nullable();
        });

        // time corrections: the same supervisor step
        Schema::table('time_corrections', function (Blueprint $table) {
            if (!Schema::hasColumn('time_corrections', 'endorsed_by'))     $table->unsignedBigInteger('endorsed_by')->nullable();
            if (!Schema::hasColumn('time_corrections', 'endorsed_at'))     $table->timestamp('endorsed_at')->nullable();
            if (!Schema::hasColumn('time_corrections', 'endorse_remarks')) $table->string('endorse_remarks', 300)->nullable();
        });

        // the supervisor certifies an intern's DTR for a period (e.g. Oct 1–15)
        if (!Schema::hasTable('dtr_certifications')) {
            Schema::create('dtr_certifications', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');            // the intern
                $table->date('period_from');
                $table->date('period_to');
                $table->decimal('hours', 6, 2)->default(0);       // hours shown on the DTR when it was certified
                $table->string('remarks', 300)->nullable();
                $table->unsignedBigInteger('certified_by');       // the supervisor
                $table->timestamp('certified_at')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'period_from', 'period_to']);
            });
        }

        // Supabase: block its public REST API on the new table (Laravel owns the table, so it still works)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table dtr_certifications enable row level security');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dtr_certifications');
        Schema::table('time_corrections', function (Blueprint $table) {
            $table->dropColumn(['endorsed_by', 'endorsed_at', 'endorse_remarks']);
        });
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn(['ot_endorsed_minutes', 'ot_endorsed_by', 'ot_endorsed_at', 'ot_endorse_remarks']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('supervisor_id');
        });
    }
};