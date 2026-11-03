<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When the account last had the portal open (updated by a small ping every minute). */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'last_seen_at')) {
            Schema::table('users', function (Blueprint $t) {
                $t->timestamp('last_seen_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'last_seen_at')) {
            Schema::table('users', function (Blueprint $t) { $t->dropColumn('last_seen_at'); });
        }
    }
};