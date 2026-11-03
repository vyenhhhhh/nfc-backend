<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Whole day, morning only (am) or afternoon only (pm). Existing events stay whole-day. */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('school_event_excuses') && !Schema::hasColumn('school_event_excuses', 'portion')) {
            Schema::table('school_event_excuses', function (Blueprint $t) {
                $t->string('portion', 10)->default('whole');   // whole | am | pm
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('school_event_excuses', 'portion')) {
            Schema::table('school_event_excuses', function (Blueprint $t) { $t->dropColumn('portion'); });
        }
    }
};