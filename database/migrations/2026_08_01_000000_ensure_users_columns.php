<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'role')) {
                $table->string('role', 30)->default('intern');
            }
            if (!Schema::hasColumn('users', 'work_mode')) {
                $table->string('work_mode', 20)->default('onsite');
            }
            if (!Schema::hasColumn('users', 'tracking_type')) {
                $table->string('tracking_type', 20)->default('hours');
            }
        });
    }

    public function down(): void {}
};