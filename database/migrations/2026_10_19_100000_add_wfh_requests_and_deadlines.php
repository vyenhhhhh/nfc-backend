<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * From the OJT coordinator's answers:
 *  - online attendance: the HTE supervisor confirms first, then the coordinator decides
 *  - WFH needs a letter from the HTE approving it (unless WFH is already in the MOA / Student Contract)
 *  - requirements get due dates, so late submissions can be followed up
 */
return new class extends Migration
{
    public function up(): void
    {
        // online attendance: the supervisor's step (status "endorsed" = confirmed by the supervisor)
        Schema::table('online_submissions', function (Blueprint $table) {
            if (!Schema::hasColumn('online_submissions', 'endorsed_by'))     $table->unsignedBigInteger('endorsed_by')->nullable();
            if (!Schema::hasColumn('online_submissions', 'endorsed_at'))     $table->timestamp('endorsed_at')->nullable();
            if (!Schema::hasColumn('online_submissions', 'endorse_remarks')) $table->string('endorse_remarks', 300)->nullable();
        });

        // WFH (or back to onsite) requests
        if (!Schema::hasTable('work_mode_requests')) {
            Schema::create('work_mode_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('requested_mode', 10);              // offsite | onsite
                $table->string('reason', 500);
                $table->boolean('in_moa')->default(false);         // WFH already stated in the MOA / Student Contract
                $table->string('file_path')->nullable();           // the HTE's letter approving the WFH
                $table->string('original_name')->nullable();
                $table->string('status', 10)->default('pending');  // pending | approved | rejected
                $table->string('remarks', 300)->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'status']);
            });
        }

        // due dates of the requirements (set by the OJT coordinator)
        if (!Schema::hasTable('requirement_deadlines')) {
            Schema::create('requirement_deadlines', function (Blueprint $table) {
                $table->id();
                $table->string('requirement', 60);
                $table->string('phase', 10);                       // pre | during | post
                $table->date('due_date');
                $table->unsignedBigInteger('set_by')->nullable();
                $table->timestamps();
                $table->unique(['requirement', 'phase']);
            });
        }

        // Supabase: block its public REST API on the new tables (Laravel owns them, so it still works)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table work_mode_requests enable row level security');
            DB::statement('alter table requirement_deadlines enable row level security');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('requirement_deadlines');
        Schema::dropIfExists('work_mode_requests');
        Schema::table('online_submissions', function (Blueprint $table) {
            $table->dropColumn(['endorsed_by', 'endorsed_at', 'endorse_remarks']);
        });
    }
};