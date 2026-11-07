<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The category of an HTE (Government Agency, IT Company, Hospital, NGO, Research Center, School, ...),
// kept on the HTE supervisor's account like the HTE's modality and MOA date.
// The Quarterly Report (Table 4) uses it, so it no longer has to be picked every quarter.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'hte_category')) {
            Schema::table('users', fn (Blueprint $table) => $table->string('hte_category', 60)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'hte_category')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('hte_category'));
        }
    }
};
