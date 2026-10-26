<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Reports the OJT coordinator asks for (instead of a fixed "every week"):
// she adds a report with a name and a due date, for all interns, some programs, or specific interns.
// Each intern upload is linked to the report it answers (movs.report_id). Old uploads stay as they are.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('report_schedules')) {
            Schema::create('report_schedules', function (Blueprint $table) {
                $table->id();
                $table->string('title', 150);
                $table->date('due_date')->nullable();
                $table->string('audience', 20)->default('all');   // all | programs | interns
                $table->text('programs')->nullable();              // JSON, e.g. ["BSIT","BSIS"]
                $table->text('intern_ids')->nullable();            // JSON, e.g. [4,9,12]
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('movs', function (Blueprint $table) {
            if (!Schema::hasColumn('movs', 'report_id')) {
                $table->unsignedBigInteger('report_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('movs', function (Blueprint $table) {
            if (Schema::hasColumn('movs', 'report_id')) $table->dropColumn('report_id');
        });
        Schema::dropIfExists('report_schedules');
    }
};