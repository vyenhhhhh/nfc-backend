<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Recovery codes live in their own table so they never come back from /me or /admin/users
        if (!Schema::hasTable('account_recovery')) {
            Schema::create('account_recovery', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->unique();
                $table->text('code');            // encrypted
                $table->timestamps();
            });
        }

        if (!Schema::hasColumn('users', 'date_of_birth')) {
            Schema::table('users', function (Blueprint $table) {
                $table->date('date_of_birth')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('account_recovery');
        if (Schema::hasColumn('users', 'date_of_birth')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('date_of_birth');
            });
        }
    }
};