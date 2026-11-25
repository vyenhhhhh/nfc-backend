<?php

use App\Services\AppraisalRequirement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Appraisals submitted before the "Student Trainee Performance Appraisal" requirement followed the appraisal:
 * mark those interns' requirement as approved too (interns who uploaded the appraisal themselves are left alone).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('evaluations') || !Schema::hasTable('movs')) return;

        DB::table('evaluations')->where('mode', '!=', 'draft')->orderBy('id')->get()
            ->each(function ($e) {
                AppraisalRequirement::markDone((int) $e->intern_id, $e->evaluator_id ? (int) $e->evaluator_id : null,
                    $e->evaluator_name, $e->evaluator_role, $e->updated_at ?? $e->created_at);
            });
    }

    public function down(): void
    {
        if (!Schema::hasTable('movs')) return;
        DB::table('movs')->where('requirement', AppraisalRequirement::KEY)
            ->where('file_path', '')->where('original_name', AppraisalRequirement::ONLINE_NAME)->delete();
    }
};