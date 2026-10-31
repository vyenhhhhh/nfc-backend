<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * intern_grades: the SIP coordinator's part of the internship grade (40%) and the final grade.
 * The HTE supervisor's part (60%) stays in "evaluations" (their appraisal of the intern).
 * Safe to run on the shared database: it only adds a table.
 *
 * A named class (CreateInternGrades) so it works on every Laravel version.
 */
class CreateInternGrades extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('intern_grades')) {
            Schema::create('intern_grades', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('intern_id')->unique();
                $table->text('ratings')->nullable();                    // JSON { item key: 1-5 } (coordinator part)
                $table->decimal('coordinator_score', 5, 2)->nullable(); // out of 40
                $table->decimal('supervisor_score', 5, 2)->nullable();  // out of 100 (copied when released)
                $table->decimal('final_grade', 5, 2)->nullable();       // %
                $table->text('remarks')->nullable();
                $table->unsignedBigInteger('graded_by')->nullable();
                $table->string('graded_by_name', 191)->nullable();
                $table->timestamp('finalized_at')->nullable();          // set = released to the intern
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('intern_grades');
    }
}