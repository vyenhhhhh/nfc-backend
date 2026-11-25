<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A time correction (forgot to time in / out, missed a whole day) now comes with a proof file, like an excused absence. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_corrections', function (Blueprint $table) {
            if (!Schema::hasColumn('time_corrections', 'proof_path')) $table->string('proof_path')->nullable();
            if (!Schema::hasColumn('time_corrections', 'proof_name')) $table->string('proof_name', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('time_corrections', function (Blueprint $table) {
            if (Schema::hasColumn('time_corrections', 'proof_path')) $table->dropColumn('proof_path');
            if (Schema::hasColumn('time_corrections', 'proof_name')) $table->dropColumn('proof_name');
        });
    }
};