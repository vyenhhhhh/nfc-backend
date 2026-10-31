<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How each intern's internship is done (the CCIS internship modalities) and where it is:
 *   modality  onsite | hybrid | virtual
 *   scope     local | inbound (foreign student interning at CSU) | outbound (CSU student with a foreign HTE)
 * Set by the OJT coordinator. Safe to run on the shared database: it only adds two columns.
 */
class AddModalityScopeToUsers extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'modality')) $table->string('modality', 12)->default('onsite');
            if (!Schema::hasColumn('users', 'scope'))    $table->string('scope', 16)->default('local');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'modality')) $table->dropColumn('modality');
            if (Schema::hasColumn('users', 'scope'))    $table->dropColumn('scope');
        });
    }
}