<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Staff details: position/title, signature image, and (supervisors) the HTE address and MOA validity date. */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('users')) return;
        Schema::table('users', function (Blueprint $t) {
            if (!Schema::hasColumn('users', 'position'))        $t->string('position', 150)->nullable();
            if (!Schema::hasColumn('users', 'signature'))       $t->string('signature')->nullable();
            if (!Schema::hasColumn('users', 'hte_address'))     $t->string('hte_address', 255)->nullable();
            if (!Schema::hasColumn('users', 'moa_valid_until')) $t->date('moa_valid_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            foreach (['position', 'signature', 'hte_address', 'moa_valid_until'] as $c) {
                if (Schema::hasColumn('users', $c)) $t->dropColumn($c);
            }
        });
    }
};