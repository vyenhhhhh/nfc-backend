<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// proof file of an online (off-site / remote) day, like time corrections and excused days
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_submissions', function (Blueprint $t) {
            if (!Schema::hasColumn('online_submissions', 'proof_path')) $t->string('proof_path', 255)->nullable();
            if (!Schema::hasColumn('online_submissions', 'proof_name')) $t->string('proof_name', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('online_submissions', function (Blueprint $t) {
            $t->dropColumn(['proof_path', 'proof_name']);
        });
    }
};