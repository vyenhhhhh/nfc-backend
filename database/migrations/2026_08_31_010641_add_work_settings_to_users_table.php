<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('work_mode', ['onsite', 'offsite'])->default('onsite')->after('role');
            $table->enum('tracking_type', ['hours', 'output'])->default('hours')->after('work_mode');
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['work_mode', 'tracking_type']);
        });
    }
};