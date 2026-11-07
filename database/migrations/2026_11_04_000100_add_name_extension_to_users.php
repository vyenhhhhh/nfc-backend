<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Name extension (Jr., Sr., II, III ...) for users who have one
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'name_extension')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('name_extension', 20)->nullable()->after('last_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'name_extension')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('name_extension');
            });
        }
    }
};