<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A photo or PDF the intern attaches as proof (medical certificate, event invitation...). */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('school_event_excuses') && !Schema::hasColumn('school_event_excuses', 'proof_path')) {
            Schema::table('school_event_excuses', function (Blueprint $t) {
                $t->string('proof_path')->nullable();
                $t->string('proof_name')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('school_event_excuses', 'proof_path')) {
            Schema::table('school_event_excuses', function (Blueprint $t) { $t->dropColumn(['proof_path', 'proof_name']); });
        }
    }
};