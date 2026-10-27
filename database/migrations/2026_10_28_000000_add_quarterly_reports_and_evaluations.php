<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 *  - quarterly_reports   the coordinator's Unit Performance Review Report (one per year + quarter);
 *                        the numbers come from the attendance, the write-ups are saved here
 *  - evaluations         the HTE supervisor's evaluation of an intern: filled in the system, or the
 *                        signed paper form uploaded (by the supervisor or the coordinator)
 * Safe to run on the shared database: it only adds tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('quarterly_reports')) {
            Schema::create('quarterly_reports', function (Blueprint $table) {
                $table->id();
                $table->unsignedSmallInteger('year');
                $table->unsignedTinyInteger('quarter');          // 1-4
                $table->longText('data')->nullable();             // JSON: write-ups, tables, photos
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['year', 'quarter']);
            });
        }

        if (!Schema::hasTable('evaluations')) {
            Schema::create('evaluations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('intern_id')->index();
                $table->unsignedBigInteger('evaluator_id')->nullable();   // who filled it in or uploaded it
                $table->string('evaluator_name', 191)->nullable();
                $table->string('evaluator_role', 30)->nullable();
                $table->string('mode', 10);                               // system | paper
                $table->text('ratings')->nullable();                      // JSON { criterion key: 1-5 }
                $table->decimal('average', 4, 2)->nullable();
                $table->text('strengths')->nullable();
                $table->text('improvements')->nullable();
                $table->text('comments')->nullable();
                $table->string('recommendation', 40)->nullable();         // hire | recommend | none
                $table->string('file_path')->nullable();                  // the signed paper form (PDF / photo)
                $table->string('original_name')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluations');
        Schema::dropIfExists('quarterly_reports');
    }
};