<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Time corrections can now fix a forgotten time IN too (before: only a forgotten time out).
//   kind = "out" -> forgot to tap out: requested_out is the time the intern left
//   kind = "in"  -> forgot to tap in:  requested_in is when the intern arrived
//                   (if the day had only one tap, that tap becomes the time out)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_corrections', function (Blueprint $table) {
            if (!Schema::hasColumn('time_corrections', 'kind'))         $table->string('kind', 10)->default('out');
            if (!Schema::hasColumn('time_corrections', 'requested_in')) $table->timestamp('requested_in')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('time_corrections', function (Blueprint $table) {
            if (Schema::hasColumn('time_corrections', 'requested_in')) $table->dropColumn('requested_in');
            if (Schema::hasColumn('time_corrections', 'kind'))         $table->dropColumn('kind');
        });
    }
};