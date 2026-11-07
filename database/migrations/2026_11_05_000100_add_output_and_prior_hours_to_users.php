<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * output_required  supervisor: does this HTE require a system output presentation? (copied to its interns,
 *                  the coordinator can change it per intern)  · intern: is the presentation required for them?
 * prior_hours      intern: hours already rendered in an earlier term (e.g. a summer intern who continues
 *                  the next semester). They are taken off the program's required hours.
 * Safe to run on the shared database: it only adds two columns.
 */
class AddOutputAndPriorHoursToUsers extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'output_required')) $table->boolean('output_required')->default(true);
            if (!Schema::hasColumn('users', 'prior_hours'))     $table->decimal('prior_hours', 7, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'output_required')) $table->dropColumn('output_required');
            if (Schema::hasColumn('users', 'prior_hours'))     $table->dropColumn('prior_hours');
        });
    }
}