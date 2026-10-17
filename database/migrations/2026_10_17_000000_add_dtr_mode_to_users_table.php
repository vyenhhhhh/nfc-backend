<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// DTR type per intern (depends on the HTI):
//   two  = 2 taps a day: in and out (9–5), lunch break not counted
//   four = 4 taps a day: AM in / AM out, PM in / PM out (9–12, 1–5)
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'dtr_mode')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('dtr_mode', 10)->default('two')->after('tracking_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'dtr_mode')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('dtr_mode');
            });
        }
    }
};