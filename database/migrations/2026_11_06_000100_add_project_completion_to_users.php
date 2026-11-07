<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project-based interns finish when their project is done, not when their hours are done.
 *   project_marked_at / _by     the HTE supervisor marked all the project tasks as complete
 *   project_confirmed_at / _by  the OJT coordinator confirmed it (this completes the OJT)
 * Safe to run on the shared database: it only adds four columns.
 */
class AddProjectCompletionToUsers extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'project_marked_at'))    $table->timestamp('project_marked_at')->nullable();
            if (!Schema::hasColumn('users', 'project_marked_by'))    $table->unsignedBigInteger('project_marked_by')->nullable();
            if (!Schema::hasColumn('users', 'project_confirmed_at')) $table->timestamp('project_confirmed_at')->nullable();
            if (!Schema::hasColumn('users', 'project_confirmed_by')) $table->unsignedBigInteger('project_confirmed_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['project_marked_at', 'project_marked_by', 'project_confirmed_at', 'project_confirmed_by'] as $c) {
                if (Schema::hasColumn('users', $c)) $table->dropColumn($c);
            }
        });
    }
}
