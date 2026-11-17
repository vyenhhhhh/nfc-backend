<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Kept on the HTE supervisor's account: does this HTE make its project-based interns follow 8:00–5:00?
// false (default) = a flexible schedule: their time-ins are never late and their DTR shows no undertime or tardiness.
// true = the regular schedule: late after 8:00 AM, undertime before 5:00 PM, like other interns.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (!Schema::hasColumn('users', 'project_fixed_schedule')) $t->boolean('project_fixed_schedule')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (Schema::hasColumn('users', 'project_fixed_schedule')) $t->dropColumn('project_fixed_schedule');
        });
    }
};